<?php
declare(strict_types=1);

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/../models/legacy_store.php';

function storagePath(string $fileName): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $fileName;
}

/**
 * @return array<int|string, mixed>
 */
function readDiskJsonFile(string $path): array
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

function storageFileKey(string $path): string
{
    $storageRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR;
    $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $normalizedRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storageRoot);
    if (str_starts_with(strtolower($normalizedPath), strtolower($normalizedRoot))) {
        $relative = substr($normalizedPath, strlen($normalizedRoot));
        return str_replace('\\', '/', (string)$relative);
    }

    return basename($path);
}

/**
 * @return array<int|string, mixed>
 */
function readJsonFile(string $path): array
{
    $mapped = legacyMappedRead(storageFileKey($path));
    if (is_array($mapped)) {
        return $mapped;
    }

    return readDiskJsonFile($path);
}

function writeJsonBackupFile(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            return false;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            flock($fp, LOCK_UN);
            return false;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $encoded);
        fflush($fp);
        flock($fp, LOCK_UN);
        return true;
    } finally {
        fclose($fp);
    }
}

function writeJsonFile(string $path, array $data): void
{
    if (legacyMappedWrite(storageFileKey($path), $data)) {
        return;
    }
    if (!writeJsonBackupFile($path, $data)) {
        errorResponse('No se pudo escribir el archivo de almacenamiento', 500);
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

