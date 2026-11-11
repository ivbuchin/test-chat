<?php
declare(strict_types=1);

require __DIR__ . '/../src/Chat.php';

const BODY_MAX = 16384;
const STREAM_MAX_SEC = 300;   // после этого клиент переподключается сам (EventSource + Last-Event-ID)
const SSE_WAIT_MS = 1000;

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(int $code, string $msg, array $headers = []): never
{
    http_response_code($code);
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > BODY_MAX) {
        fail(413, 'Слишком большой запрос');
    }
    $d = json_decode(file_get_contents('php://input', false, null, 0, BODY_MAX) ?: '', true);
    return is_array($d) ? $d : [];
}

function throttle(Chat $chat, string $bucket, int $limit, int $window, string $msg): void
{
    if (($wait = $chat->hit($bucket, $limit, $window)) > 0) {
        fail(429, "$msg Повторите через $wait с.", ["Retry-After: $wait"]);
    }
}

function startSession(): void
{
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => getenv('COOKIE_SECURE') === '1' || !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function stream(Chat $chat, string $name, string $sid, string $ip): void
{
    ignore_user_abort(true);
    set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    [$code, $conn] = $chat->join($name, $sid, $ip);
    if ($conn === null) {
        match ($code) {
            Chat::JOIN_NAME_TAKEN => fail(409, 'Это имя уже занято'),
            default => fail(429, 'Слишком много открытых вкладок'),
        };
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('X-Accel-Buffering: no');

    $send = static function (string $event, mixed $data, ?string $id = null): void {
        echo ($id !== null ? "id: $id\n" : '') . "event: $event\ndata: "
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
    };

    try {
        echo "retry: 2000\n\n";
        $send('ready', ['conn' => $conn]);
        $lastEvent = $_SERVER['HTTP_LAST_EVENT_ID'] ?? '';
        if (preg_match('/^\d+-\d+$/', $lastEvent)) {
            $last = $lastEvent; // переподключение: досылаем только пропущенное
            $missed = $chat->missed($last);
            if ($missed) {
                $last = end($missed)['id'];
            }
            $send('history', $missed, $missed ? $last : null);
        } else {
            $from = Chat::historyFrom();
            $hist = $chat->history($from);
            $last = $hist ? end($hist)['id'] : $from;
            $send('history', $hist, $hist ? $last : null);
        }
        $version = -1;
        $deadline = time() + STREAM_MAX_SEC;
        while (!connection_aborted() && time() < $deadline) {
            if (!$chat->touch($conn)) {
                break; // соединение закрыто клиентом (sendBeacon) или истекло
            }
            $chat->reap();
            if (($v = $chat->version()) !== $version) {
                $version = $v;
                $send('users', $chat->users());
            }
            $new = $chat->wait($last, SSE_WAIT_MS);
            foreach ($new as $m) {
                $last = $m['id'];
                $send('message', $m, $last);
            }
            if (!$new) {
                echo ": ping\n\n"; // запись нужна, чтобы PHP заметил обрыв
                flush();
            }
        }
    } catch (RedisException $e) {
        error_log('stream: ' . $e->getMessage()); // поток закрывается, клиент переподключится
    } finally {
        try {
            if (connection_aborted()) {
                $chat->release($conn);
            }
        } catch (RedisException) {
        }
    }
}

// ---------- запрос ----------

$action = $_GET['action'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

try {
    $chat = new Chat(Chat::connect());

    if ($action === 'health') {
        echo '{"ok":true}';
        exit;
    }

    startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    $sid = hash('sha256', session_id());
    $csrf = $_SESSION['csrf'];
    $name = $_SESSION['name'] ?? null;
    session_write_close(); // не блокируем параллельные запросы сессии (важно для SSE)

    $in = [];
    if ($isPost) {
        if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            fail(415, 'Ожидается JSON');
        }
        $in = body();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf'] ?? ''); // sendBeacon не умеет заголовки
        if (!is_string($token) || !hash_equals($csrf, $token)) {
            fail(403, 'Сессия устарела, обновите страницу');
        }
    }

    switch ($isPost ? "POST $action" : "GET $action") {
        case 'GET state':
            $chat->reap(); // выписывает «покинул чат» тем, кто ушёл, даже если стримов больше нет
            echo json_encode(['csrf' => $csrf, 'name' => $name], JSON_UNESCAPED_UNICODE);
            break;

        case 'POST login':
            throttle($chat, "login:$ip", 20, 60, 'Слишком много попыток входа.');
            [$n, $why] = Chat::checkName($in['name'] ?? null);
            if ($n === null) {
                fail(422, $why);
            }
            if ($chat->nameTaken($n, $sid)) {
                fail(409, 'Это имя уже занято');
            }
            startSession();
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            $_SESSION['name'] = $n;
            $csrf = $_SESSION['csrf'];
            session_write_close();
            echo json_encode(['name' => $n, 'csrf' => $csrf], JSON_UNESCAPED_UNICODE);
            break;

        case 'POST logout':
            if (is_string($in['conn'] ?? null) && $chat->owns($in['conn'], $sid)) {
                $chat->release($in['conn']);
            }
            startSession();
            unset($_SESSION['name']);
            session_write_close();
            echo '{"ok":true}';
            break;

        case 'POST send':
            if ($name === null || !$chat->hasConn($name, $sid)) {
                fail(403, 'Вы не в чате');
            }
            throttle($chat, "send:$sid", 10, 10, 'Слишком часто.');
            throttle($chat, "send-ip:$ip", 60, 10, 'Слишком часто.');
            [$t, $why] = Chat::checkText($in['text'] ?? null);
            if ($t === null) {
                fail(422, $why);
            }
            $chat->post('message', $name, $t);
            echo '{"ok":true}';
            break;

        case 'POST leave': // sendBeacon из pagehide
            if (is_string($in['conn'] ?? null) && $chat->owns($in['conn'], $sid)) {
                $chat->release($in['conn']);
            }
            http_response_code(204);
            break;

        case 'GET stream':
            if ($name === null) {
                fail(401, 'Нет имени');
            }
            throttle($chat, "stream:$sid", 30, 60, 'Слишком частые подключения.');
            stream($chat, $name, $sid, $ip);
            break;

        default:
            fail(404, 'Не найдено');
    }
} catch (RedisException $e) {
    error_log('redis: ' . $e->getMessage());
    if (!headers_sent()) {
        fail(503, 'Сервер временно недоступен');
    }
}
