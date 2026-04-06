<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function firmarXML(array $document): array
{
    $signature = facturacionLoadSignature();
    $source = (string)($document['files']['generatedXml'] ?? '');
    if ($source === '' || !file_exists($source)) {
        throw new RuntimeException('Primero debe generarse el XML antes de firmarlo.');
    }

    $signedDir = facturacionStoragePath('xml/firmados');
    if (!is_dir($signedDir)) {
        mkdir($signedDir, 0777, true);
    }
    $target = $signedDir . DIRECTORY_SEPARATOR . (string)$document['accessKey'] . '.xml';

    if (($signature['signatureMode'] ?? 'mock') === 'mock') {
        copy($source, $target);
        $document['files']['signedXml'] = $target;
        $document['status'] = 'signed';
        $document['updatedAt'] = date('c');
        facturacionAppendLog('info', 'XML firmado en modo mock', ['documentId' => $document['id'] ?? null]);
        return $document;
    }

    if (!class_exists('RobRichards\\XMLSecLibs\\XMLSecurityKey')) {
        throw new RuntimeException('No se encontró xmlseclibs. Instale la librería para firma XAdES-BES real.');
    }

    throw new RuntimeException('La firma XAdES-BES real aún no está implementada en esta instalación.');
}

