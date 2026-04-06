<?php
$currentView = (string)($_GET['view'] ?? '');
$labels = [
    'nota-credito' => 'Nota de credito',
    'nota-debito' => 'Nota de debito',
    'guia-remision' => 'Guia de remision',
    'retencion' => 'Comprobante de retencion',
    'liquidacion' => 'Liquidacion de compra de bienes y prestacion de servicios',
];
$title = $labels[$currentView] ?? 'Documento';
?>
<section class="facturacion-card">
  <div class="facturacion-card-head">
    <h3><?php echo htmlspecialchars($title); ?></h3>
    <p>La estructura base del modulo ya esta preparada. Este comprobante se conectará despues de cerrar la factura y los catalogos oficiales del SRI.</p>
  </div>
  <div class="facturacion-note">
    Estado actual: vista reservada y flujo preparado para reutilizar configuracion del emisor, firma, puntos de emision, almacenamiento y pipeline tecnico.
  </div>
</section>
