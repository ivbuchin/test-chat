<?php
declare(strict_types=1);

// Логика чата поверх Redis.
//   stream   — Redis Stream: сообщения и системные события join/leave;
//   conns    — ZSET соединений (score = время истечения, unix-секунды);
//   meta     — HASH connId => JSON {name, k, sid, ip};
//   ver      — счётчик, растёт при каждом изменении списка онлайн.
// Вход и выход выполняются Lua-скриптами, чтобы проверка имени и запись были атомарными.
final class Chat
{
    public const NAME_MAX = 30;
    public const TEXT_MAX = 1000;
    public const HISTORY_SEC = 1800;
    public const HISTORY_LIMIT = 500;
    public const MISSED_LIMIT = 1000;
    public const STREAM_MAXLEN = 10000;
    public const CONN_TTL = 8;        // соединение считается живым, если его продлевали не позже TTL секунд назад
    public const LEAVE_GRACE = 3;     // пауза между закрытием вкладки и «покинул чат» (чтобы F5 не шумел)
    public const MAX_CONN_SESSION = 3;
    public const MAX_CONN_IP = 30;
    private const REAP_BATCH = 100;

    public const JOIN_OK = 1;
    public const JOIN_NAME_TAKEN = 0;
    public const JOIN_LIMIT_SESSION = -1;
    public const JOIN_LIMIT_IP = -2;

    private const LUA_JOIN = <<<'LUA'
local live = redis.call('ZRANGEBYSCORE', KEYS[1], ARGV[6], '+inf')
local same, cs, ci = false, 0, 0
for _, id in ipairs(live) do
  local m = redis.call('HGET', KEYS[2], id)
  if m then
    local d = cjson.decode(m)
    if d.k == ARGV[3] then
      if d.sid ~= ARGV[4] then return 0 end
      same = true
    end
    if d.sid == ARGV[4] then cs = cs + 1 end
    if d.ip == ARGV[5] then ci = ci + 1 end
  end
end
if cs >= tonumber(ARGV[10]) then return -1 end
if ci >= tonumber(ARGV[11]) then return -2 end
redis.call('HSET', KEYS[2], ARGV[1], ARGV[2])
redis.call('ZADD', KEYS[1], tonumber(ARGV[6]) + tonumber(ARGV[7]), ARGV[1])
if not same then
  redis.call('XADD', KEYS[3], 'MAXLEN', '~', ARGV[12], '*', 'type', 'join', 'name', ARGV[8], 'text', '', 'ts', ARGV[9])
end
redis.call('INCR', KEYS[4])
return 1
LUA;

    private const LUA_LEAVE = <<<'LUA'
if redis.call('ZREM', KEYS[1], ARGV[1]) == 0 then return 0 end
local m = redis.call('HGET', KEYS[2], ARGV[1])
redis.call('HDEL', KEYS[2], ARGV[1])
redis.call('INCR', KEYS[4])
if not m then return 1 end
local d = cjson.decode(m)
for _, id in ipairs(redis.call('ZRANGE', KEYS[1], 0, -1)) do
  local o = redis.call('HGET', KEYS[2], id)
  if o and cjson.decode(o).k == d.k then return 1 end
end
redis.call('XADD', KEYS[3], 'MAXLEN', '~', ARGV[3], '*', 'type', 'leave', 'name', d.name, 'text', '', 'ts', ARGV[2])
return 1
LUA;

    private readonly string $p;

    public function __construct(private readonly Redis $r)
    {
        $this->p = getenv('CHAT_PREFIX') ?: 'chat:';
    }

