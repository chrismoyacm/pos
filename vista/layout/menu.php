<?php
declare(strict_types=1);
function menuLink(string $label, string $kbd, string $mod, string $current): void {
  $active = $mod === $current ? 'active' : '';
  $kbdHtml = $kbd !== '' ? '<span class="kbd">'.$kbd.'</span>' : '';
  echo '<a class="'.$active.'" href="index.php?mod='.$mod.'">'.$kbdHtml.' '.$label.'</a>';
}
$current = $_GET['mod'] ?? 'ventas';
?>
<nav class="menu">
  <?php menuLink('Ventas', 'F1', 'ventas', $current); ?>
  <?php menuLink('Créditos', 'F2', 'creditos', $current); ?>
  <?php menuLink('Clientes', '', 'clientes', $current); ?>
  <?php menuLink('Productos', 'F3', 'productos', $current); ?>
  <?php menuLink('Inventario', 'F4', 'inventario', $current); ?>
  <?php menuLink('Compras', '', 'compras', $current); ?>
  <?php menuLink('Configuración', '', 'configuracion', $current); ?>
  <?php menuLink('Facturas', '', 'facturas', $current); ?>
  <?php menuLink('Corte', '', 'corte', $current); ?>
  <?php menuLink('Reportes', '', 'reportes', $current); ?>
</nav>

