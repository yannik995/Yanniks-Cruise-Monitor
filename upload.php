<?php
function httpsUploadIfChanged(string $baseUrl, string $secret, string $localFile, string $remoteName): bool
{
    if (!is_file($localFile)) return false;
    $data = file_get_contents($localFile);
    $ts = (string)time();
    $sig = base64_encode(hash_hmac('sha256', $ts . "\n" . $remoteName . "\n" . $data, $secret, true));

    $ch = curl_init($baseUrl . '?file=' . rawurlencode($remoteName));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-TS: ' . $ts,
            'X-SIG: ' . $sig,
        ],
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) throw new RuntimeException('Upload failed: ' . $code . ' ' . $resp);
    return trim((string)$resp) === 'OK';
}

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
define('SECRET', cfg('SECRET', gethostname()));
define('BASE_URL', cfg('BASE_URL', gethostname()));

if (!SECRET) {
    echo "Secret not set in config.php";
    http_response_code(500);
}

foreach (glob(CACHE_DIR . '/aida_a*.json') as $lf) {
    $changed = httpsUploadIfChanged(BASE_URL, SECRET, $lf, basename($lf));
    if ($changed) echo "↑ Hochgeladen: " . basename($lf) . PHP_EOL;
}
