<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function persistenceStatusPath(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'db_fallback_status.json';
}

/**
 * @return array{active:bool,message:string,context:string,updatedAt:string,dbEnabled:bool,dbAvailable:bool}
 */
function persistenceGetStatus(): array
{
    $defaults = [
        'active' => false,
        'message' => '',
        'context' => '',
        'updatedAt' => '',
        'dbEnabled' => dbEnabled(),
        'dbAvailable' => dbEnabled(),
    ];

    $path = persistenceStatusPath();
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return [
        'active' => (bool)($decoded['active'] ?? false),
        'message' => trim((string)($decoded['message'] ?? '')),
        'context' => trim((string)($decoded['context'] ?? '')),
        'updatedAt' => trim((string)($decoded['updatedAt'] ?? '')),
        'dbEnabled' => (bool)($decoded['dbEnabled'] ?? dbEnabled()),
        'dbAvailable' => (bool)($decoded['dbAvailable'] ?? dbEnabled()),
    ];
}

/**
 * @param array<string, mixed> $status
 */
function persistenceWriteStatus(array $status): void
{
    $path = persistenceStatusPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $payload = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($payload)) {
        return;
    }

    file_put_contents($path, $payload, LOCK_EX);
}

/**
 * @return array{dbEnabled:bool,dbAvailable:bool,message:string}
 */
function persistenceProbeDb(): array
{
    if (!dbEnabled()) {
        return [
            'dbEnabled' => false,
            'dbAvailable' => false,
            'message' => 'Base de datos deshabilitada. Operando solo con respaldo JSON.',
        ];
    }

    try {
        db()->query('SELECT 1');
        return [
            'dbEnabled' => true,
            'dbAvailable' => true,
            'message' => '',
        ];
    } catch (Throwable $e) {
        return [
            'dbEnabled' => true,
            'dbAvailable' => false,
            'message' => 'Sin conexion a base de datos. Operando con respaldo JSON.',
        ];
    }
}

function persistenceMarkDbHealthy(string $context = ''): void
{
    persistenceWriteStatus([
        'active' => false,
        'message' => '',
        'context' => $context,
        'updatedAt' => date('c'),
        'dbEnabled' => dbEnabled(),
        'dbAvailable' => true,
    ]);
}

function persistenceMarkDbFallback(string $context, string $message = ''): void
{
    $probe = persistenceProbeDb();
    $finalMessage = trim($message);
    if ($finalMessage === '') {
        $finalMessage = $probe['message'] !== '' ? $probe['message'] : 'Sin conexion a base de datos. Operando con respaldo JSON.';
    }

    persistenceWriteStatus([
        'active' => true,
        'message' => $finalMessage,
        'context' => $context,
        'updatedAt' => date('c'),
        'dbEnabled' => (bool)$probe['dbEnabled'],
        'dbAvailable' => false,
    ]);
}

/**
 * @return array{active:bool,message:string,context:string,updatedAt:string,dbEnabled:bool,dbAvailable:bool}
 */
function persistenceRefreshStatus(string $context = 'system'): array
{
    $probe = persistenceProbeDb();
    if ($probe['dbAvailable']) {
        persistenceMarkDbHealthy($context);
        return persistenceGetStatus();
    }

    persistenceMarkDbFallback($context, $probe['message']);
    return persistenceGetStatus();
}
