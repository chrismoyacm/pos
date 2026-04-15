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

/**
 * Ejecuta un Stored Procedure y retorna todas las filas
 * @param string $procName Nombre del SP (ej: sp_get_sales_by_day)
 * @param array<string, mixed> $params Parámetros nombrados (ej: ['date' => '2024-01-01', 'page' => 1])
 * @return array<int, array<string, mixed>>
 */
function callStoredProcedure(string $procName, array $params = []): array
{
    $pdo = db();

    $placeholders = implode(', ', array_fill(0, count($params), '?'));
    $sql = sprintf('CALL %s(%s)', $procName, $placeholders);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($params));
    
    $results = $stmt->fetchAll();
    
    // Limpiar la siguiente consulta pendiente de CALL
    try {
        while ($stmt->nextRowset()) {
            // vaciar cada result set
        }
    } catch (PDOException) {
        // ignorar si no hay más result sets
    }
    
    return is_array($results) ? $results : [];
}

/**
 * Ejecuta un SP y retorna una única fila
 * @param string $procName
 * @param array<string, mixed> $params
 * @return array<string, mixed>|null
 */
function callStoredProcedureOne(string $procName, array $params = []): ?array
{
    $results = callStoredProcedure($procName, $params);
    return count($results) > 0 ? $results[0] : null;
}

/**
 * Ejecuta un SP que retorna múltiples result sets
 * @param string $procName
 * @param array<string, mixed> $params
 * @return array<int, array<int, array<string, mixed>>>
 */
function callStoredProcedureMulti(string $procName, array $params = []): array
{
    $pdo = db();

    $placeholders = implode(', ', array_fill(0, count($params), '?'));
    $sql = sprintf('CALL %s(%s)', $procName, $placeholders);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($params));
    
    $allResults = [];
    do {
        $allResults[] = $stmt->fetchAll();
    } while ($stmt->nextRowset());
    
    return $allResults;
}
