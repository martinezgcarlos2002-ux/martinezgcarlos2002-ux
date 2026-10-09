<?php
require_login();
$db = get_db();

$rows = $db->query("SELECT id, nombre, estado FROM productores ORDER BY nombre ASC")->fetchAll();
$prods = array_values(array_filter($rows, fn($r) => !isset($r['estado']) || bool_estado($r['estado'])));
$total = count($prods);

$labels_72 = [
    1 => 'VENTAS', 5 => 'CORTESIA', 10 => 'VALES',
    15 => 'TOTAL FALTANTE', 21 => 'TOTAL', 35 => 'SELLO', 44 => 'FIRMA',
];
$labels = [];
if ($total > 0) {
    foreach ($labels_72 as $row => $lbl) {
        $r = max(1, min($total, (int)round($row * $total / 72)));
        while (isset($labels[$r]) && $r < $total) $r++;
        $labels[$r] = $lbl;
    }
}
$section_at = [];
if ($total > 0) {
    $starts = array_keys($labels); sort($starts);
    if (empty($starts) || $starts[0] !== 1) array_unshift($starts, 1);
    for ($k = 0; $k < count($starts); $k++) {
        $s = $starts[$k];
        $next = $starts[$k + 1] ?? ($total + 1);
        $section_at[$s] = [$next - $s, $labels[$s] ?? ''];
    }
}

$fecha_archivo = date('Y-m-d');
$fecha_visible = date('d/m/Y');

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="control_diario_leche_' . $fecha_archivo . '.xls"');
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF"; // BOM UTF-8 para que Excel respete acentos
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<!--[if gte mso 9]>
<xml>
  <x:ExcelWorkbook>
    <x:ExcelWorksheets>
      <x:ExcelWorksheet>
        <x:Name>Control Diario</x:Name>
        <x:WorksheetOptions>
          <x:Print>
            <x:FitWidth>1</x:FitWidth>
            <x:FitHeight>1</x:FitHeight>
            <x:ValidPrinterInfo/>
            <x:PaperSizeIndex>5</x:PaperSizeIndex>
            <x:HorizontalResolution>600</x:HorizontalResolution>
            <x:VerticalResolution>600</x:VerticalResolution>
          </x:Print>
          <x:PageSetup>
            <x:Layout x:CenterHorizontal="1" x:Orientation="Portrait"/>
            <x:PageMargins x:Bottom="0.3" x:Left="0.3" x:Right="0.3" x:Top="0.3"/>
          </x:PageSetup>
          <x:FitToPage/>
          <x:DoNotDisplayGridlines/>
        </x:WorksheetOptions>
      </x:ExcelWorksheet>
    </x:ExcelWorksheets>
  </x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
  @page { size: legal portrait; margin: 8mm; mso-page-orientation: portrait; mso-fit-to-page: yes; }
  table { border-collapse: collapse; page-break-inside: avoid; }
  td, th { border: 1px solid #000; padding: 4px 6px; font-family: Arial, sans-serif; font-size: 9pt; vertical-align: middle; mso-number-format:"\@"; }
  .titulo { text-align: center; font-weight: bold; font-size: 14pt; background:#ffffff; }
  .fecha { text-align: center; font-weight: bold; }
  .hd-nombre { background: #22c55e; color: #ffffff; text-align: center; font-weight: bold; }
  .hd { text-align: center; font-weight: bold; background: #f5f5f5; }
  .n { text-align: center; font-weight: bold; }
  .nombre { font-weight: bold; text-transform: uppercase; }
  .sec { text-align: center; font-weight: bold; vertical-align: middle; }
</style>
</head>
<body>
<table>
  <tr>
    <td colspan="2" style="text-align:center;">EL CASTILLO DEL QUESO</td>
    <td colspan="3" class="titulo">CONTROL DIARIO DE LECHE</td>
    <td class="fecha"><?= htmlspecialchars($fecha_visible) ?></td>
  </tr>
  <tr>
    <th class="hd">N</th>
    <th class="hd-nombre">NOMBRE Y APELLIDO</th>
    <th class="hd">LITROS</th>
    <th class="hd">DENSIDAD</th>
    <th class="hd">FALTANTE</th>
    <th class="hd"></th>
  </tr>
<?php if (!$total): ?>
  <tr><td colspan="6" style="text-align:center;padding:20px">Sin productores activos.</td></tr>
<?php else: foreach ($prods as $i => $p):
    $n = $i + 1;
    $sec = $section_at[$n] ?? null;
?>
  <tr>
    <td class="n"><?= $n ?></td>
    <td class="nombre"><?= htmlspecialchars(strtoupper($p['nombre'])) ?></td>
    <td></td><td></td><td></td>
    <?php if ($sec !== null): ?>
      <td class="sec" rowspan="<?= (int)$sec[0] ?>"><?= htmlspecialchars($sec[1]) ?></td>
    <?php endif ?>
  </tr>
<?php endforeach; endif ?>
</table>
</body>
</html>
