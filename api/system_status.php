<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/persistence.php';

session_start();

ok(persistenceRefreshStatus('system_status'));
