<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

function facturacionSignatureRandom(string $prefix, int $digits = 6): string
{
    $max = (10 ** $digits) - 1;
    return $prefix . str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
}

function facturacionSignatureGuidLike(string $prefix): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    $guid = substr($hex, 0, 8)
        . '-' . substr($hex, 8, 4)
        . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4)
        . '-' . substr($hex, 20, 12);
    return $prefix . $guid;
}

function facturacionPemBody(string $pem): string
{
    return preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/m', '', $pem) ?? '';
}

function facturacionHexToDecimalString(string $hex): string
{
    $hex = strtoupper(ltrim($hex, '0'));
    if ($hex === '') {
        return '0';
    }

    $decimal = '0';
    $length = strlen($hex);
    for ($i = 0; $i < $length; $i += 1) {
        $value = hexdec($hex[$i]);
        $carry = $value;
        $result = '';
        for ($j = strlen($decimal) - 1; $j >= 0; $j -= 1) {
            $current = ((int) $decimal[$j] * 16) + $carry;
            $result = ($current % 10) . $result;
            $carry = intdiv($current, 10);
        }
        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }
        $decimal = ltrim($result, '0');
        if ($decimal === '') {
            $decimal = '0';
        }
    }

    return $decimal;
}

function facturacionFormatDn(array|string $dn): string
{
    if (!is_array($dn)) {
        return trim((string) $dn);
    }

    $parts = [];
    foreach ($dn as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $nested) {
                array_unshift($parts, $key . '=' . $nested);
            }
            continue;
        }
        array_unshift($parts, $key . '=' . $value);
    }

    return implode(', ', $parts);
}

function facturacionCreateDigestValue(string $binary, string $algorithm = 'sha1'): string
{
    return base64_encode(hash($algorithm, $binary, true));
}

function facturacionCreateElement(DOMDocument $dom, DOMElement $parent, string $namespace, string $qualifiedName, ?string $value = null): DOMElement
{
    $node = $dom->createElementNS($namespace, $qualifiedName);
    if ($value !== null) {
        $node->appendChild($dom->createTextNode($value));
    }
    $parent->appendChild($node);
    return $node;
}

function facturacionFindLastReference(XMLSecurityDSig $dsig): DOMElement
{
    $xpath = new DOMXPath($dsig->sigNode->ownerDocument);
    $xpath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
    $nodes = $xpath->query('./ds:SignedInfo/ds:Reference', $dsig->sigNode);
    if ($nodes === false || $nodes->length === 0) {
        throw new RuntimeException('No se pudo ubicar la referencia XML recién agregada.');
    }
    $reference = $nodes->item($nodes->length - 1);
    if (!$reference instanceof DOMElement) {
        throw new RuntimeException('Referencia XML inválida.');
    }
    return $reference;
}

function facturacionRemoveEmptyTransforms(DOMElement $reference): void
{
    foreach (iterator_to_array($reference->childNodes) as $child) {
        if (!$child instanceof DOMElement || $child->localName !== 'Transforms') {
            continue;
        }
        if (!$child->hasChildNodes()) {
            $reference->removeChild($child);
            return;
        }
        $onlyEmpty = true;
        foreach (iterator_to_array($child->childNodes) as $transformChild) {
            if ($transformChild instanceof DOMElement) {
                $onlyEmpty = false;
                break;
            }
        }
        if ($onlyEmpty) {
            $reference->removeChild($child);
        }
        return;
    }
}

function facturacionRemoveAllTransforms(DOMElement $reference): void
{
    foreach (iterator_to_array($reference->childNodes) as $child) {
        if ($child instanceof DOMElement && $child->localName === 'Transforms') {
            $reference->removeChild($child);
            return;
        }
    }
}

function facturacionStripNamespaceDeclarations(DOMElement $node, string $prefix): void
{
    $xmlnsNamespace = 'http://www.w3.org/2000/xmlns/';
    if ($node->hasAttributeNS($xmlnsNamespace, $prefix)) {
        $node->removeAttributeNS($xmlnsNamespace, $prefix);
    }
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMElement) {
            facturacionStripNamespaceDeclarations($child, $prefix);
        }
    }
}

function facturacionStripWhitespaceTextNodes(DOMNode $node): void
{
    $toRemove = [];
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            if (trim($child->nodeValue ?? '') === '') {
                $toRemove[] = $child;
            }
            continue;
        }
        if ($child instanceof DOMNode) {
            facturacionStripWhitespaceTextNodes($child);
        }
    }
    foreach ($toRemove as $child) {
        $node->removeChild($child);
    }
}

