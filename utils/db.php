<?php
declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function dbConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    if (!is_file($path)) {
        $config = ['enabled' => false];
        return $config;
    }

    $loaded = require $path;
    $config = is_array($loaded) ? $loaded : ['enabled' => false];
    $config['enabled'] = (bool)($config['enabled'] ?? false);
    return $config;
}

function dbEnabled(): bool
{
    $cfg = dbConfig();
    return (bool)($cfg['enabled'] ?? false);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!dbEnabled()) {
        throw new RuntimeException('Base de datos deshabilitada en config/database.php');
    }

    $cfg = dbConfig();
    $host = (string)($cfg['host'] ?? 'localhost');
    $port = (int)($cfg['port'] ?? 3306);
    $database = (string)($cfg['database'] ?? '');
    $charset = (string)($cfg['charset'] ?? 'utf8mb4');

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
    $pdo = new PDO(
        $dsn,
        (string)($cfg['username'] ?? ''),
        (string)($cfg['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => (int)($cfg['connect_timeout'] ?? 5),
        ]
    );

    return $pdo;
}

function legacyNow(): string
{
    return date('d/m/Y H:i:s');
}
