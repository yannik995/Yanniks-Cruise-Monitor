<?php
declare(strict_types=1);

function cfg(string $key, $default = null) {
    global $CFG;
    return $CFG[$key] ?? $default;
}

$CFG = [];
$cfgFile = __DIR__ . '/config.php';
if (is_file($cfgFile)) {
    $loaded = require $cfgFile;
    if (is_array($loaded)) {
        $CFG = $loaded;
    }
}
define('CACHE_DIR', cfg('CACHE_DIR', __DIR__ . '/cache'));
define('SECRET', cfg('SECRET'));

if (!SECRET) {
    echo "Secret not set in config.php";
    http_response_code(500);
}

if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0775, true);

function bad(int $code, string $msg)
{
    http_response_code($code);
    echo $msg;
    exit;
}

$ts = $_SERVER['HTTP_X_TS'] ?? '';
$sig = $_SERVER['HTTP_X_SIG'] ?? '';
$rel = $_GET['file'] ?? ''; // z.B. "aida_adults1.json"

if (!preg_match('~^[a-z0-9._-]+\.json$~i', $rel)) bad(400, 'Bad filename');
if (abs(time() - (int)$ts) > 300) bad(401, 'Stale timestamp'); // 5 min Fenster

$body = file_get_contents('php://input');
$calc = base64_encode(hash_hmac('sha256', $ts . "\n" . $rel . "\n" . $body, SECRET, true));
if (!hash_equals($calc, $sig)) bad(401, 'Bad signature');

// Atomar schreiben, wenn Inhalt sich ändert
$target = CACHE_DIR . '/' . $rel;
$hashNew = hash('sha256', $body);
$hashFile = $target . '.sha256';
$hashOld = is_file($hashFile) ? trim((string)file_get_contents($hashFile)) : '';

if ($hashNew === $hashOld) {
    echo 'NOCHANGE';
    exit;
}

$tmp = $target . '.tmp';
file_put_contents($tmp, $body);
@chmod($tmp, 0644);
@rename($tmp, $target);
file_put_contents($hashFile, $hashNew);
@chmod($hashFile, 0644);

echo 'OK';
