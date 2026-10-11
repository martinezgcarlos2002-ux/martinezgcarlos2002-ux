<?php
declare(strict_types=1);

/**
 * Planilla física ASERP: productores a la izquierda, resumen global a la derecha.
 *
 * @var string $fechaHoja
 * @var list<array{n:int, nombre:string, litros:mixed, densidad:string, faltante:string}> $filasHoja
 * @var array<int, array{0:int, 1:string}> $seccionesHoja  fila(1-based) => [span, label]
 * @var array<string, string> $mermaHoja
 * @var float $totalRecibido
 * @var float $totalNeto
 * @var bool $puedeEditarHoja
 */
$mermaHoja = $mermaHoja ?? [];
$totalNeto = $totalNeto ?? max(0, (float) ($totalRecibido ?? 0)
    - (float) ($mermaHoja['ventas_leche'] ?? 0)
    - (float) ($mermaHoja['cortesias_leche'] ?? 0)
    - (float) ($mermaHoja['vales_leche'] ?? 0)
    - (float) ($mermaHoja['leche_faltante'] ?? 0));
$camposMerma = [
    'VENTAS'          => 'ventas_leche',
    'CORTESIA'        => 'cortesias_leche',
    'VALES'           => 'vales_leche',
    'TOTAL FALTANTE'  => 'leche_faltante',
];
$celda = static function (mixed $valor, int $dec = 1): string {
    if ($valor === null || $valor === '' || (is_numeric($valor) && (float) $valor == 0.0)) {
        return '';
    }
    return is_numeric($valor) ? number_format((float) $valor, $dec, '.', '') : (string) $valor;
};
?>
<div class="cd-preview mb-3">
  <div id="cdSheet" class="cd-sheet">
    <table id="tabla-control-leche">
      <colgroup>
        <col class="col-n"><col class="col-name">
        <col class="col-num"><col class="col-num"><col class="col-num">
        <col class="col-right">
      </colgroup>
      <thead class="cd-head">
        <tr>
          <td class="cd-logo" colspan="2">
            <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="El Castillo del Queso">
          </td>
          <td colspan="3" class="cd-title">CONTROL DIARIO DE LECHE</td>
          <td class="cd-date"><?= e((new DateTimeImmutable($fechaHoja))->format('d/m/Y')) ?></td>
        </tr>
        <tr>
          <th>N</th>
          <th class="cd-col-name-head">NOMBRE Y APELLIDO</th>
          <th>LITROS</th>
          <th>DENSIDAD</th>
          <th>FALTANTE</th>
          <th></th>
        </tr>
      </thead>
      <tbody class="cd-body">
      <?php if ($filasHoja === []): ?>
        <tr><td colspan="6" class="text-center py-4">Sin productores activos.</td></tr>
      <?php endif; ?>
      <?php foreach ($filasHoja as $fila):
          $n = (int) $fila['n'];
          $sec = $seccionesHoja[$n] ?? null;
      ?>
        <tr>
          <td class="cd-num-n"><?= $n ?></td>
          <td class="cd-name"><?= e(mb_strtoupper((string) $fila['nombre'])) ?></td>
          <?php $productorId = (int) ($fila['productor_id'] ?? 0); ?>
          <td class="cd-blank">
            <input class="cd-inp" type="number" inputmode="decimal" min="0" step="0.001"
                   <?= $puedeEditarHoja && $productorId > 0 ? 'name="litros[' . $productorId . ']"' : 'readonly tabindex="-1"' ?>
                   value="<?= e($celda($fila['litros'], 3)) ?>" aria-label="Litros <?= e($fila['nombre']) ?>">
          </td>
          <td class="cd-blank">
            <input class="cd-inp" type="number" inputmode="decimal" min="0" step="0.001"
                   <?= $productorId > 0 ? 'name="densidad[' . $productorId . ']"' : 'readonly tabindex="-1"' ?>
                   value="<?= e($celda($fila['densidad'], 3)) ?>"
                   aria-label="Densidad <?= e($fila['nombre']) ?>">
          </td>
          <td class="cd-blank">
            <input class="cd-inp cd-faltante" type="number" inputmode="decimal" min="0" step="0.01"
                   <?= $productorId > 0 ? 'name="faltante[' . $productorId . ']"' : 'readonly tabindex="-1"' ?>
                   value="<?= e($celda($fila['faltante'], 2)) ?>"
                   aria-label="Faltante <?= e($fila['nombre']) ?>">
          </td>
          <?php if ($sec !== null):
              $lbl = $sec[1];
              $campo = $camposMerma[$lbl] ?? null;
          ?>
            <td class="cd-right-section" rowspan="<?= (int) $sec[0] ?>">
              <span class="cd-right-lbl"><?= e($lbl) ?></span>
              <?php if ($campo !== null): ?>
                <?php $soloLectura = !$puedeEditarHoja || $lbl === 'TOTAL FALTANTE'; ?>
                <input class="cd-inp cd-inp-block<?= $lbl === 'TOTAL FALTANTE' ? ' cd-total-faltante' : '' ?>" type="number" inputmode="decimal" min="0" step="0.01"
                       name="<?= e($campo) ?>" <?= $soloLectura ? 'readonly tabindex="-1"' : '' ?>
                       value="<?= e($celda($mermaHoja[$campo] ?? '', 2)) ?>" aria-label="<?= e($lbl) ?>">
              <?php elseif ($lbl === 'TOTAL'): ?>
                <input class="cd-inp cd-inp-block cd-total-neto" type="text" readonly tabindex="-1"
                       value="<?= e(num($totalNeto, 1)) ?>" aria-label="Total neto de leche">
              <?php elseif ($lbl === 'SELLO'): ?>
                <div class="cd-sello" aria-label="Sello"></div>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var tabla = document.getElementById('tabla-control-leche');
  if (!tabla) return;
  var totalFaltante = tabla.querySelector('.cd-total-faltante');
  var totalNeto = tabla.querySelector('.cd-total-neto');

  function leer(el) {
    if (!el) return 0;
    var n = parseFloat(String(el.value).replace(',', '.'));
    return isFinite(n) ? n : 0;
  }
  function campo(nombre) {
    return leer(tabla.querySelector('input[name="' + nombre + '"]'));
  }
  function formato(n) {
    return n.toLocaleString('es-VE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  }
  function sumarFaltantes() {
    if (!totalFaltante) return;
    var suma = 0;
    tabla.querySelectorAll('.cd-faltante').forEach(function (inp) {
      var n = leer(inp);
      if (n > 0) suma += n;
    });
    totalFaltante.value = suma > 0 ? String(Math.round(suma * 100) / 100) : '';
  }
  function recalcularNeto() {
    sumarFaltantes();
    if (!totalNeto) return;
    var litros = 0;
    tabla.querySelectorAll('input[name^="litros["]').forEach(function (inp) {
      var n = leer(inp);
      if (n > 0) litros += n;
    });
    var neto = litros - campo('ventas_leche') - campo('cortesias_leche') - campo('vales_leche') - campo('leche_faltante');
    totalNeto.value = formato(Math.max(0, neto));
  }
  tabla.addEventListener('input', recalcularNeto);
  recalcularNeto();
})();
</script>
