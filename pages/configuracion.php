<?php
declare(strict_types=1);

/**
 * @var array{nombre_empresa:string,rif_documento:string,telefono:string,direccion:string,color_primario:string,color_secundario:string,ruta_logo:string,ruta_sello:string,fondo_login:string} $marca
 * @var array<string, string> $errores
 */
$marca = $marca ?? marca();
$errores = $errores ?? [];
$err = static function (string $campo) use ($errores): string {
    return isset($errores[$campo]) ? '<div class="invalid-feedback d-block">' . e($errores[$campo]) . '</div>' : '';
};
$logoUrl = $marca['ruta_logo'] !== '' ? asset_v($marca['ruta_logo']) : '';
$selloUrl = $marca['ruta_sello'] !== '' ? asset_v($marca['ruta_sello']) : '';
$fondoUrl = ($marca['fondo_login'] ?? '') !== '' ? asset_v($marca['fondo_login']) : '';

layout_start('Marca de la empresa', 'configuracion');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
  <div>
    <h5 class="mb-1">Marca de la empresa</h5>
    <p class="text-muted small mb-0">El nombre, el RIF, el teléfono, la dirección y el logo salen en el menú y en facturas, liquidaciones, reportes y transporte. El sello se imprime en esos documentos. El fondo reemplaza la imagen del inicio de sesión de esta empresa.</p>
  </div>
</div>

<form class="card marca-form" method="post" action="<?= e(url('configuracion_guardar')) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="nombre_empresa">Nombre</label>
        <input class="form-control" id="nombre_empresa" name="nombre_empresa" maxlength="120" required value="<?= e($marca['nombre_empresa']) ?>">
        <?= $err('nombre_empresa') ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="rif_documento">RIF</label>
        <input class="form-control" id="rif_documento" name="rif_documento" maxlength="20" required value="<?= e($marca['rif_documento']) ?>">
        <?= $err('rif_documento') ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="telefono">Teléfono</label>
        <input class="form-control" id="telefono" name="telefono" maxlength="30" value="<?= e($marca['telefono']) ?>">
        <?= $err('telefono') ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="direccion">Dirección</label>
        <input class="form-control" id="direccion" name="direccion" maxlength="180" value="<?= e($marca['direccion']) ?>">
        <?= $err('direccion') ?>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="color_primario">Color principal</label>
        <input class="form-control form-control-color marca-color" type="color" id="color_primario" name="color_primario" value="<?= e($marca['color_primario']) ?>">
        <?= $err('color_primario') ?>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="color_secundario">Color secundario</label>
        <input class="form-control form-control-color marca-color" type="color" id="color_secundario" name="color_secundario" value="<?= e($marca['color_secundario']) ?>">
        <?= $err('color_secundario') ?>
        <p class="text-muted small mt-2 mb-0">Al elegir un logo, ADARA propone el color dominante y un tono oscuro de contraste. Puedes ajustarlos antes de guardar.</p>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="logo">Logo</label>
        <input class="form-control" type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp">
        <?= $err('logo') ?>
        <?php if ($logoUrl !== ''): ?>
          <img class="marca-preview" src="<?= e($logoUrl) ?>" alt="Logo actual">
        <?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="fondo">Fondo del login</label>
        <input class="form-control" type="file" id="fondo" name="fondo" accept="image/jpeg,image/png,image/webp">
        <?= $err('fondo') ?>
        <?php if ($fondoUrl !== ''): ?>
          <img class="marca-preview" src="<?= e($fondoUrl) ?>" alt="Fondo de login actual">
        <?php else: ?>
          <p class="text-muted small mt-2 mb-0">Todavía se usa el fondo estándar de ADARA.</p>
        <?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="sello">Sello</label>
        <input class="form-control" type="file" id="sello" name="sello" accept="image/jpeg,image/png,image/webp">
        <?= $err('sello') ?>
        <?php if ($selloUrl !== ''): ?>
          <img class="marca-preview" src="<?= e($selloUrl) ?>" alt="Sello actual">
        <?php else: ?>
          <p class="text-muted small mt-2 mb-0">Todavía no hay sello.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="card-footer bg-white border-top-0 pt-0">
    <button class="btn btn-primary" type="submit">Guardar marca</button>
  </div>
</form>
<script src="<?= e(asset_v('js/marca_colores.js')) ?>"></script>
<?php
layout_end();
