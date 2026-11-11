<?php
declare(strict_types=1);

// Запуск: docker compose exec web php /var/www/tests/run.php
// Использует отдельный префикс ключей Redis и чистит за собой.
putenv('CHAT_PREFIX=test' . bin2hex(random_bytes(3)) . ':');
require __DIR__ . '/../src/Chat.php';

$fails = 0;
function check(string $title, bool $ok, string $extra = ''): void
{
    global $fails;
    echo ($ok ? "ok   " : "FAIL ") . $title . ($ok ? '' : " $extra") . "\n";
    $fails += $ok ? 0 : 1;
}

// --- имена ---
check('имя: обычное', Chat::checkName('Юзер 1')[0] === 'Юзер 1');
check('имя: пробелы схлопываются', Chat::checkName("  Юзер   1 ")[0] === 'Юзер 1');
check('имя: Hangul filler отклонён', Chat::checkName("\u{3164}\u{3164}")[0] === null);
check('имя: braille blank отклонён', Chat::checkName("\u{2800}")[0] === null);
check('имя: латиница+кириллица отклонены', Chat::checkName("Юзeр 1")[0] === null);
check('имя: zalgo отклонён', Chat::checkName('a' . str_repeat("\u{0301}", 10))[0] === null);
check('имя: HTML отклонён', Chat::checkName('<img src=x>')[0] === null);
check('имя: 31 символ отклонён', Chat::checkName(str_repeat('а', 31))[0] === null);
check('имя: NFC и NFD совпадают', Chat::nameKey(Chat::checkName("и\u{0306}")[0]) === Chat::nameKey(Chat::checkName("й")[0]));

// --- текст ---
check('текст: эмодзи', Chat::checkText('Привет! 😁')[0] === 'Привет! 😁');
check('текст: ZWJ-семья', Chat::checkText("👨\u{200D}👩\u{200D}👧")[0] !== null);
check('текст: флаг-радуга', Chat::checkText("🏳\u{FE0F}\u{200D}🌈")[0] !== null);
check('текст: 1000 эмодзи', Chat::checkText(str_repeat('😁', 1000))[0] !== null);
check('текст: 1001 эмодзи отклонён', Chat::checkText(str_repeat('😁', 1001))[0] === null);
check('текст: bidi override отклонён', Chat::checkText("a\u{202E}b")[0] === null);
check('текст: перевод строки сохранён', Chat::checkText("a\nb")[0] === "a\nb");
check('текст: NUL отклонён', Chat::checkText("a\0b")[0] === null);

// --- Redis ---
$chat = new Chat(Chat::connect());
[$c1, $a] = $chat->join('Юзер 1', 'sid-a', '1.1.1.1');
check('join: первая сессия', $c1 === Chat::JOIN_OK && $a !== null);
[$c2] = $chat->join('юзер 1', 'sid-b', '2.2.2.2');
check('join: имя другой сессии без учёта регистра занято', $c2 === Chat::JOIN_NAME_TAKEN);
[$c3, $a2] = $chat->join('Юзер 1', 'sid-a', '1.1.1.1');
check('join: вторая вкладка той же сессии', $c3 === Chat::JOIN_OK);
[$c4, $b] = $chat->join('Юзер 2', 'sid-b', '2.2.2.2');
check('join: другое имя', $c4 === Chat::JOIN_OK);
check('users: список', $chat->users() === ['Юзер 1', 'Юзер 2'], json_encode($chat->users(), JSON_UNESCAPED_UNICODE));

$hist = $chat->history(Chat::historyFrom());
$joins = array_values(array_filter($hist, fn($m) => $m['type'] === 'join'));
check('join: одно событие на имя (две вкладки)', count($joins) === 2, (string)count($joins));

$last = end($hist)['id'];
$t = microtime(true);
$none = $chat->missed($last);
check('реконнект: пропущенного нет — ответ мгновенный', $none === [] && microtime(true) - $t < 0.5);
$chat->post('message', 'Юзер 1', 'привет');
check('реконнект: пропущенное досылается', count($chat->missed($last)) === 1);

for ($i = 0; $i <= Chat::MAX_CONN_SESSION; $i++) {
    [$code] = $chat->join('Юзер 3', 'sid-c', '3.3.3.3');
}
check('лимит соединений на сессию', $code === Chat::JOIN_LIMIT_SESSION, (string)$code);

$v = $chat->version();
$chat->release($a);
$chat->release($a2);
$chat->release($b);
sleep(Chat::LEAVE_GRACE + 2);
$chat->reap();
$leaves = array_values(array_filter($chat->history(Chat::historyFrom()), fn($m) => $m['type'] === 'leave'));
$names = array_column($leaves, 'name');
sort($names);
check('leave: по одному событию на имя', $names === ['Юзер 1', 'Юзер 2'], json_encode($names, JSON_UNESCAPED_UNICODE));
check('leave: версия присутствия выросла', $chat->version() > $v);
check('rate limit', ($chat->hit('t', 2, 5) === 0) && ($chat->hit('t', 2, 5) === 0) && ($chat->hit('t', 2, 5) > 0));

$r = Chat::connect();
foreach ($r->keys(getenv('CHAT_PREFIX') . '*') as $k) {
    $r->del($k);
}
echo $fails === 0 ? "\nВсе проверки пройдены\n" : "\nПровалено: $fails\n";
exit($fails === 0 ? 0 : 1);
