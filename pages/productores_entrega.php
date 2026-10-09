<?php
declare(strict_types=1);

/** @var list<array{id:int, nombre:string, precio_litro_usd:string}> $productores */
/** @var list<array> $entregasDia */
/** @var array{entregas:int, litros:string, subtotal_usd:string} $totales */
/** @var string $fecha */
/** @var string $hoy */
/** @var array<string, string> $old */
/** @var array<string, string> $errors */
$yaEntregaron = array_flip(array_map(static fn (array $e) => (string) $e['productor_id'], $entregasDia));
layout_start('Registrar entrega de leche', 'productores');
?>
<div class="grid grid-entrega">
    <form method="post" action="<?= e(url('productores_entrega')) ?>" class="card form" novalidate>
        <?= csrf_field() ?>
        <h2>Nueva entrega</h2>

        <label for="fecha">Fecha *</label>
        <input type="date" id="fecha" name="fecha" max="<?= e($hoy) ?>" required
               class="<?= field_class($errors, 'fecha') ?>" value="<?= e($fecha) ?>">
        <?= field_error($errors, 'fecha') ?>

        <label for="productor_id">Productor *</label>
        <select id="productor_id" name="productor_id" required class="<?= field_class($errors, 'productor_id') ?>">
            <option value="">— Selecciona —</option>
            <?php foreach ($productores as $p): ?>
                <?php $ya = isset($yaEntregaron[(string) $p['id']]); ?>
                <option value="<?= (int) $p['id'] ?>" <?= $ya ? 'disabled' : '' ?>
                    <?= $old['productor_id'] === (string) $p['id'] ? 'selected' : '' ?>>
                    <?= e($p['nombre']) ?> · <?= e(num($p['precio_litro_usd'], 4)) ?> USD/L<?= $ya ? ' (ya entregó)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'productor_id') ?>

        <label for="litros">Litros *</label>
        <input type="text" inputmode="decimal" id="litros" name="litros" required placeholder="0.000"
               class="<?= field_class($errors, 'litros') ?>" value="<?= e($old['litros']) ?>">
        <?= field_error($errors, 'litros') ?>

        <div class="form-grid">
            <div class="field">
                <label for="traslado_usd">Traslado (USD)</label>
                <input type="text" inputmode="decimal" id="traslado_usd" name="traslado_usd" placeholder="0.00"
                       class="<?= field_class($errors, 'traslado_usd') ?>" value="<?= e($old['traslado_usd']) ?>">
                <?= field_error($errors, 'traslado_usd') ?>
            </div>
            <div class="field">
                <label for="cuajada_kg">Cuajada (kg)</label>
                <input type="text" inputmode="decimal" id="cuajada_kg" name="cuajada_kg" placeholder="0.000"
                       class="<?= field_class($errors, 'cuajada_kg') ?>" value="<?= e($old['cuajada_kg']) ?>">
                <?= field_error($errors, 'cuajada_kg') ?>
            </div>
        </div>

        <label for="observaciones">Observaciones</label>
        <input type="text" id="observaciones" name="observaciones" maxlength="255" value="<?= e($old['observaciones']) ?>">

        <p class="muted">El precio por litro se toma del productor al momento de guardar y queda congelado en la entrega.</p>
        <button type="submit" class="btn btn-block">Registrar entrega</button>
    </form>

    <section class="card table-wrap">
        <div class="toolbar">
            <h2>Entregas del <?= e(date('d/m/Y', strtotime($fecha))) ?></h2>
            <form method="get" action="<?= e(base_path() . 'index.php') ?>" class="filters">
                <input type="hidden" name="p" value="productores_entrega">
                <input type="date" name="fecha" max="<?= e($hoy) ?>" value="<?= e($fecha) ?>">
                <button type="submit" class="btn btn-light">Ver día</button>
            </form>
        </div>
        <table class="table">
            <thead>
                <tr>
                    <th>Productor</th>
                    <th class="num">Litros</th>
                    <th class="num">USD/L</th>
                    <th class="num">Subtotal USD</th>
                    <th class="num">Traslado</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($entregasDia === []): ?>
                <tr><td colspan="5" class="muted center">Sin entregas registradas en esta fecha.</td></tr>
            <?php endif; ?>
            <?php foreach ($entregasDia as $en): ?>
                <tr>
                    <td><?= e($en['nombre']) ?></td>
                    <td class="num"><?= e(num($en['litros'], 3)) ?></td>
                    <td class="num"><?= e(num($en['precio_litro_usd'], 4)) ?></td>
                    <td class="num"><?= e(num($en['subtotal_usd'])) ?></td>
                    <td class="num"><?= e(num($en['traslado_usd'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th><?= (int) $totales['entregas'] ?> entrega(s)</th>
                    <th class="num"><?= e(num($totales['litros'], 3)) ?></th>
                    <th></th>
                    <th class="num"><?= e(num($totales['subtotal_usd'])) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </section>
</div>
<?php
layout_end();
