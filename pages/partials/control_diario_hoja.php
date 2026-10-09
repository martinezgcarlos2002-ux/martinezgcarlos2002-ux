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
 * @var bool $puedeEditarHoja
 */
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
    <table>
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
          <td class="cd-blank">
            <input class="cd-inp" type="number" inputmode="decimal" min="0" step="0.001" readonly tabindex="-1"
                   value="<?= e($celda($fila['litros'], 3)) ?>" aria-label="Litros <?= e($fila['nombre']) ?>">
          </td>
          <td class="cd-blank">
            <input class="cd-inp" type="number" inputmode="decimal" min="0" step="0.001"
                   name="densidad[<?= $n ?>]" value="<?= e($celda($fila['densidad'], 3)) ?>"
                   aria-label="Densidad <?= e($fila['nombre']) ?>">
          </td>
          <td class="cd-blank">
            <input class="cd-inp" type="number" inputmode="decimal" min="0" step="0.01"
                   name="faltante_fila[<?= $n ?>]" value="<?= e($celda($fila['faltante'], 2)) ?>"
                   aria-label="Faltante <?= e($fila['nombre']) ?>">
          </td>
          <?php if ($sec !== null):
              $lbl = $sec[1];
              $campo = $camposMerma[$lbl] ?? null;
          ?>
            <td class="cd-right-section" rowspan="<?= (int) $sec[0] ?>">
              <span class="cd-right-lbl"><?= e($lbl) ?></span>
              <?php if ($campo !== null): ?>
                <input class="cd-inp cd-inp-block" type="number" inputmode="decimal" min="0" step="0.01"
                       name="<?= e($campo) ?>" <?= $puedeEditarHoja ? '' : 'readonly tabindex="-1"' ?>
                       value="<?= e($celda($mermaHoja[$campo] ?? '', 2)) ?>" aria-label="<?= e($lbl) ?>">
              <?php elseif ($lbl === 'TOTAL'): ?>
                <input class="cd-inp cd-inp-block" type="text" readonly tabindex="-1"
                       value="<?= e(num($totalRecibido, 1)) ?>" aria-label="Total litros recibidos">
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
