<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function enviarEmailCliente(array $document): array
{
    $signature = facturacionLoadSignature();
    $buyer = $document['buyer'] ?? [];
    $email = trim((string)($buyer['email'] ?? ''));
    if ($email === '') {
        facturacionAppendLog('warning', 'No se envió correo porque el cliente no tiene email', ['documentId' => $document['id'] ?? null]);
        return $document;
    }

    $jobs = facturacionReadJson('emails.json', []);
    $jobs[] = [
        'id' => 'mail-' . str_pad((string)(count($jobs) + 1), 6, '0', STR_PAD_LEFT),
        'documentId' => $document['id'] ?? null,
        'to' => $email,
        'mode' => $signature['emailMode'] ?? 'mock',
        'xml' => $document['files']['authorizedXml'] ?? null,
        'pdf' => $document['files']['pdf'] ?? null,
        'createdAt' => date('c'),
        'status' => (($signature['emailMode'] ?? 'mock') === 'mock') ? 'queued_mock' : 'pending_smtp',
    ];
    facturacionWriteJson('emails.json', $jobs);
    facturacionAppendLog('info', 'Correo de comprobante registrado', ['documentId' => $document['id'] ?? null, 'to' => $email]);
    return $document;
}

