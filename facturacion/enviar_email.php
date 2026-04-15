<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function facturacionBrevoSend(array $signature, array $document, string $toEmail): array
{
    $apiKey = trim((string)($signature['brevoApiKey'] ?? ''));
    $endpoint = trim((string)($signature['brevoEndpoint'] ?? 'https://api.brevo.com/v3/smtp/email'));
    $fromEmail = trim((string)($signature['fromEmail'] ?? ''));
    $fromName = trim((string)($signature['fromName'] ?? 'POS Facturacion'));

    if ($apiKey === '') {
        throw new RuntimeException('No se puede enviar por Brevo: falta API Key.');
    }
    if ($fromEmail === '') {
        throw new RuntimeException('No se puede enviar por Brevo: falta correo remitente.');
    }
    if ($endpoint === '') {
        $endpoint = 'https://api.brevo.com/v3/smtp/email';
    }

    $attachments = [];
    $authorizedXml = (string)($document['files']['authorizedXml'] ?? '');
    if ($authorizedXml !== '' && file_exists($authorizedXml)) {
        $attachments[] = [
            'name' => basename($authorizedXml),
            'content' => base64_encode((string)file_get_contents($authorizedXml)),
        ];
    }
    $pdfPath = (string)($document['files']['pdf'] ?? '');
    if ($pdfPath !== '' && file_exists($pdfPath)) {
        $attachments[] = [
            'name' => basename($pdfPath),
            'content' => base64_encode((string)file_get_contents($pdfPath)),
        ];
    }

    $subject = 'Comprobante electronico ' . (string)($document['docName'] ?? 'Factura') . ' ' . (string)($document['secuencial'] ?? '');
    $html = '<p>Estimado cliente,</p><p>Adjuntamos su comprobante electronico.</p>'
        . '<p>Clave de acceso: <strong>' . htmlspecialchars((string)($document['accessKey'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong></p>';

    $payload = [
        'sender' => [
            'name' => $fromName !== '' ? $fromName : $fromEmail,
            'email' => $fromEmail,
        ],
        'to' => [[
            'email' => $toEmail,
            'name' => (string)(($document['buyer']['razonSocial'] ?? '') ?: $toEmail),
        ]],
        'subject' => $subject,
        'htmlContent' => $html,
    ];
    if ($attachments !== []) {
        $payload['attachment'] = $attachments;
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('No se puede enviar por Brevo: extension curl no disponible.');
    }

    $ch = curl_init($endpoint);
    if ($ch === false) {
        throw new RuntimeException('No se pudo inicializar cURL para Brevo.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 25,
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false || $curlError !== '') {
        throw new RuntimeException('Error de red al enviar con Brevo: ' . $curlError);
    }

    $decoded = json_decode((string)$rawResponse, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$rawResponse;
        throw new RuntimeException('Brevo respondio HTTP ' . $httpCode . ': ' . $message);
    }

    return is_array($decoded) ? $decoded : ['raw' => (string)$rawResponse];
}

function enviarEmailCliente(array $document): array
{
    $signature = facturacionLoadSignature();
    $buyer = $document['buyer'] ?? [];
    $email = trim((string)($buyer['email'] ?? ''));
    if ($email === '') {
        facturacionAppendLog('warning', 'No se envio correo porque el cliente no tiene email', ['documentId' => $document['id'] ?? null]);
        return $document;
    }

    $mode = (string)($signature['emailMode'] ?? 'mock');
    $jobs = facturacionReadJson('emails.json', []);
    $job = [
        'id' => 'mail-' . str_pad((string)(count($jobs) + 1), 6, '0', STR_PAD_LEFT),
        'documentId' => $document['id'] ?? null,
        'to' => $email,
        'mode' => $mode,
        'xml' => $document['files']['authorizedXml'] ?? null,
        'pdf' => $document['files']['pdf'] ?? null,
        'createdAt' => date('c'),
        'status' => 'queued_mock',
    ];

    if ($mode === 'mock') {
        $job['status'] = 'queued_mock';
        $jobs[] = $job;
        facturacionWriteJson('emails.json', $jobs);
        facturacionAppendLog('info', 'Correo de comprobante registrado', ['documentId' => $document['id'] ?? null, 'to' => $email]);
        return $document;
    }

    if ($mode === 'brevo_api') {
        $response = facturacionBrevoSend($signature, $document, $email);
        $job['status'] = 'sent_brevo';
        $job['response'] = $response;
        $jobs[] = $job;
        facturacionWriteJson('emails.json', $jobs);
        facturacionAppendLog('info', 'Correo enviado por Brevo API', [
            'documentId' => $document['id'] ?? null,
            'to' => $email,
            'response' => $response,
        ]);
        return $document;
    }

    $job['status'] = 'unknown_mode';
    $jobs[] = $job;
    facturacionWriteJson('emails.json', $jobs);
    facturacionAppendLog('warning', 'Modo de correo no reconocido', ['documentId' => $document['id'] ?? null, 'mode' => $mode]);
    return $document;
}

