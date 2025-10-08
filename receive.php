<?php
// upload.php
declare(strict_types=1);

// fester Zielordner (außerhalb webroot, wenn möglich)
const TARGET_DIR = __DIR__ . '/cache';
$secret  = file_get_contents(".secret");
if (!is_dir(TARGET_DIR)) mkdir(TARGET_DIR, 0775, true);

function bad(int $code, string $msg){ http_response_code($code); echo $msg; exit; }

$ts  = $_SERVER['HTTP_X_TS'] ?? '';
$sig = $_SERVER['HTTP_X_SIG'] ?? '';
$rel = $_GET['file'] ?? ''; // z.B. "aida_adults1.json"

if (!preg_match('~^[a-z0-9._-]+\.json$~i', $rel)) bad(400,'Bad filename');
if (abs(time() - (int)$ts) > 300) bad(401,'Stale timestamp'); // 5 min Fenster

$body = file_get_contents('php://input');
$calc = base64_encode(hash_hmac('sha256', $ts . "\n" . $rel . "\n" . $body, $secret, true));
if (!hash_equals($calc, $sig)) bad(401,'Bad signature');

// Atomar schreiben, wenn Inhalt sich ändert
$target = TARGET_DIR . '/' . $rel;
$hashNew = hash('sha256', $body);
$hashFile = $target.'.sha256';
$hashOld = is_file($hashFile) ? trim((string)file_get_contents($hashFile)) : '';

if ($hashNew === $hashOld) { echo 'NOCHANGE'; exit; }

$tmp = $target.'.tmp';
file_put_contents($tmp, $body);
@chmod($tmp, 0644);
@rename($tmp, $target);
file_put_contents($hashFile, $hashNew);
@chmod($hashFile, 0644);

echo 'OK';
