<?php
declare(strict_types=1);

require_once __DIR__ . '/response.php';

function storagePath(string $fileName): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $fileName;
}

/**
 * @return array<int|string, mixed>
 */
function readJsonFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }
    return $decoded;
}

function writeJsonFile(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $fp = fopen($path, 'c+');
    if ($fp === false) {
        errorResponse('No se pudo abrir el archivo de almacenamiento', 500);
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            errorResponse('No se pudo bloquear el archivo de almacenamiento', 500);
        }

        ftruncate($fp, 0);
        rewind($fp);
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            errorResponse('No se pudo serializar JSON', 500);
        }
        fwrite($fp, $encoded);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

/**
 * @return array{method:string, body:mixed}
 */
function getRequestInfo(): array
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $rawBody = file_get_contents('php://input');
    $body = null;
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        $body = $decoded === null ? $rawBody : $decoded;
    }
    return ['method' => $method, 'body' => $body];
}

