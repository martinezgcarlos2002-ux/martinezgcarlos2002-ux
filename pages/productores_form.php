<?php
declare(strict_types=1);

/** @var 'crear'|'editar' $modo */
/** @var array<string, string> $productor */
/** @var array<string, string> $errors */
/** @var int|null $id */
$editando = $modo === 'editar';
$action = $editando ? url('productores_editar', ['id' => $id]) : url('productores_nuevo');
layout_start($editando ? 'Editar productor' : 'Nuevo productor', 'productores');
?>
<form method="post" action="<?= e($action) ?>" class="card form" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field span-2">
            <label for="nombre">Nombre *</label>
            <input type="text" id="nombre" name="nombre" maxlength="120" required
                   class="<?= field_class($errors, 'nombre') ?>" value="<?= e($productor['nombre']) ?>">
            <?= field_error($errors, 'nombre') ?>
        </div>
        <div class="field">
            <label for="documentoNum">Cédula / RIF</label>
            <?php input_group_documento([
                'name' => 'documento',
                'id' => 'documento',
                'value' => $productor['documento'],
                'class' => field_class($errors, 'documento'),
                'error_html' => field_error($errors, 'documento'),
            ]); ?>
        </div>
        <div class="field">
            <label for="telefonoNum">Teléfono</label>
            <?php input_group_telefono([
                'name' => 'telefono',
                'id' => 'telefono',
                'value' => $productor['telefono'],
                'class' => field_class($errors, 'telefono'),
                'error_html' => field_error($errors, 'telefono'),
            ]); ?>
        </div>
        <div class="field">
            <label for="correo">Correo</label>
            <input type="email" id="correo" name="correo" maxlength="100"
                   class="<?= field_class($errors, 'correo') ?>" value="<?= e($productor['correo']) ?>">
            <?= field_error($errors, 'correo') ?>
        </div>
        <div class="field">
            <label for="precio_litro_usd">Precio por litro (USD) *</label>
            <input type="text" inputmode="decimal" id="precio_litro_usd" name="precio_litro_usd" required placeholder="0.55"
                   class="<?= field_class($errors, 'precio_litro_usd') ?>" value="<?= e($productor['precio_litro_usd']) ?>">
            <?= field_error($errors, 'precio_litro_usd') ?>
        </div>
        <div class="field">
            <label for="credito_dias">Días de crédito *</label>
            <input type="number" id="credito_dias" name="credito_dias" min="0" max="90" required
                   class="<?= field_class($errors, 'credito_dias') ?>" value="<?= e($productor['credito_dias']) ?>">
            <?= field_error($errors, 'credito_dias') ?>
        </div>
        <div class="field">
            <label for="dia_cierre">Día de cierre de semana *</label>
            <select id="dia_cierre" name="dia_cierre" required class="<?= field_class($errors, 'dia_cierre') ?>">
                <?php foreach (CierreSemanal::DIAS as $n => $dia): ?>
                <option value="<?= $n ?>" <?= (string) $productor['dia_cierre'] === (string) $n ? 'selected' : '' ?>><?= e($dia) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'dia_cierre') ?>
        </div>
        <div class="field">
            <label for="grupo_cierre">Grupo de cierre (etiqueta)</label>
            <input type="text" id="grupo_cierre" name="grupo_cierre" maxlength="50" list="gruposCierreSugeridos"
                   placeholder="Ej. Jueves, Viernes"
                   class="<?= field_class($errors, 'grupo_cierre') ?>" value="<?= e($productor['grupo_cierre'] ?? '') ?>">
            <datalist id="gruposCierreSugeridos">
                <?php foreach (CierreSemanal::DIAS as $dia): ?>
                <option value="<?= e($dia) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <?= field_error($errors, 'grupo_cierre') ?>
        </div>
        <div class="field">
            <label for="tolerancia_pago">Tolerancia de pago *</label>
            <select id="tolerancia_pago" name="tolerancia_pago" required class="<?= field_class($errors, 'tolerancia_pago') ?>">
                <?php foreach (Productor::TOLERANCIAS as $clave => $dias): ?>
                <option value="<?= e($clave) ?>" <?= (string) $productor['tolerancia_pago'] === $clave ? 'selected' : '' ?>><?= e(ucfirst($clave) . " ($dias días)") ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'tolerancia_pago') ?>
        </div>
        <div class="field span-2">
            <label for="direccion">Dirección</label>
            <input type="text" id="direccion" name="direccion" maxlength="160"
                   class="<?= field_class($errors, 'direccion') ?>" value="<?= e($productor['direccion']) ?>">
            <?= field_error($errors, 'direccion') ?>
        </div>
        <?php if ($editando): ?>
            <div class="field span-2">
                <label for="motivo_precio">Motivo del cambio de precio (si aplica)</label>
                <input type="text" id="motivo_precio" name="motivo_precio" maxlength="160"
                       value="<?= e($productor['motivo_precio']) ?>">
            </div>
            <div class="field">
                <label class="check">
                    <input type="checkbox" name="activo" value="1" <?= $productor['activo'] === '1' ? 'checked' : '' ?>>
                    Productor activo
                </label>
            </div>
        <?php endif; ?>
    </div>
    <div class="form-actions">
        <a class="btn btn-light" href="<?= e(url('productores')) ?>">Cancelar</a>
        <button type="submit" class="btn"><?= $editando ? 'Guardar cambios' : 'Registrar productor' ?></button>
    </div>
</form>
<?php
layout_end();
