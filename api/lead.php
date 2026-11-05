<?php
// Приём заявки с формы записи: проверка, письмо на почту, сообщение в MAX.
// Настройки и секреты в api/config.php (в git не идёт), образец в config.example.php.
// Текст заявки на сервере не сохраняется и в журнал не пишется.
// Любой отказ возвращает ok:false, и форма ведёт родителя ручным путём через MAX: заявка не теряется.
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Значения должны совпадать с value радиокнопок в index.html (это проверяет тест)
const WANTS = ['Бесплатное посещение', 'Первая смена', 'Вторая смена', 'Разовое посещение', 'Лагерь на каникулах'];
const PLACES = ['ул. Станкостроительная, 1', 'ул. Советская, 40', 'Подскажите, какой ближе'];
const MAX_BODY = 8192;
const MIN_FILL_MS = 1500;
const RATE_TTL = 3700; // файлы счётчиков старше этого удаляем

function respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// Длина в символах без зависимости от mbstring
function ulen(string $s): int
{
    return (int) preg_match_all('/./su', $s);
}

// Одна строка: управляющие символы и переводы строк превращаем в пробел
function one_line($v): string
{
    if (!is_string($v)) {
        return '';
    }
    return trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $v));
}

// Комментарий: переводы строк оставляем, остальное управляющее убираем
function multi_line($v): string
{
    if (!is_string($v)) {
        return '';
    }
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    return trim((string) preg_replace('/[^\P{Cc}\n]+/u', '', $v));
}

function phone_digits($v): string
{
    $d = (string) preg_replace('/\D/', '', is_string($v) ? $v : '');
    if (strlen($d) === 11 && ($d[0] === '8' || $d[0] === '7')) {
        $d = substr($d, 1);
    }
    return $d;
}

function format_phone(string $d): string
{
    return '+7 ' . substr($d, 0, 3) . ' ' . substr($d, 3, 3) . '-' . substr($d, 6, 2) . '-' . substr($d, 8, 2);
}

// Счётчик обращений в файле. Нет доступа к папке: пропускаем, принять заявку важнее
function rate_allow(string $dir, string $key, int $limit, int $window): bool
{
    $fh = @fopen("$dir/rate-$key.json", 'c+');
    if ($fh === false) {
        return true;
    }
    flock($fh, LOCK_EX);
    $now = time();
    $hits = json_decode((string) stream_get_contents($fh), true);
    $hits = array_values(array_filter(is_array($hits) ? $hits : [], function ($t) use ($now, $window) {
        return is_int($t) && $t > $now - $window;
    }));
    $allow = count($hits) < $limit;
    if ($allow) {
        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($hits));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $allow;
}

function rate_cleanup(string $dir): void
{
    foreach (glob("$dir/rate-*.json") ?: [] as $path) {
        if (@filemtime($path) < time() - RATE_TTL) {
            @unlink($path);
        }
    }
}

// В журнал только канал и причина сбоя, без данных заявки
function log_fail(string $dir, string $channel, string $reason): void
{
    @file_put_contents("$dir/errors.log", date('c') . " $channel $reason\n", FILE_APPEND | LOCK_EX);
}

// Заголовок письма кусками: одно закодированное слово не длиннее 75 байт
function encode_header(string $s): string
{
    $parts = [];
    foreach (array_chunk(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [], 20) as $chunk) {
        $parts[] = '=?UTF-8?B?' . base64_encode(implode('', $chunk)) . '?=';
    }
    return implode("\r\n ", $parts);
}

