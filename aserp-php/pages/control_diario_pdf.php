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
$fecha_visible = date('d/m/Y');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Control Diario de Leche - PDF</title>
<style>
  /* Página de impresión: Oficio (Legal) vertical, márgenes mínimos */
  @page { size: legal portrait; margin: 5mm; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; color: #000; }
  body { padding: 6mm; }
  .toolbar { margin-bottom: 8px; }
  .toolbar button { margin-right: 6px; padding: 6px 14px; cursor: pointer; font-size: 12px; }
  /* #sheet es el contenedor que se escala automáticamente para que entre en UNA hoja */
  #sheet { transform-origin: top left; }
  table { border-collapse: collapse; width: 100%; table-layout: fixed; page-break-inside: avoid !important; break-inside: avoid !important; }
  tr, td, th { page-break-inside: avoid !important; break-inside: avoid !important; }
  td, th { border: 1px solid #000; padding: 2px 4px; font-size: 8.5pt; vertical-align: middle; line-height: 1.15; }
  col.c-n { width: 6%; }
  col.c-nom { width: 44%; }
  col.c-num { width: 11%; }
  col.c-sec { width: 17%; }
  .titulo { text-align: center; font-weight: bold; font-size: 12pt; }
  .fecha { text-align: center; font-weight: bold; font-size: 9.5pt; }
  .hd-nombre { background: #22c55e; color: #fff; text-align: center; font-weight: bold; }
  .hd { text-align: center; font-weight: bold; background: #f5f5f5; }
  .n { text-align: center; font-weight: bold; }
  .nombre { font-weight: bold; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .sec { text-align: center; font-weight: bold; vertical-align: middle; font-size: 8pt; }
  @media print {
    .toolbar { display: none !important; }
    body { padding: 0; margin: 0; }
    html, body { width: 100%; height: 100%; overflow: hidden; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button onclick="doPrint()" style="background:#0d6efd;color:#fff;border:0;border-radius:4px">Imprimir</button>
  <button onclick="descargarPDF()" style="background:#dc3545;color:#fff;border:0;border-radius:4px">Descargar PDF</button>
  <button onclick="window.history.back()">Volver</button>
  <span style="margin-left:10px;font-size:11px;color:#555">La hoja se ajusta autom&aacute;ticamente para entrar en una sola p&aacute;gina.</span>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<div id="sheet">
<table>
  <colgroup>
    <col class="c-n"><col class="c-nom"><col class="c-num"><col class="c-num"><col class="c-num"><col class="c-sec">
  </colgroup>
  <tr>
    <td colspan="2" style="text-align:center;font-weight:bold">EL CASTILLO DEL QUESO</td>
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
</div><!-- /#sheet -->
<script>
(function(){
  // Tamaño útil de una hoja Oficio (Legal) vertical con margen 5mm.
  // Legal = 8.5 x 14 in => 215.9mm x 355.6mm. Útil ≈ 205.9 x 345.6 mm.
  // A 96 DPI: 1mm ≈ 3.7795 px. Tomamos un margen de seguridad.
  var PAGE_W_PX = Math.round(205 * 3.7795); // ~775 px
  var PAGE_H_PX = Math.round(340 * 3.7795); // ~1285 px

  function ajustarUnaHoja(){
    var sheet = document.getElementById('sheet');
    if (!sheet) return;
    // Reset previo
    sheet.style.transform = '';
    sheet.style.width = '';
    var w = sheet.scrollWidth;
    var h = sheet.scrollHeight;
    if (w <= 0 || h <= 0) return;
    var escala = Math.min(PAGE_W_PX / w, PAGE_H_PX / h, 1);
    // Forzamos a achicar siempre que no entre (sin agrandar más de 1x)
    if (escala < 1) {
      sheet.style.transform = 'scale(' + escala + ')';
      // Compensar el ancho para que no quede una franja blanca a la derecha
      sheet.style.width = (100 / escala) + '%';
    }
  }

  function doPrint(){
    ajustarUnaHoja();
    setTimeout(function(){ window.print(); }, 80);
  }
  window.doPrint = doPrint;

  // Descarga directa de PDF usando html2pdf (sin pasar por el diálogo de impresión)
  function descargarPDF(){
    var sheet = document.getElementById('sheet');
    if (!sheet) return;
    if (typeof html2pdf === 'undefined') {
      alert('No se pudo cargar el generador de PDF. Verifica tu conexi\u00f3n a internet e intenta de nuevo.');
      return;
    }
    // Quitar escalado CSS para que html2pdf mida bien y luego escale él mismo
    var prevTransform = sheet.style.transform;
    var prevWidth = sheet.style.width;
    sheet.style.transform = '';
    sheet.style.width = '';

    var hoy = new Date();
    var dd = String(hoy.getDate()).padStart(2,'0');
    var mm = String(hoy.getMonth()+1).padStart(2,'0');
    var yy = hoy.getFullYear();
    var nombreArchivo = 'control_diario_leche_' + yy + '-' + mm + '-' + dd + '.pdf';

    var opt = {
      margin:       [5, 5, 5, 5], // mm
      filename:     nombreArchivo,
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
      jsPDF:        { unit: 'mm', format: 'legal', orientation: 'portrait' },
      pagebreak:    { mode: ['avoid-all'] }
    };

    html2pdf().set(opt).from(sheet).save().then(function(){
      sheet.style.transform = prevTransform;
      sheet.style.width = prevWidth;
    }).catch(function(err){
      sheet.style.transform = prevTransform;
      sheet.style.width = prevWidth;
      alert('Error al generar el PDF: ' + err);
    });
  }
  window.descargarPDF = descargarPDF;

  window.addEventListener('load', function(){
    ajustarUnaHoja();
    // Reajustar antes de cada impresión (por si el usuario cambia margen/tamaño)
    if (window.matchMedia) {
      var mq = window.matchMedia('print');
      if (mq.addEventListener) mq.addEventListener('change', function(){ ajustarUnaHoja(); });
    }
    window.addEventListener('beforeprint', ajustarUnaHoja);
  });
  window.addEventListener('resize', ajustarUnaHoja);
})();
</script>
</body>
</html>
