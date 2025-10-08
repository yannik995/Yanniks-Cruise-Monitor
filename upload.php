<?php
function httpsUploadIfChanged(string $baseUrl, string $secret, string $localFile, string $remoteName): bool {
    if (!is_file($localFile)) return false;
    $data = file_get_contents($localFile);
    $ts = (string)time();
    $sig = base64_encode(hash_hmac('sha256', $ts."\n".$remoteName."\n".$data, $secret, true));

    $ch = curl_init($baseUrl.'?file='.rawurlencode($remoteName));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-TS: '.$ts,
            'X-SIG: '.$sig,
        ],
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) throw new RuntimeException('Upload failed: '.$code.' '.$resp);
    return trim((string)$resp) === 'OK';
}

// Beispiel:
$baseUrl = 'https://yannikeichel.de/aida/receive.php';
$secret  = file_get_contents(".secret");

foreach (glob(__DIR__.'/cache/aida_a*.json') as $lf) {
    $changed = httpsUploadIfChanged($baseUrl, $secret, $lf, basename($lf));
    if ($changed) echo "↑ Hochgeladen: ".basename($lf).PHP_EOL;
}