function send_mail(array $m, string $subject, string $text): void
{
    $from = (string) ($m['from'] ?? '');
    $to = (string) $m['to'];
    $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
    $headers = [
        'From: ' . encode_header((string) ($m['from_name'] ?? 'Сайт')) . " <$from>",
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $subj = encode_header($subject);
    $body = chunk_split(base64_encode($text), 76, "\r\n");

    $smtp = $m['smtp'] ?? [];
    if (empty($smtp['host'])) {
        if (!mail($to, $subj, $body, implode("\r\n", $headers), '-f' . $from)) {
            throw new RuntimeException('mail() вернул false');
        }
        return;
    }
    $data = implode("\r\n", array_merge(["To: <$to>", "Subject: $subj"], $headers)) . "\r\n\r\n" . $body;
    smtp_send($smtp, $from, $to, $data, $domain);
}

// Письмо через почтовый ящик на хостинге. Ошибка называет шаг, на котором сервер отказал
function smtp_send(array $s, string $from, string $to, string $data, string $helo): void
{
    $secure = (string) ($s['secure'] ?? 'ssl');
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $s['host'] . ':' . (int) ($s['port'] ?? 465);
    $fp = @stream_socket_client($remote, $errno, $errstr, 8);
    if ($fp === false) {
        throw new RuntimeException("smtp connect $errno");
    }
    stream_set_timeout($fp, 8);

    $read = function () use ($fp): int {
        $code = 0;
        while (($line = fgets($fp, 1024)) !== false) {
            $code = (int) substr($line, 0, 3);
            if (($line[3] ?? ' ') !== '-') {
                break;
            }
        }
        return $code;
    };
    $step = function (string $name, string $cmd, array $ok) use ($fp, $read): void {
        fwrite($fp, $cmd . "\r\n");
        $code = $read();
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException("smtp $name $code");
        }
    };

    try {
        $code = $read();
        if ($code !== 220) {
            throw new RuntimeException("smtp greeting $code");
        }
        $step('ehlo', "EHLO $helo", [250]);
        if ($secure === 'tls') {
            $step('starttls', 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('smtp tls');
            }
            $step('ehlo', "EHLO $helo", [250]);
        }
        if (!empty($s['user'])) {
            $step('auth', 'AUTH LOGIN', [334]);
            $step('user', base64_encode((string) $s['user']), [334]);
            $step('pass', base64_encode((string) ($s['pass'] ?? '')), [235]);
        }
        $step('mail', "MAIL FROM:<$from>", [250]);
        $step('rcpt', "RCPT TO:<$to>", [250, 251]);
        $step('data', 'DATA', [354]);
        $step('body', $data . '.', [250]);
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

function send_max(array $m, string $text): void
{
    $target = !empty($m['chat_id']) ? ['chat_id' => $m['chat_id']] : ['user_id' => $m['user_id']];
    $url = rtrim((string) ($m['api'] ?? 'https://platform-api2.max.ru'), '/') . '/messages?' . http_build_query($target);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $m['token'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    // У platform-api2.max.ru сертификат Минцифры, в обычных наборах его корня нет
    if (!empty($m['ca_file'])) {
        curl_setopt($ch, CURLOPT_CAINFO, (string) $m['ca_file']);
    }
    curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0) {
        throw new RuntimeException("curl $errno");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("http $status");
    }
}

// ---------- Обработка запроса ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'method']);
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $host = (string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (strcasecmp((string) parse_url($origin, PHP_URL_HOST), $host) !== 0) {
        respond(403, ['ok' => false, 'error' => 'origin']);
    }
}
// Форма с чужого сайта без предзапроса такой заголовок поставить не может
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    respond(415, ['ok' => false, 'error' => 'type']);
}

$raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
if (strlen($raw) > MAX_BODY) {
    respond(413, ['ok' => false, 'error' => 'size']);
}
$d = json_decode($raw, true);
if (!is_array($d)) {
    respond(400, ['ok' => false, 'error' => 'json']);
}

// Ловушки для ботов. Честный отказ, а не притворный успех: если сработает на человеке,
// он уйдёт на ручной путь, а заявка не пропадёт молча
if (one_line($d['hp_field'] ?? '') !== '' || (int) ($d['elapsed'] ?? 0) < MIN_FILL_MS) {
    respond(400, ['ok' => false, 'error' => 'rejected']);
}

$want = one_line($d['want'] ?? '');
$place = one_line($d['place'] ?? '');
$name = one_line($d['name'] ?? '');
$digits = phone_digits($d['phone'] ?? '');
$child = one_line($d['child'] ?? '');
$note = multi_line($d['note'] ?? '');

$bad = [];
if (!in_array($want, WANTS, true)) {
    $bad[] = 'want';
}
if (!in_array($place, PLACES, true)) {
    $bad[] = 'place';
}
if ($name === '' || ulen($name) > 100) {
    $bad[] = 'name';
}
if (strlen($digits) !== 10) {
    $bad[] = 'phone';
}
if (ulen($child) > 200) {
    $bad[] = 'child';
}
if (ulen($note) > 1000) {
    $bad[] = 'note';
}
if (($d['agree'] ?? false) !== true) {
    $bad[] = 'agree';
}
if ($bad) {
    respond(422, ['ok' => false, 'error' => 'invalid', 'fields' => $bad]);
}

$cfgPath = getenv('LEAD_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($cfgPath)) {
    respond(503, ['ok' => false, 'error' => 'not_configured']);
}
$cfg = require $cfgPath;

$mailOn = !empty($cfg['mail']['to']);
$maxOn = !empty($cfg['max']['token']) && (!empty($cfg['max']['user_id']) || !empty($cfg['max']['chat_id']));
if (!$mailOn && !$maxOn) {
    respond(503, ['ok' => false, 'error' => 'not_configured']);
}

$dataDir = (string) ($cfg['data_dir'] ?? __DIR__ . '/.data');
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0700, true);
}
rate_cleanup($dataDir);

$rate = $cfg['rate'] ?? [];
$ip = (string) ($_SERVER[$cfg['ip_header'] ?? 'REMOTE_ADDR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$ipKey = substr(hash('sha256', (string) ($cfg['salt'] ?? '') . $ip), 0, 32);
if (!rate_allow($dataDir, $ipKey, (int) ($rate['per_ip'] ?? 5), (int) ($rate['per_ip_window'] ?? 600))
    || !rate_allow($dataDir, 'all', (int) ($rate['all'] ?? 60), (int) ($rate['all_window'] ?? 3600))) {
    respond(429, ['ok' => false, 'error' => 'rate']);
}

$lines = [
    'Новая заявка с сайта «Мафия.Дети»',
    "Интересует: $want",
    "Адрес: $place",
    "Родитель: $name",
    'Телефон: ' . format_phone($digits),
];
if ($child !== '') {
    $lines[] = "Ребёнок: $child";
}
if ($note !== '') {
    $lines[] = "Комментарий: $note";
}
$text = implode("\n", $lines);

// Достаточно одного сработавшего канала: сбой второго уходит в журнал
$delivered = 0;
if ($mailOn) {
    try {
        send_mail($cfg['mail'], "Заявка с сайта: $want", $text);
        $delivered++;
    } catch (Throwable $e) {
        log_fail($dataDir, 'mail', $e->getMessage());
    }
}
if ($maxOn) {
    try {
        send_max($cfg['max'], $text);
        $delivered++;
    } catch (Throwable $e) {
        log_fail($dataDir, 'max', $e->getMessage());
    }
}

if ($delivered === 0) {
    respond(502, ['ok' => false, 'error' => 'delivery']);
}
respond(200, ['ok' => true]);