function facturacionRecalculateComprobanteDigest(DOMDocument $dom, DOMElement $factura, DOMElement $signatureNode, DOMElement $referenceNode): void
{
    $digestValueNode = null;
    foreach ($referenceNode->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'DigestValue') {
            $digestValueNode = $child;
            break;
        }
    }
    if (!$digestValueNode instanceof DOMElement) {
        throw new RuntimeException('No se pudo ubicar DigestValue de la referencia al comprobante.');
    }

    $clone = $dom->cloneNode(true);
    if (!$clone instanceof DOMDocument) {
        throw new RuntimeException('No se pudo clonar el XML para recalcular el digest del comprobante.');
    }

    $cloneXPath = new DOMXPath($clone);
    $cloneXPath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
    $cloneFactura = $clone->documentElement;
    if (!$cloneFactura instanceof DOMElement) {
        throw new RuntimeException('No se pudo ubicar la factura clonada para recalcular el digest.');
    }

    $cloneSignature = $cloneXPath->query('./ds:Signature', $cloneFactura)?->item(0);
    if ($cloneSignature instanceof DOMElement) {
        $cloneFactura->removeChild($cloneSignature);
    }

    $canonical = $cloneFactura->C14N(false, false);
    if ($canonical === false) {
        throw new RuntimeException('No se pudo canonicalizar el comprobante para recalcular su digest.');
    }

    $digestValueNode->nodeValue = base64_encode(sha1($canonical, true));
}

function facturacionAppendRsaKeyValue(XMLSecurityDSig $dsig, string $privateKeyPem): void
{
    $resource = openssl_pkey_get_private($privateKeyPem);
    if ($resource === false) {
        throw new RuntimeException('No se pudo leer la llave privada para construir KeyValue.');
    }

    $details = openssl_pkey_get_details($resource);
    if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || !isset($details['rsa'])) {
        throw new RuntimeException('El certificado digital no contiene una llave RSA válida.');
    }

    $rsa = $details['rsa'];
    $doc = $dsig->sigNode->ownerDocument;
    $dsNs = XMLSecurityDSig::XMLDSIGNS;

    $keyValue = $doc->createElementNS($dsNs, 'ds:KeyValue');
    $rsaKeyValue = $doc->createElementNS($dsNs, 'ds:RSAKeyValue');
    $keyValue->appendChild($rsaKeyValue);

    $modulus = $doc->createElementNS($dsNs, 'ds:Modulus', base64_encode((string) ($rsa['n'] ?? '')));
    $exponent = $doc->createElementNS($dsNs, 'ds:Exponent', base64_encode((string) ($rsa['e'] ?? '')));
    $rsaKeyValue->appendChild($modulus);
    $rsaKeyValue->appendChild($exponent);

    $dsig->appendToKeyInfo($keyValue);
}

