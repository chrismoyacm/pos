<?php
declare(strict_types=1);

$argv = $_SERVER['argv'] ?? [];
$stderr = fopen('php://stderr', 'w');
if ($stderr === false) {
    $stderr = null;
}

if (!isset($argv[1]) || trim((string) $argv[1]) === '') {
    $payload = ['ok' => false, 'error' => 'Missing certificate path'];
    if ($stderr) {
        fwrite($stderr, "Missing certificate path\n");
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit(1);
}

$certificatePath = (string) $argv[1];
$password = stream_get_contents(STDIN);
if ($password === false) {
    $password = '';
}
$password = rtrim($password, "\r\n");

if (!file_exists($certificatePath)) {
    echo json_encode(['ok' => false, 'error' => 'Certificate file not found'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

$pkcs12 = file_get_contents($certificatePath);
if ($pkcs12 === false) {
    echo json_encode(['ok' => false, 'error' => 'Cannot read certificate file'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

$store = [];
if (!openssl_pkcs12_read($pkcs12, $store, $password)) {
    $errors = [];
    while (true) {
        $error = openssl_error_string();
        if ($error === false) {
            break;
        }
        $errors[] = $error;
    }
    echo json_encode([
        'ok' => false,
        'error' => implode(' | ', array_values(array_unique($errors))),
    ], JSON_UNESCAPED_SLASHES);
    exit(0);
}

echo json_encode([
    'ok' => true,
    'certStore' => [
        'cert' => (string) ($store['cert'] ?? ''),
        'pkey' => (string) ($store['pkey'] ?? ''),
        'extracerts' => $store['extracerts'] ?? [],
    ],
], JSON_UNESCAPED_SLASHES);
exit(0);
