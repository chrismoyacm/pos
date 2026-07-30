<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * @param string|array<int, string|array<string, string>> $recipients
 * @return array<int, array{email:string,name:string}>
 */
function facturacionNormalizeEmailRecipients(string|array $recipients, string $defaultName = ''): array
{
    $rawRows = is_array($recipients) ? $recipients : [$recipients];
    $out = [];
    $seen = [];

    foreach ($rawRows as $row) {
        $email = '';
        $name = '';
        if (is_array($row)) {
            $email = trim((string)($row['email'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
        } else {
            $email = trim((string)$row);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $key = strtolower($email);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = [
            'email' => $email,
            'name' => $name !== '' ? $name : ($defaultName !== '' ? $defaultName : $email),
        ];
    }

    return $out;
}

function facturacionDocumentEmailRecipients(array $signature, array $document): array
{
    $buyer = is_array($document['buyer'] ?? null) ? $document['buyer'] : [];
    $emitter = is_array($document['emitter'] ?? null) ? $document['emitter'] : facturacionLoadEmitter();

    $buyerEmail = trim((string)($buyer['email'] ?? ''));
    $buyerName = trim((string)(($buyer['razonSocial'] ?? '') ?: $buyerEmail));
    $ownerEmail = trim((string)($emitter['email'] ?? ''));
    if ($ownerEmail === '') {
        $ownerEmail = trim((string)($signature['fromEmail'] ?? ''));
    }
    $ownerName = trim((string)(($emitter['nombreComercial'] ?? '') ?: ($emitter['razonSocial'] ?? '')));

    return facturacionNormalizeEmailRecipients([
        ['email' => $buyerEmail, 'name' => $buyerName],
        ['email' => $ownerEmail, 'name' => $ownerName],
    ]);
}

function facturacionBrevoSend(array $signature, array $document, string|array $recipients): array
{
    $apiKey = trim((string)($signature['brevoApiKey'] ?? ''));
    $endpoint = trim((string)($signature['brevoEndpoint'] ?? 'https://api.brevo.com/v3/smtp/email'));
    $fromEmail = trim((string)($signature['fromEmail'] ?? ''));
    $fromName = trim((string)($signature['fromName'] ?? 'POS Facturacion'));
    $to = facturacionNormalizeEmailRecipients($recipients);

    if ($apiKey === '') {
        throw new RuntimeException('No se puede enviar por Brevo: falta API Key.');
    }
    if ($fromEmail === '') {
        throw new RuntimeException('No se puede enviar por Brevo: falta correo remitente.');
    }
    if ($endpoint === '') {
        $endpoint = 'https://api.brevo.com/v3/smtp/email';
    }
    if ($to === []) {
        throw new RuntimeException('No se puede enviar por Brevo: no hay destinatarios validos.');
    }

    $attachments = [];
    $authorizedXml = facturacionReadDocumentFile($document, 'authorizedXml');
    if ($authorizedXml !== null) {
        $attachments[] = [
            'name' => (string)($authorizedXml['fileName'] ?? 'comprobante.xml'),
            'content' => base64_encode((string)($authorizedXml['content'] ?? '')),
        ];
    }
    $pdf = facturacionReadDocumentFile($document, 'pdf');
    if ($pdf !== null) {
        $attachments[] = [
            'name' => (string)($pdf['fileName'] ?? 'comprobante.pdf'),
            'content' => base64_encode((string)($pdf['content'] ?? '')),
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
        'to' => $to,
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

    // En Windows la cadena CA del sistema suele ser mas confiable que un cacert.pem
    // externo, especialmente en instalaciones de cliente con certificados raiz propios.
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }

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
    $recipients = facturacionDocumentEmailRecipients($signature, $document);
    if ($recipients === []) {
        facturacionAppendLog('warning', 'No se envio correo porque no hay destinatarios validos', ['documentId' => $document['id'] ?? null]);
        return $document;
    }
    $recipientEmails = array_map(static fn(array $row): string => (string)$row['email'], $recipients);

    $mode = (string)($signature['emailMode'] ?? 'mock');
    $jobs = facturacionReadJson('emails.json', []);
    $job = [
        'id' => 'mail-' . str_pad((string)(count($jobs) + 1), 6, '0', STR_PAD_LEFT),
        'documentId' => $document['id'] ?? null,
        'to' => $recipientEmails[0] ?? '',
        'recipients' => $recipientEmails,
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
        facturacionAppendLog('info', 'Correo de comprobante registrado', ['documentId' => $document['id'] ?? null, 'recipients' => $recipientEmails]);
        return $document;
    }

    if ($mode === 'brevo_api') {
        $response = facturacionBrevoSend($signature, $document, $recipients);
        $job['status'] = 'sent_brevo';
        $job['response'] = $response;
        $jobs[] = $job;
        facturacionWriteJson('emails.json', $jobs);
        facturacionAppendLog('info', 'Correo enviado por Brevo API', [
            'documentId' => $document['id'] ?? null,
            'recipients' => $recipientEmails,
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

