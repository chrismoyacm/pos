<?php
declare(strict_types=1);

function sendJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(mixed $data = null): void
{
    sendJson(['ok' => true, 'data' => $data]);
}

function errorResponse(string $message, int $statusCode = 400, mixed $details = null): void
{
    $payload = ['ok' => false, 'error' => $message];
    if ($details !== null) {
        $payload['details'] = $details;
    }
    sendJson($payload, $statusCode);
}

