<?php
declare(strict_types=1);

/**
 * @var array{id:int, nombre:string, correo:string} $admin
 * @var 'empresas'|'accesos' $tab
 * @var bool $esPropietario
 * @var list<array<string, mixed>> $clientes
 * @var list<array<string, mixed>> $superAdmins
 * @var array<string, string> $errores
 * @var array<string, string> $form
 * @var array{titulo:string, correo:string, clave:string}|null $claveTemporal
 */
$err = static fn (string $campo): string => isset($errores[$campo])
    ? '<div class="invalid-feedback d-block">' . e($errores[$campo]) . '</div>' : '';
$val = static fn (string $campo): string => e($form[$campo] ?? '');
$activas = count(array_filter($clientes, static fn (array $c): bool => $c['estado_licencia'] === 'activa'));
$fecha = static fn (?string $f): string => $f === null || $f === '' ? '—' : date('d/m/Y H:i', strtotime($f));
layout_master_start('Panel de Empresas', 'master');
?>
  <?php if (isset($errores['general'])): ?>
  <div class="alert alert-danger" role="alert"><?= e($errores['general']) ?></div>
  <?php endif; ?>
  <?php if ($claveTemporal !== null): ?>
  <div class="alert alert-warning border-warning shadow-sm" role="alert" id="claveTemporal">
    <h6 class="alert-heading mb-2"><i class="bi bi-exclamation-triangle-fill"></i> Clave temporal: se muestra una sola vez</h6>
    <div class="small mb-2"><?= e($claveTemporal['titulo']) ?> · <strong><?= e($claveTemporal['correo']) ?></strong></div>
    <code class="fs-4 user-select-all bg-white px-3 py-1 rounded border d-inline-block"><?= e($claveTemporal['clave']) ?></code>
    <div class="small mt-2">Entrégala por un canal seguro. ADARA no la guarda en texto y no podrá volver a mostrarla.</div>
  </div>
  <?php endif; ?>

