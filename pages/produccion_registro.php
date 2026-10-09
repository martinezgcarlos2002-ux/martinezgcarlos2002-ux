<?php
declare(strict_types=1);

/** @var list<array> $productos */
/** @var list<array> $recientes */
/** @var string|null $precioPromHoy */
/** @var bool $puedeCrear */
/** @var string $hoy */
/** @var array<string, string> $old */
/** @var array<string, string> $errors */
layout_start('Producción de queso', 'produccion');
?>
<div class="row g-3">
    <?php if ($puedeCrear): ?>
    <div class="col-lg-5">
        <form method="post" action="<?= e(url('produccion_registro')) ?>" class="card p-3" id="form-produccion" novalidate>
            <?= csrf_field() ?>
            <h2 class="h5 mb-3">Registrar producción</h2>

            <div class="row g-2">
                <div class="col-sm-6">
                    <label for="fecha" class="form-label">Fecha *</label>
                    <input type="date" id="fecha" name="fecha" max="<?= e($hoy) ?>" required
                           class="form-control <?= field_class($errors, 'fecha') ?>" value="<?= e($old['fecha']) ?>">
                    <?= field_error($errors, 'fecha') ?>
                </div>
                <div class="col-sm-6">
                    <label for="producto_id" class="form-label">Tipo de queso *</label>
                    <select id="producto_id" name="producto_id" required
                            class="form-select <?= field_class($errors, 'producto_id') ?>">
                        <option value="">— Selecciona —</option>
                        <?php foreach ($productos as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"
                                    data-base="<?= e($p['base']) ?>"
                                    data-factor="<?= e($p['factor_l_kg_ref'] ?? '') ?>"
                                    data-cuajada-ref="<?= e($p['cuajada_ref_kg'] ?? '') ?>"
                                    data-masa-min="<?= e($p['masa_min_kg'] ?? '') ?>"
                                    data-masa-max="<?= e($p['masa_max_kg'] ?? '') ?>"
                                    <?= $old['producto_id'] === (string) $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($errors, 'producto_id') ?>
                </div>

                <div class="col-sm-6">
                    <label for="litros_leche" class="form-label">Litros de leche *</label>
                    <input type="text" inputmode="decimal" id="litros_leche" name="litros_leche" required placeholder="0.000"
                           class="form-control <?= field_class($errors, 'litros_leche') ?>" value="<?= e($old['litros_leche']) ?>">
                    <?= field_error($errors, 'litros_leche') ?>
                </div>
                <div class="col-sm-6">
                    <label for="factor_l_kg" class="form-label">Factor (litros por kg) *</label>
                    <input type="text" inputmode="decimal" id="factor_l_kg" name="factor_l_kg" required placeholder="8"
                           class="form-control <?= field_class($errors, 'factor_l_kg') ?>" value="<?= e($old['factor_l_kg']) ?>">
                    <?= field_error($errors, 'factor_l_kg') ?>
                </div>

                <div class="col-12">
                    <label for="kg_esperados" class="form-label" id="label-esperados">Kg esperados</label>
                    <input type="text" id="kg_esperados" class="form-control" readonly tabindex="-1" placeholder="Litros ÷ factor">
                    <div class="form-text">Se calcula como litros ÷ factor. El valor definitivo lo calcula el servidor.</div>
                </div>
            </div>

            <fieldset id="bloque-cuajada" class="border rounded p-2 mt-3" hidden>
                <legend class="float-none w-auto px-2 fs-6 mb-0">Formulación de masa (cuajada + suero)</legend>
                <p class="small text-muted mb-2" id="nota-formula"></p>
                <label for="cuajada_kg" class="form-label">Cuajada utilizada (kg) *</label>
                <input type="text" inputmode="decimal" id="cuajada_kg" name="cuajada_kg" placeholder="0.000"
                       class="form-control <?= field_class($errors, 'cuajada_kg') ?>" value="<?= e($old['cuajada_kg']) ?>">
                <?= field_error($errors, 'cuajada_kg') ?>
                <div class="mt-2">Masa esperada: <strong id="masa-rango">—</strong></div>
            </fieldset>

            <div class="mt-3">
                <label for="kg_reales" class="form-label" id="label-reales">Kg reales obtenidos</label>
                <input type="text" inputmode="decimal" id="kg_reales" name="kg_reales" placeholder="Opcional"
                       class="form-control <?= field_class($errors, 'kg_reales') ?>" value="<?= e($old['kg_reales']) ?>">
                <?= field_error($errors, 'kg_reales') ?>
                <div class="mt-1" id="rendimiento"></div>
            </div>

            <div class="mt-3">
                <label for="observaciones" class="form-label">Observaciones</label>
                <input type="text" id="observaciones" name="observaciones" maxlength="255"
                       class="form-control" value="<?= e($old['observaciones']) ?>">
            </div>

            <p class="small text-muted mt-3 mb-2">
                Costo de leche: litros × precio promedio ponderado recibido en la fecha
                <?= $precioPromHoy !== null ? '(hoy: ' . e(num($precioPromHoy, 4)) . ' USD/L)' : '(hoy aún no hay entregas)' ?>.
            </p>
            <button type="submit" class="btn btn-primary w-100">Registrar producción</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="<?= $puedeCrear ? 'col-lg-7' : 'col-12' ?>">
        <section class="card p-3 table-wrap">
            <h2 class="h5 mb-3">Producciones recientes</h2>
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Queso</th>
                        <th class="num">Litros</th>
                        <th class="num">Esperado</th>
                        <th class="num">Real</th>
                        <th class="num">Rend.</th>
                        <th class="num">Costo leche</th>
                        <th class="num">USD/kg</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($recientes === []): ?>
                    <tr><td colspan="8" class="text-muted text-center">Aún no hay producciones registradas.</td></tr>
                <?php endif; ?>
                <?php foreach ($recientes as $r): ?>
                    <?php
                    $esMasa = $r['cuajada_kg'] !== null;
                    $esperado = $esMasa
                        ? num($r['masa_min_kg'], 3) . ' – ' . num($r['masa_max_kg'], 3)
                        : num($r['kg_esperados'], 3);
                    $rend = '—';
                    $rendClass = '';
                    if ($r['kg_reales'] !== null) {
                        if ($esMasa) {
                            $ok = (float) $r['kg_reales'] >= (float) $r['masa_min_kg'] && (float) $r['kg_reales'] <= (float) $r['masa_max_kg'];
                            $rend = $ok ? 'En rango' : 'Fuera';
                            $rendClass = $ok ? 'text-success' : 'text-danger';
                        } elseif ((float) $r['kg_esperados'] > 0) {
                            $rend = num((float) $r['kg_reales'] / (float) $r['kg_esperados'] * 100, 1) . ' %';
                        }
                    }
                    ?>
                    <tr>
                        <td><?= e(date('d/m/Y', strtotime($r['fecha']))) ?></td>
                        <td><?= e($r['producto']) ?><?= $esMasa ? '<div class="small text-muted">' . e(num($r['cuajada_kg'], 3)) . ' kg cuajada</div>' : '' ?></td>
                        <td class="num"><?= e(num($r['litros_leche'], 3)) ?></td>
                        <td class="num"><?= e($esperado) ?></td>
                        <td class="num"><?= $r['kg_reales'] !== null ? e(num($r['kg_reales'], 3)) : '—' ?></td>
                        <td class="num <?= $rendClass ?>"><?= e($rend) ?></td>
                        <td class="num"><?= $r['costo_leche_usd'] !== null ? e(num($r['costo_leche_usd'])) : '—' ?></td>
                        <td class="num"><?= $r['costo_kg_usd'] !== null ? e(num($r['costo_kg_usd'], 4)) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(() => {
    const form = document.getElementById('form-produccion');
    if (!form) return;

    const $ = (id) => document.getElementById(id);
    const producto = $('producto_id'), litros = $('litros_leche'), factor = $('factor_l_kg');
    const cuajada = $('cuajada_kg'), reales = $('kg_reales'), esperados = $('kg_esperados');
    const bloque = $('bloque-cuajada'), rango = $('masa-rango'), nota = $('nota-formula');
    const rendimiento = $('rendimiento');

    const parse = (el) => {
        const v = parseFloat(String(el.value).trim().replace(',', '.'));
        return Number.isFinite(v) ? v : null;
    };
    const fmt = (v, d = 3) => v.toLocaleString('es-VE', { minimumFractionDigits: d, maximumFractionDigits: d });

    function formula() {
        const o = producto.selectedOptions[0];
        if (!o || !o.value) return null;
        return {
            base: o.dataset.base,
            factor: o.dataset.factor,
            cuajadaRef: parseFloat(o.dataset.cuajadaRef),
            masaMin: parseFloat(o.dataset.masaMin),
            masaMax: parseFloat(o.dataset.masaMax),
        };
    }

    function recalcular() {
        const f = formula();
        const l = parse(litros), k = parse(factor);
        const kgEsp = l !== null && k !== null && k > 0 ? l / k : null;
        const esCuajada = f !== null && f.base === 'cuajada';

        bloque.hidden = !esCuajada;
        cuajada.required = esCuajada;
        $('label-esperados').textContent = esCuajada ? 'Kg de cuajada esperados' : 'Kg esperados';
        $('label-reales').textContent = esCuajada ? 'Kg reales de masa obtenidos' : 'Kg reales obtenidos';
        esperados.value = kgEsp !== null ? fmt(kgEsp) + ' kg' : '';

        let min = null, max = null;
        if (esCuajada) {
            nota.textContent = `${fmt(f.cuajadaRef)} kg de cuajada absorben suero y rinden entre ${fmt(f.masaMin)} y ${fmt(f.masaMax)} kg de masa.`;
            cuajada.placeholder = kgEsp !== null ? kgEsp.toFixed(3) : '0.000';
            const c = parse(cuajada);
            if (c !== null && c > 0) {
                min = c * f.masaMin / f.cuajadaRef;
                max = c * f.masaMax / f.cuajadaRef;
                rango.textContent = `${fmt(min)} – ${fmt(max)} kg`;
            } else {
                rango.textContent = '—';
            }
        }

        const r = parse(reales);
        rendimiento.className = 'small mt-1';
        rendimiento.textContent = '';
        if (r === null) return;

        if (esCuajada && min !== null) {
            const ok = r >= min && r <= max;
            rendimiento.classList.add(ok ? 'text-success' : 'text-danger');
            rendimiento.textContent = ok ? 'Dentro del rango técnico de la formulación.' : 'Fuera del rango técnico de la formulación.';
        } else if (!esCuajada && kgEsp) {
            rendimiento.textContent = `Rendimiento: ${fmt(r / kgEsp * 100, 1)} % del esperado.`;
        }
    }

    producto.addEventListener('change', () => {
        const f = formula();
        if (f && f.factor && factor.value === '') factor.value = f.factor;
    });
    [producto, litros, factor, cuajada, reales].forEach((el) => {
        el.addEventListener('input', recalcular);
        el.addEventListener('change', recalcular);
    });
    recalcular();
})();
</script>
<?php
layout_end();