    public static function connect(): Redis
    {
        $r = new Redis();
        $r->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int)(getenv('REDIS_PORT') ?: 6379), 2.0);
        if (($pw = getenv('REDIS_PASSWORD')) !== false && $pw !== '') {
            $r->auth($pw);
        }
        $r->setOption(Redis::OPT_READ_TIMEOUT, 5);
        return $r;
    }

    private function k(string $name): string
    {
        return $this->p . $name;
    }

    // ---------- валидация ----------

    /** Нормализует и проверяет имя. Возвращает [имя, null] или [null, причина]. */
    public static function checkName(mixed $name): array
    {
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
            return [null, 'Имя должно быть текстом'];
        }
        $name = \Normalizer::normalize($name, \Normalizer::FORM_KC);
        $name = trim(preg_replace('/\s+/u', ' ', (string)$name) ?? '');
        $len = mb_strlen($name);
        if ($len < 1 || $len > self::NAME_MAX) {
            return [null, 'Имя: от 1 до ' . self::NAME_MAX . ' символов'];
        }
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\p{M} ._-]*$/u', $name)
            || preg_match('/[\x{115F}\x{1160}\x{3164}\x{FFA0}]|\p{M}{3,}/u', $name)) {
            return [null, 'Имя: только буквы, цифры, пробел, точка, дефис и подчёркивание'];
        }
        if (preg_match('/\p{Latin}/u', $name) && preg_match('/\p{Cyrillic}/u', $name)) {
            return [null, 'Имя: не смешивайте латиницу и кириллицу'];
        }
        return [$name, null];
    }

    /** Ключ сравнения имён: без учёта регистра. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower($name);
    }

    /** Проверяет текст сообщения. Возвращает [текст, null] или [null, причина]. */
    public static function checkText(mixed $text): array
    {
        if (!is_string($text) || !mb_check_encoding($text, 'UTF-8')) {
            return [null, 'Сообщение должно быть текстом'];
        }
        $text = trim($text);
        $len = mb_strlen($text);
        if ($len < 1) {
            return [null, 'Сообщение пустое'];
        }
        if ($len > self::TEXT_MAX) {
            return [null, 'Сообщение длиннее ' . self::TEXT_MAX . ' символов'];
        }
        // Запрещены управляющие (кроме \n и \t), направляющие bidi-символы и неназначенные коды.
        // ZWJ и селекторы вариантов (эмодзи) разрешены.
        if (preg_match('/[^\P{Cc}\n\t]|[\x{202A}-\x{202E}\x{2066}-\x{2069}]|\p{Cn}/u', $text)) {
            return [null, 'В сообщении есть недопустимые символы'];
        }
        return [$text, null];
    }

    // ---------- присутствие ----------

    /** @return array<string, array{name:string,k:string,sid:string,ip:string}> connId => meta, только живые */
    private function live(): array
    {
        $ids = $this->r->zRangeByScore($this->k('conns'), (string)time(), '+inf');
        if (!$ids) {
            return [];
        }
        $metas = $this->r->hMGet($this->k('meta'), $ids);
        $out = [];
        foreach ($ids as $id) {
            $d = isset($metas[$id]) && is_string($metas[$id]) ? json_decode($metas[$id], true) : null;
            if (is_array($d)) {
                $out[$id] = $d;
            }
        }
        return $out;
    }

    /** Имя занято другой сессией (или похоже на чужое имя)? */
    public function nameTaken(string $name, string $sid): bool
    {
        $key = self::nameKey($name);
        $spoof = new \Spoofchecker();
        foreach ($this->live() as $m) {
            if ($m['sid'] === $sid) {
                continue;
            }
            if ($m['k'] === $key || $spoof->areConfusable($m['name'], $name)) {
                return true;
            }
        }
        return false;
    }

    public function hasConn(string $name, string $sid): bool
    {
        $key = self::nameKey($name);
        foreach ($this->live() as $m) {
            if ($m['k'] === $key && $m['sid'] === $sid) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:int,1:?string} [код JOIN_*, connId] */
    public function join(string $name, string $sid, string $ip): array
    {
        $id = bin2hex(random_bytes(8));
        $now = time();
        $meta = json_encode(['name' => $name, 'k' => self::nameKey($name), 'sid' => $sid, 'ip' => $ip], JSON_UNESCAPED_UNICODE);
        $res = (int)$this->r->eval(self::LUA_JOIN, [
            $this->k('conns'), $this->k('meta'), $this->k('stream'), $this->k('ver'),
            $id, $meta, self::nameKey($name), $sid, $ip, (string)$now, (string)self::CONN_TTL,
            $name, (string)($now * 1000), (string)self::MAX_CONN_SESSION, (string)self::MAX_CONN_IP, (string)self::STREAM_MAXLEN,
        ], 4);
        return [$res, $res === self::JOIN_OK ? $id : null];
    }

    /** Соединение ещё зарегистрировано? Заодно продлевает его. */
    public function touch(string $id): bool
    {
        $key = $this->k('conns');
        if ($this->r->zScore($key, $id) === false) {
            return false;
        }
        $this->r->zAdd($key, ['XX'], time() + self::CONN_TTL, $id);
        return true;
    }

    /** Вкладка закрыта: через LEAVE_GRACE секунд reap() выпишет «покинул чат», если соединение не вернётся. */
    public function release(string $id): void
    {
        $this->r->zAdd($this->k('conns'), ['XX'], time() + self::LEAVE_GRACE, $id);
    }

    /** Владеет ли сессия этим соединением? */
    public function owns(string $id, string $sid): bool
    {
        $m = $this->r->hGet($this->k('meta'), $id);
        $d = is_string($m) ? json_decode($m, true) : null;
        return is_array($d) && $d['sid'] === $sid;
    }

    private function leave(string $id, int $tsMs): void
    {
        $this->r->eval(self::LUA_LEAVE, [
            $this->k('conns'), $this->k('meta'), $this->k('stream'), $this->k('ver'),
            $id, (string)$tsMs, (string)self::STREAM_MAXLEN,
        ], 4);
    }

    /** Удаляет истёкшие соединения. Выполняется не чаще раза в секунду на весь кластер. */
    public function reap(): void
    {
        if (!$this->r->set($this->k('reap-lock'), '1', ['nx', 'px' => 900])) {
            return;
        }
        $now = time();
        $expired = $this->r->zRangeByScore($this->k('conns'), '-inf', '(' . $now, ['withscores' => true, 'limit' => [0, self::REAP_BATCH]]);
        foreach ($expired as $id => $score) {
            $this->leave((string)$id, ((int)$score - self::LEAVE_GRACE) * 1000);
        }
    }

    public function version(): int
    {
        return (int)$this->r->get($this->k('ver'));
    }

    /** @return string[] */
    public function users(): array
    {
        $names = [];
        foreach ($this->live() as $m) {
            $names[$m['k']] = $m['name'];
        }
        $names = array_values($names);
        (new \Collator('ru_RU'))->sort($names);
        return $names;
    }

    // ---------- сообщения ----------

    public function post(string $type, string $name, string $text = ''): void
    {
        $this->r->xAdd($this->k('stream'), '*', [
            'type' => $type,
            'name' => $name,
            'text' => $text,
            'ts' => (string)(int)(microtime(true) * 1000),
        ], self::STREAM_MAXLEN, true);
    }

    /** @return int 0 — можно, иначе сколько секунд подождать */
    public function hit(string $bucket, int $limit, int $window): int
    {
        $k = $this->k('rl:' . $bucket);
        $this->r->set($k, '0', ['nx', 'ex' => $window]);
        if ($this->r->incr($k) <= $limit) {
            return 0;
        }
        return max(1, (int)$this->r->ttl($k));
    }

    public static function historyFrom(): string
    {
        return ((int)(microtime(true) * 1000) - self::HISTORY_SEC * 1000) . '-0';
    }

    /** @return array<int, array> последние события за окно истории (по возрастанию) */
    public function history(string $from): array
    {
        $rows = $this->r->xRevRange($this->k('stream'), '+', $from, self::HISTORY_LIMIT) ?: [];
        return $this->format(array_reverse($rows, true));
    }

    /** События строго после $lastId, без ожидания. */
    public function missed(string $lastId): array
    {
        return $this->format($this->r->xRange($this->k('stream'), '(' . $lastId, '+', self::MISSED_LIMIT) ?: []);
    }

    /** Новые события после $lastId; ждёт до $blockMs (> 0). */
    public function wait(string $lastId, int $blockMs): array
    {
        $res = $this->r->xRead([$this->k('stream') => $lastId], 100, $blockMs);
        return $res ? $this->format($res[$this->k('stream')]) : [];
    }

    private function format(array $entries): array
    {
        $out = [];
        foreach ($entries as $id => $f) {
            $out[] = [
                'id' => (string)$id,
                'type' => $f['type'] ?? 'message',
                'name' => $f['name'] ?? '',
                'text' => $f['text'] ?? '',
                'ts' => (int)($f['ts'] ?? 0),
            ];
        }
        return $out;
    }
}