<?php if ($esPropietario): ?>
  <section class="mb-5" id="accesos">
  <h4 class="mb-3"><i class="bi bi-person-lock"></i> Súper-administradores</h4>
  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card card-body">
        <h5 class="mb-3"><i class="bi bi-person-plus"></i> Nuevo super admin</h5>
        <form method="post" action="<?= e(url('master_acceso_crear')) ?>" novalidate>
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="sa_nombre">Nombre</label>
            <input class="form-control" id="sa_nombre" name="sa_nombre" value="<?= $val('sa_nombre') ?>" maxlength="80" required>
            <?= $err('sa_nombre') ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="sa_correo">Correo</label>
            <input class="form-control" type="email" id="sa_correo" name="sa_correo" value="<?= $val('sa_correo') ?>" maxlength="100" required>
            <?= $err('sa_correo') ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="cedula_numero">Cédula</label>
            <div class="input-group">
              <select class="form-select flex-grow-0 w-auto" name="prefijo_cedula" aria-label="Tipo de documento">
                <?php foreach (Saas::PREFIJOS_CEDULA as $prefijo): ?>
                  <option value="<?= e($prefijo) ?>"<?= ($form['prefijo_cedula'] ?? 'V-') === $prefijo ? ' selected' : '' ?>><?= e($prefijo) ?></option>
                <?php endforeach; ?>
              </select>
              <input class="form-control" type="number" id="cedula_numero" name="cedula_numero" value="<?= $val('cedula_numero') ?>"
                     placeholder="Ej: 12345678" min="1" max="9999999999" step="1" inputmode="numeric" required autocomplete="off">
            </div>
            <?= $err('sa_cedula') ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="sa_telefono">Teléfono</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-telephone" aria-hidden="true"></i></span>
              <input class="form-control" type="tel" id="sa_telefono" name="sa_telefono" value="<?= $val('sa_telefono') ?>"
                     placeholder="Ej: 0414-1234567" pattern="\+?[0-9][0-9\- ]{6,28}" maxlength="30"
                     title="Solo números, guiones y espacios. Ej: 0414-1234567" required autocomplete="off">
            </div>
            <?= $err('sa_telefono') ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="sa_direccion">Dirección</label>
            <textarea class="form-control" id="sa_direccion" name="sa_direccion" maxlength="500" rows="2" required><?= $val('sa_direccion') ?></textarea>
            <?= $err('sa_direccion') ?>
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-person-check"></i> Crear con clave temporal</button>
          <div class="form-text">Gestiona empresas, pero no esta sección ni tu cuenta.</div>
        </form>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card">
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead class="table-light">
              <tr><th>#</th><th>Nombre y correo</th><th>Estado</th><th>Último acceso</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php foreach ($superAdmins as $s):
                $id = (int) $s['id'];
                $revocado = $s['revocado_en'] !== null;
                $activo = !$revocado && (int) $s['activo'] === 1;
            ?>
              <tr class="<?= $revocado ? 'text-body-secondary' : '' ?>">
                <td><?= $id ?></td>
                <td>
                  <div class="fw-semibold"><?= e($s['nombre']) ?></div>
                  <div class="small"><?= e($s['correo']) ?></div>
                  <?php if (($s['cedula'] ?? '') !== '' || ($s['telefono'] ?? '') !== ''): ?>
                  <div class="small text-body-secondary"><?= e((string) ($s['cedula'] ?? '')) ?><?= ($s['cedula'] ?? '') !== '' && ($s['telefono'] ?? '') !== '' ? ' · ' : '' ?><?= e((string) ($s['telefono'] ?? '')) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($id === Saas::PROPIETARIO_ID): ?>
                    <span class="badge text-bg-warning"><i class="bi bi-lock-fill"></i> Propietario</span>
                  <?php elseif ($revocado): ?>
                    <span class="badge text-bg-dark">Revocado</span>
                  <?php elseif ($activo): ?>
                    <span class="badge text-bg-success">Activo</span>
                  <?php else: ?>
                    <span class="badge text-bg-danger">Suspendido</span>
                  <?php endif; ?>
                </td>
                <td class="small"><?= e($fecha($s['ultimo_acceso'])) ?></td>
                <td class="text-end text-nowrap">
                  <?php if ($id === Saas::PROPIETARIO_ID): ?>
                    <span class="small text-body-secondary">Intocable</span>
                  <?php elseif (!$revocado): ?>
                    <form method="post" action="<?= e(url('master_acceso_estado')) ?>" class="d-inline js-confirmar"
                          data-confirmar="<?= $activo ? e('¿Suspender el acceso de ' . $s['nombre'] . '?') : '' ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <input type="hidden" name="estado" value="<?= $activo ? 'suspendido' : 'activo' ?>">
                      <button type="submit" class="btn btn-sm <?= $activo ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                        <?= $activo ? 'Suspender' : 'Reactivar' ?>
                      </button>
                    </form>
                    <form method="post" action="<?= e(url('master_acceso_revocar')) ?>" class="d-inline js-confirmar"
                          data-confirmar="<?= e('¿Revocar el acceso de ' . $s['nombre'] . '? Dejará de poder entrar hasta que lo reactives.') ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">Revocar</button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="<?= e(url('master_acceso_reactivar')) ?>" class="d-inline js-confirmar"
                          data-confirmar="<?= e('¿Reactivar a ' . $s['nombre'] . '? Su clave anterior queda anulada y verás una temporal nueva.') ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-arrow-counterclockwise"></i> Reactivar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  </section>
