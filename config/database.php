<?php
declare(strict_types=1);

return [
    'enabled' => filter_var(getenv('POS_DB_ENABLED') ?: '1', FILTER_VALIDATE_BOOL),
    'host' => getenv('POS_DB_HOST') ?: 'localhost',
    'port' => (int)(getenv('POS_DB_PORT') ?: 3306),
    'database' => getenv('POS_DB_NAME') ?: 'otra',
    'username' => getenv('POS_DB_USER') ?: 'root',
    'password' => getenv('POS_DB_PASS') ?: 'root',
    'charset' => getenv('POS_DB_CHARSET') ?: 'utf8mb4',
    'connect_timeout' => (int)(getenv('POS_DB_TIMEOUT') ?: 5),
];