function facturacionBuildSignedProperties(
    DOMDocument $doc,
    DOMElement $objectNode,
    string $signatureId,
    string $qualifyingPropertiesId,
    string $signedPropertiesId,
    string $comprobanteReferenceId,
    string $certificatePem,
    array $certificateData,
    string $signingTime
): DOMElement {
    $xadesNs = 'http://uri.etsi.org/01903/v1.3.2#';
    $dsNs = XMLSecurityDSig::XMLDSIGNS;

    $qualifying = facturacionCreateElement($doc, $objectNode, $xadesNs, 'xades:QualifyingProperties');
    $qualifying->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xades', $xadesNs);
    $qualifying->setAttribute('Id', $qualifyingPropertiesId);
    $qualifying->setAttribute('Target', '#' . $signatureId);

    $signedProperties = facturacionCreateElement($doc, $qualifying, $xadesNs, 'xades:SignedProperties');
    $signedProperties->setAttribute('Id', $signedPropertiesId);

    $signedSignatureProperties = facturacionCreateElement($doc, $signedProperties, $xadesNs, 'xades:SignedSignatureProperties');
    facturacionCreateElement($doc, $signedSignatureProperties, $xadesNs, 'xades:SigningTime', $signingTime);

    $signingCertificate = facturacionCreateElement($doc, $signedSignatureProperties, $xadesNs, 'xades:SigningCertificate');
    $certNode = facturacionCreateElement($doc, $signingCertificate, $xadesNs, 'xades:Cert');
    $certDigest = facturacionCreateElement($doc, $certNode, $xadesNs, 'xades:CertDigest');
    $digestMethod = facturacionCreateElement($doc, $certDigest, $dsNs, 'ds:DigestMethod');
    $digestMethod->setAttribute('Algorithm', XMLSecurityDSig::SHA1);

    $pemBody = facturacionPemBody($certificatePem);
    $certificateDer = base64_decode($pemBody, true);
    if ($certificateDer === false) {
        throw new RuntimeException('No se pudo obtener el contenido DER del certificado.');
    }
    facturacionCreateElement($doc, $certDigest, $dsNs, 'ds:DigestValue', facturacionCreateDigestValue($certificateDer));

    $issuerSerial = facturacionCreateElement($doc, $certNode, $xadesNs, 'xades:IssuerSerial');
    $issuerName = facturacionFormatDn($certificateData['issuer'] ?? []);
    $serialNumber = trim((string) ($certificateData['serialNumber'] ?? ''));
    if ($serialNumber === '') {
        $serialNumber = facturacionHexToDecimalString((string) ($certificateData['serialNumberHex'] ?? '0'));
    }
    facturacionCreateElement($doc, $issuerSerial, $dsNs, 'ds:X509IssuerName', $issuerName);
    facturacionCreateElement($doc, $issuerSerial, $dsNs, 'ds:X509SerialNumber', $serialNumber);

    $signedDataObjectProperties = facturacionCreateElement($doc, $signedProperties, $xadesNs, 'xades:SignedDataObjectProperties');
    $dataObjectFormat = facturacionCreateElement($doc, $signedDataObjectProperties, $xadesNs, 'xades:DataObjectFormat');
    $dataObjectFormat->setAttribute('ObjectReference', '#' . $comprobanteReferenceId);
    facturacionCreateElement($doc, $dataObjectFormat, $xadesNs, 'xades:MimeType', 'text/xml');
    facturacionCreateElement($doc, $dataObjectFormat, $xadesNs, 'xades:Encoding', 'UTF-8');

    return $signedProperties;
}