<?php endif; ?>

  <section id="empresas">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">Empresas suscritas</h4>
      <small class="text-body-secondary"><?= count($clientes) ?> registradas · <?= $activas ?> con licencia activa</small>
    </div>
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formNuevaEmpresa"
            aria-expanded="<?= $errores !== [] ? 'true' : 'false' ?>" aria-controls="formNuevaEmpresa">
      <i class="bi bi-building-add"></i> Registrar Nueva Empresa
    </button>
  </div>

  <div class="collapse<?= $errores !== [] ? ' show' : '' ?> mb-4" id="formNuevaEmpresa">
    <div class="card card-body">
      <form method="post" action="<?= e(url('master_registrar')) ?>" class="row g-3" novalidate>
        <?= csrf_field() ?>
        <div class="col-md-6">
          <label class="form-label" for="nombre_empresa">Nombre de la empresa</label>
          <input class="form-control" id="nombre_empresa" name="nombre_empresa" value="<?= $val('nombre_empresa') ?>" maxlength="120" required>
          <?= $err('nombre_empresa') ?>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="dominio_o_slug">Slug o dominio</label>
          <input class="form-control" id="dominio_o_slug" name="dominio_o_slug" value="<?= $val('dominio_o_slug') ?>" maxlength="100" placeholder="castillo  o  erp.castillo.com" required>
          <div class="form-text">Sus usuarios entran por <code>slug.tu-dominio</code> o por el dominio propio.</div>
          <?= $err('dominio_o_slug') ?>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="correo_admin_cliente">Correo del administrador de la empresa</label>
          <input class="form-control" type="email" id="correo_admin_cliente" name="correo_admin_cliente" value="<?= $val('correo_admin_cliente') ?>" maxlength="100" required>
          <div class="form-text">Se crea como administrador con una clave temporal que verás una sola vez.</div>
          <?= $err('correo_admin_cliente') ?>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="nombre_db_fisica">Base de datos física</label>
          <input class="form-control font-monospace" id="nombre_db_fisica" name="nombre_db_fisica" value="<?= $val('nombre_db_fisica') ?>" maxlength="64" placeholder="adara_panaderia" pattern="adara_[a-z0-9_]+" required>
          <?= $err('nombre_db_fisica') ?>
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="base_existente" name="base_existente"<?= ($form['base_existente'] ?? '') === '1' ? ' checked' : '' ?>>
            <label class="form-check-label" for="base_existente">
              La base ya existe (empresa migrada): solo vincular, no crear ni modificar nada
            </label>
          </div>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-success"><i class="bi bi-rocket-takeoff"></i> Registrar y desplegar</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Empresa</th><th>Slug / dominio</th><th>Administrador</th><th>Base física</th>
            <th>Licencia</th><th>Contrato</th><th>Registrada</th><th class="text-end">Acción</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($clientes === []): ?>
          <tr><td colspan="8" class="text-center text-body-secondary py-4">Todavía no hay empresas registradas.</td></tr>
        <?php endif; ?>
        <?php foreach ($clientes as $c): $activa = $c['estado_licencia'] === 'activa'; ?>
          <tr>
            <td class="fw-semibold"><?= e($c['nombre_empresa']) ?></td>
            <td><code><?= e($c['dominio_o_slug']) ?></code></td>
            <td class="small"><?= e($c['correo_admin_cliente']) ?></td>
            <td>
              <code><?= e($c['nombre_db_fisica']) ?></code>
              <?php if ((int) $c['base_existe'] === 1): ?>
                <span class="badge text-bg-light border" title="La base existe en el servidor">OK</span>
              <?php else: ?>
                <span class="badge text-bg-warning" title="Registrada, pero la base no existe: el login fallará">sin base</span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $activa ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $activa ? 'Activa' : 'Inactiva' ?></span></td>
            <td class="text-center">
              <?php if (!empty($c['fecha_aceptacion_contrato'])): ?>
                <i class="bi bi-check-circle-fill text-success" title="Contrato aceptado"></i>
              <?php else: ?>
                <i class="bi bi-x-circle-fill text-danger" title="Falta el contrato"></i>
              <?php endif; ?>
            </td>
            <td class="small text-body-secondary"><?= e(date('d/m/Y', strtotime((string) $c['creado_en']))) ?></td>
            <td class="text-end">
              <form method="post" action="<?= e(url('master_licencia')) ?>" class="d-inline js-confirmar"
                    data-confirmar="<?= $activa ? e('¿Suspender la licencia de ' . $c['nombre_empresa'] . '? Sus usuarios saldrán del sistema de inmediato.') : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="estado" value="<?= $activa ? 'inactiva' : 'activa' ?>">
                <button type="submit" class="btn btn-sm <?= $activa ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                  <i class="bi <?= $activa ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i> <?= $activa ? 'Suspender' : 'Activar' ?>
                </button>
              </form>
              <?php if ((int) $c['base_existe'] === 1): ?>
              <form method="post" action="<?= e(url('master_respaldo')) ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-primary" title="Volcado .sql completo de <?= e($c['nombre_db_fisica']) ?>">
                  <i class="bi bi-database-down"></i> Descargar BD
                </button>
              </form>
              <form method="post" action="<?= e(url('master_soporte')) ?>" class="d-inline js-confirmar"
                    data-confirmar="<?= e('¿Entrar en modo soporte a ' . $c['nombre_empresa'] . '? Quedará registrado a tu nombre y verás sus datos.') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-headset"></i> Soporte (Entrar al Panel)</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  </section>
<?php
layout_master_end();