function facturacionPrepareRealSignature(string $source, string $certificatePath, string $certificatePassword): array
{
    $result = facturacionReadPkcs12Store($certificatePath, $certificatePassword);
    $certStore = (array)($result['certStore'] ?? []);

    $privateKey = (string) ($certStore['pkey'] ?? '');
    $certificatePem = (string) ($certStore['cert'] ?? '');
    if ($privateKey === '' || $certificatePem === '') {
        throw new RuntimeException('El certificado digital no contiene la llave privada y el certificado requeridos.');
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    if (!$dom->load($source)) {
        throw new RuntimeException('No se pudo cargar el XML generado para la firma.');
    }

    $factura = $dom->documentElement;
    if (!$factura instanceof DOMElement || $factura->localName !== 'factura') {
        throw new RuntimeException('El XML a firmar no tiene un nodo raíz factura válido.');
    }

    if (!$factura->hasAttribute('id')) {
        $factura->setAttribute('id', 'comprobante');
    }

    return [$dom, $factura, $privateKey, $certificatePem, openssl_x509_parse($certificatePem) ?: []];
}

function firmarXML(array $document): array
{
    $signature = facturacionLoadSignature();
    $source = facturacionMaterializeDocumentFile($document, 'generatedXml');
    if ($source === '') {
        throw new RuntimeException('Primero debe generarse el XML antes de firmarlo.');
    }

    $certificatePath = trim((string) ($signature['certificatePath'] ?? ''));
    $certificatePassword = (string) ($signature['certificatePassword'] ?? '');
    if ($certificatePath === '' || !file_exists($certificatePath)) {
        throw new RuntimeException('No se encontró el certificado digital configurado.');
    }
    if (!class_exists(XMLSecurityKey::class)) {
        throw new RuntimeException('No se encontró xmlseclibs. Revise la instalación de Composer antes de firmar.');
    }

    [$dom, $factura, $privateKeyPem, $certificatePem, $certificateData] = facturacionPrepareRealSignature(
        $source,
        $certificatePath,
        $certificatePassword
    );

    $signatureId = facturacionSignatureGuidLike('Signature-');
    $signatureValueId = 'SignatureValue-' . substr($signatureId, strlen('Signature-'));
    $keyInfoId = 'KeyInfoId-' . $signatureId;
    $signedPropertiesId = 'SignedProperties-' . $signatureId;
    $qualifyingPropertiesId = 'QualifyingProperties-' . substr($signatureId, strlen('Signature-'));
    $signedPropertiesReferenceId = facturacionSignatureGuidLike('Reference-');
    $keyInfoReferenceId = 'ReferenceKeyInfo';
    $comprobanteReferenceId = facturacionSignatureGuidLike('Reference-');
    $objectId = 'XadesObjectId-' . substr($signatureId, strlen('Signature-'));
    $signingTime = date('c');

    $dsig = new XMLSecurityDSig('ds');
    $dsig->idKeys[] = 'id';
    $appendedSignature = $dsig->appendSignature($factura);
    if (!$appendedSignature instanceof DOMElement) {
        throw new RuntimeException('No se pudo anexar el nodo Signature al comprobante.');
    }
    $dsig->sigNode = $appendedSignature;
    $dsig->setCanonicalMethod(XMLSecurityDSig::C14N);

    $signatureNode = $dsig->sigNode;
    if (!$signatureNode instanceof DOMElement) {
        throw new RuntimeException('No se pudo crear el nodo de firma XML.');
    }
    $signatureNode->setAttribute('Id', $signatureId);

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);

    $signedInfoNode = $xpath->query('./ds:SignedInfo', $signatureNode)?->item(0);
    if (!$signedInfoNode instanceof DOMElement) {
        throw new RuntimeException('No se pudo crear SignedInfo para la firma XML.');
    }

    $dsig->add509Cert($certificatePem, true, false, ['issuerSerial' => false]);
    facturacionAppendRsaKeyValue($dsig, $privateKeyPem);
    $keyInfoNode = $xpath->query('./ds:KeyInfo', $signatureNode)?->item(0);
    if (!$keyInfoNode instanceof DOMElement) {
        throw new RuntimeException('No se pudo crear KeyInfo para la firma XML.');
    }
    $keyInfoNode->setAttribute('Id', $keyInfoId);

    $dsig->addReference(
        $factura,
        XMLSecurityDSig::SHA1,
        ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
        ['overwrite' => false, 'id_name' => 'id']
    );
    $comprobanteReference = facturacionFindLastReference($dsig);
    $comprobanteReference->setAttribute('Id', $comprobanteReferenceId);

    $dsig->addReference(
        $keyInfoNode,
        XMLSecurityDSig::SHA1,
        null,
        ['overwrite' => false, 'id_name' => 'Id']
    );
    $keyInfoReference = facturacionFindLastReference($dsig);
    $keyInfoReference->setAttribute('Id', $keyInfoReferenceId);
    facturacionRemoveAllTransforms($keyInfoReference);

    $objectNode = $dsig->addObject('');
    $objectNode->setAttribute('Id', $objectId);
    while ($objectNode->firstChild !== null) {
        $objectNode->removeChild($objectNode->firstChild);
    }

    $signedPropertiesNode = facturacionBuildSignedProperties(
        $dom,
        $objectNode,
        $signatureId,
        $qualifyingPropertiesId,
        $signedPropertiesId,
        $comprobanteReferenceId,
        $certificatePem,
        $certificateData,
        $signingTime
    );

    facturacionStripWhitespaceTextNodes($signatureNode);
    facturacionRecalculateComprobanteDigest($dom, $factura, $signatureNode, $comprobanteReference);

    $dsig->addReference(
        $signedPropertiesNode,
        XMLSecurityDSig::SHA1,
        null,
        ['overwrite' => false, 'id_name' => 'Id']
    );
    $signedPropertiesReference = facturacionFindLastReference($dsig);
    $signedPropertiesReference->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
    facturacionRemoveAllTransforms($signedPropertiesReference);

    $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'private']);
    $key->loadKey($privateKeyPem, false);
    $dsig->sign($key);

    $signatureValueNode = $xpath->query('./ds:SignatureValue', $signatureNode)?->item(0);
    if ($signatureValueNode instanceof DOMElement) {
        $signatureValueNode->setAttribute('Id', $signatureValueId);
    }

    $signedXml = $dom->saveXML();
    if (!is_string($signedXml) || trim($signedXml) === '') {
        throw new RuntimeException('No se pudo serializar el XML firmado.');
    }
    $document = facturacionStoreDocumentFile($document, 'signedXml', $signedXml);
    $document['status'] = 'signed';
    $document['updatedAt'] = date('c');
    facturacionAppendLog('info', 'XML firmado con XAdES-BES', [
        'documentId' => $document['id'] ?? null,
        'accessKey' => $document['accessKey'] ?? null,
        'certificatePath' => $certificatePath,
    ]);

    return $document;
}
