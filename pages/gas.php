<?php
declare(strict_types=1);

/** @var bool $puedeCrear */
/** @var bool $puedeEditar */
/** @var bool $puedeAnular */
/** @var float|null $tasa */
/** @var string $hoy */

$puedeCrear = $puedeCrear ?? false;
$puedeEditar = $puedeEditar ?? false;
$puedeAnular = $puedeAnular ?? false;
$hoy = $hoy ?? date('Y-m-d');

$config = [
    'crear'  => $puedeCrear,
    'editar' => $puedeEditar,
    'anular' => $puedeAnular,
    'tasa'   => $tasa,
    'hoy'    => $hoy,
    'url'    => [
        'resumen'      => url('gas_resumen'),
        'catalogo'     => url('gas_catalogo'),
        'proveedores'  => url('gas_proveedores'),
        'provCrear'    => url('gas_proveedor_crear'),
        'provEditar'   => url('gas_proveedor_editar'),
        'cilindros'    => url('gas_cilindros'),
        'disponibles'  => url('gas_cilindros_disponibles'),
        'cilCrear'     => url('gas_cilindro_crear'),
        'cilEditar'    => url('gas_cilindro_editar'),
        'cilEliminar'  => url('gas_cilindro_eliminar'),
        'despachos'    => url('gas_despachos'),
        'despacho'     => url('gas_despacho'),
        'desCrear'     => url('gas_despacho_crear'),
        'desEditar'    => url('gas_despacho_editar'),
        'desCilindros' => url('gas_despacho_cilindros'),
        'desQuitar'    => url('gas_despacho_quitar'),
        'desEliminar'  => url('gas_despacho_eliminar'),
        'pago'         => url('gas_pago'),
        'pagoAnular'   => url('gas_pago_anular'),
        'reparaciones' => url('gas_reparaciones'),
        'repEnviar'    => url('gas_reparacion_enviar'),
        'repCerrar'    => url('gas_reparacion_cerrar'),
    ],
];

layout_start('Gestión de Gas', 'gas');
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.15.10/dist/sweetalert2.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/gas.css')) ?>">

<ul class="nav nav-tabs mb-3" id="gasTabs" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#gasResumen" type="button">Resumen</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#gasProveedores" type="button">Proveedores</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#gasCilindros" type="button">Cilindros</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#gasDespachos" type="button">Despachos</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#gasReparaciones" type="button">Reparaciones</button></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="gasResumen">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h5 class="mb-0">Inversión de la semana</h5>
        <div class="text-muted small" id="gasSemanaLabel">Cargando…</div>
      </div>
    </div>
    <div class="row g-3 mb-4 gas-kpi">
      <div class="col-6 col-lg-3"><div class="card stat-card text-center"><div class="card-body"><div class="stat-label">Invertido</div><div class="stat-val" id="kpiInvertido">—</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card stat-card text-center"><div class="card-body"><div class="stat-label">Pagado</div><div class="stat-val text-success" id="kpiPagado">—</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card stat-card text-center accent-red"><div class="card-body"><div class="stat-label">Saldo deudor</div><div class="stat-val text-danger" id="kpiSaldo">—</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card stat-card text-center"><div class="card-body"><div class="stat-label">Bombonas activas</div><div class="stat-val" id="kpiTotal">—</div></div></div></div>
    </div>
    <div class="card mb-4"><div class="card-body">
      <h6 class="section-title">Inventario por capacidad</h6>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="tablaInventario"><thead><tr>
        <th>Capacidad</th><th class="text-end">Óptimas</th><th class="text-end">Regular</th><th class="text-end">Mal estado</th><th class="text-end">Total</th>
      </tr></thead><tbody></tbody></table></div>
    </div></div>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card"><div class="card-body">
          <h6 class="section-title">Por proveedor</h6>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="tablaSemanas"><thead><tr>
            <th>Semana</th><th>Proveedor</th><th class="text-end">Bombonas</th><th class="text-end">Invertido</th><th class="text-end">Saldo</th>
          </tr></thead><tbody></tbody></table></div>
        </div></div>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="gasProveedores">
    <div class="d-flex flex-wrap gap-2 justify-content-between mb-2">
      <input type="search" class="form-control form-control-sm w-search" id="qProveedores" placeholder="Buscar proveedor…" maxlength="80">
      <?php if ($puedeCrear): ?><button class="btn btn-sm btn-primary" type="button" id="btnProvNuevo"><i class="bi bi-plus-lg"></i> Proveedor</button><?php endif; ?>
    </div>
    <div class="table-responsive card"><table class="table table-sm table-hover align-middle mb-0" id="tablaProveedores"><thead><tr>
      <th>Nombre</th><th>RIF</th><th>Teléfono</th><th>Estado</th><th></th>
    </tr></thead><tbody></tbody></table></div>
  </div>

  <div class="tab-pane fade" id="gasCilindros">
    <div class="d-flex flex-wrap gap-2 justify-content-between mb-2">
      <input type="search" class="form-control form-control-sm w-search" id="qCilindros" placeholder="Buscar serial o dueño…" maxlength="80">
      <?php if ($puedeCrear): ?><button class="btn btn-sm btn-primary" type="button" id="btnCilNuevo"><i class="bi bi-plus-lg"></i> Bombona</button><?php endif; ?>
    </div>
    <div class="table-responsive card"><table class="table table-sm table-hover align-middle mb-0" id="tablaCilindros"><thead><tr>
      <th>Foto</th><th>Serial</th><th>Capacidad</th><th>Estado físico</th><th>Propietario</th><th>Registro</th><th></th>
    </tr></thead><tbody></tbody></table></div>
  </div>

  <div class="tab-pane fade" id="gasDespachos">
    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card mb-3"><div class="card-body">
          <h6 class="section-title">Nuevo viaje</h6>
          <form id="formDespacho" class="row g-2">
            <div class="col-12"><label class="form-label" for="desProveedor">Proveedor</label><select class="form-select form-select-sm" id="desProveedor" name="proveedor_id" required></select></div>
            <div class="col-12"><label class="form-label" for="desSalida">Salida</label><input class="form-control form-control-sm" type="date" id="desSalida" name="fecha_salida" required value="<?= e($hoy) ?>"></div>
            <div class="col-6"><label class="form-label" for="desP10">Precio 10 kg</label><input class="form-control form-control-sm" id="desP10" name="precio_10" inputmode="decimal" required placeholder="0,00"></div>
            <div class="col-6"><label class="form-label" for="desP15">Precio 15 kg</label><input class="form-control form-control-sm" id="desP15" name="precio_15" inputmode="decimal" required placeholder="0,00"></div>
            <div class="col-6"><label class="form-label" for="desP27">Precio 27 kg</label><input class="form-control form-control-sm" id="desP27" name="precio_27" inputmode="decimal" required placeholder="0,00"></div>
            <div class="col-6"><label class="form-label" for="desP43">Precio 43 kg</label><input class="form-control form-control-sm" id="desP43" name="precio_43" inputmode="decimal" required placeholder="0,00"></div>
            <div class="col-12"><label class="form-label" for="desNotas">Observaciones</label><input class="form-control form-control-sm" id="desNotas" name="observaciones" maxlength="255"></div>
            <?php if ($puedeCrear): ?><div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Abrir despacho</button></div><?php endif; ?>
          </form>
        </div></div>
        <input type="search" class="form-control form-control-sm mb-2" id="qDespachos" placeholder="Buscar despacho…" maxlength="80">
        <div class="table-responsive card"><table class="table table-sm table-hover align-middle mb-0" id="tablaDespachos"><thead><tr>
          <th>Salida</th><th>Proveedor</th><th class="text-end">Saldo</th><th>Deuda</th>
        </tr></thead><tbody></tbody></table></div>
      </div>
      <div class="col-lg-7">
        <div class="card" id="panelDetalle"><div class="card-body">
          <div class="text-muted" id="detalleVacio">Selecciona un despacho para ver sus bombonas y abonos.</div>
          <div id="detalleCuerpo" hidden>
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
              <div>
                <h6 class="mb-0" id="detTitulo"></h6>
                <div class="small text-muted" id="detMeta"></div>
              </div>
              <div class="d-flex flex-wrap gap-1">
                <?php if ($puedeEditar): ?><button class="btn btn-sm btn-outline-primary" type="button" id="btnCargarCilindros">Cargar bombonas</button><?php endif; ?>
                <?php if ($puedeCrear): ?><button class="btn btn-sm btn-primary" type="button" id="btnAbonar">Registrar abono</button><?php endif; ?>
                <?php if ($puedeEditar): ?><button class="btn btn-sm btn-outline-secondary" type="button" id="btnEntregar">Marcar Entregada</button><?php endif; ?>
                <?php if ($puedeEditar): ?><button class="btn btn-sm btn-outline-danger" type="button" id="btnQuitarViaje" hidden>Quitar viaje</button><?php endif; ?>
              </div>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-4"><div class="small text-muted">Costo</div><strong id="detCosto"></strong></div>
              <div class="col-4"><div class="small text-muted">Pagado</div><strong id="detPagado"></strong></div>
              <div class="col-4"><div class="small text-muted">Saldo</div><strong id="detSaldo"></strong></div>
            </div>
            <h6 class="section-title">Bombonas del viaje</h6>
            <div class="table-responsive mb-3"><table class="table table-sm align-middle" id="tablaLineas"><thead><tr>
              <th>Serial</th><th>Capacidad</th><th>Dueño al llenar</th><th>Físico</th><th class="text-end">USD</th><th></th>
            </tr></thead><tbody></tbody></table></div>
            <h6 class="section-title">Abonos</h6>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="tablaPagos"><thead><tr>
              <th>Fecha</th><th>Método</th><th>Referencia</th><th class="text-end">USD</th><th></th>
            </tr></thead><tbody></tbody></table></div>
          </div>
        </div></div>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="gasReparaciones">
    <div class="d-flex flex-wrap gap-2 justify-content-between mb-2">
      <p class="text-muted small mb-0">Bombonas fuera de planta. Al marcarlas reparadas vuelven a óptimas y el costo sale de la caja.</p>
      <?php if ($puedeCrear): ?><button class="btn btn-sm btn-primary" type="button" id="btnReparar"><i class="bi bi-wrench"></i> Enviar a reparación</button><?php endif; ?>
    </div>
    <div class="table-responsive card"><table class="table table-sm table-hover align-middle mb-0" id="tablaReparaciones"><thead><tr>
      <th>Serial</th><th>Capacidad</th><th>Responsable</th><th>Salida</th><th>Estado</th><th class="text-end">Costo</th><th></th>
    </tr></thead><tbody></tbody></table></div>
  </div>
</div>

<div class="modal fade" id="modalProveedor" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="formProveedor">
  <div class="modal-header"><h5 class="modal-title" id="tituloProveedor">Proveedor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2">
    <input type="hidden" name="id" id="provId">
    <div class="col-8"><label class="form-label" for="provNombre">Nombre</label><input class="form-control form-control-sm" id="provNombre" name="nombre" required maxlength="120"></div>
    <div class="col-4"><label class="form-label" for="provDoc">RIF</label><input class="form-control form-control-sm" id="provDoc" name="documento" maxlength="12" placeholder="J-12345678-9"></div>
    <div class="col-6"><label class="form-label" for="provContacto">Contacto</label><input class="form-control form-control-sm" id="provContacto" name="contacto" maxlength="80"></div>
    <div class="col-6"><label class="form-label" for="provTel">Teléfono</label><input class="form-control form-control-sm" id="provTel" name="telefono" maxlength="20"></div>
    <div class="col-12"><label class="form-label" for="provCorreo">Correo</label><input class="form-control form-control-sm" id="provCorreo" name="correo" maxlength="100"></div>
    <div class="col-12"><label class="form-label" for="provDir">Dirección</label><input class="form-control form-control-sm" id="provDir" name="direccion" maxlength="160"></div>
    <div class="col-12"><label class="form-label" for="provNotas">Notas</label><input class="form-control form-control-sm" id="provNotas" name="notas" maxlength="255"></div>
    <div class="col-12" id="provEstadoWrap" hidden><label class="form-label" for="provEstado">Estado</label>
      <select class="form-select form-select-sm" id="provEstado" name="estado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option><option value="eliminado">Archivado</option></select>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary btn-sm" type="submit">Guardar</button></div>
</form></div></div>

<div class="modal fade" id="modalCilindro" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="formCilindro" enctype="multipart/form-data">
  <div class="modal-header"><h5 class="modal-title" id="tituloCilindro">Bombona</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2">
    <input type="hidden" name="id" id="cilId">
    <div class="col-12"><p class="text-muted small mb-0">El serial se asigna al guardar, con la forma CIL-000001.</p></div>
    <div class="col-12"><label class="form-label" for="cilCapacidad">Capacidad</label>
      <select class="form-select form-select-sm" id="cilCapacidad" name="capacidad_kg" required>
        <option value="">Selecciona…</option>
        <option value="10">10 kg</option>
        <option value="15">15 kg</option>
        <option value="27">27 kg</option>
        <option value="43">43 kg</option>
      </select>
    </div>
    <div class="col-12"><label class="form-label" for="cilFisico">Estado físico</label>
      <select class="form-select form-select-sm" id="cilFisico" name="estado_fisico">
        <option value="optimo">Óptimas condiciones</option>
        <option value="regular">Regular</option>
        <option value="mal_estado">Mal estado / requiere reparación</option>
      </select>
    </div>
    <div class="col-12"><label class="form-label" for="cilObs">Observación</label><input class="form-control form-control-sm" id="cilObs" name="observacion_fisica" maxlength="255"></div>
    <div class="col-12">
      <label class="form-label" for="cilFoto">Foto de la bombona</label>
      <input class="form-control form-control-sm" type="file" id="cilFoto" name="foto" accept="image/jpeg,image/png,image/webp">
      <img id="cilFotoPrev" alt="Foto actual de la bombona" class="mt-2 rounded border d-none" width="72" height="72" style="object-fit:cover">
    </div>
    <div class="col-12"><label class="form-label" for="cilDueno">Propiedad</label>
      <select class="form-select form-select-sm" id="cilDueno" name="tipo_dueno">
        <option value="propio">Propio de la empresa</option>
        <option value="empleado">Empleado</option>
        <option value="transportista">Transportista</option>
        <option value="productor">Productor</option>
        <option value="otro">Otro tercero</option>
      </select>
    </div>
    <div class="col-12" id="cilDuenoCampo"></div>
    <div class="col-12" id="cilArchivoWrap" hidden><label class="form-label" for="cilArchivo">Registro</label>
      <select class="form-select form-select-sm" id="cilArchivo" name="estado_registro"><option value="activo">Activo</option><option value="archivado">Archivado</option></select>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary btn-sm" type="submit">Guardar</button></div>
</form></div></div>

<div class="modal fade" id="modalCargar" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" id="formCargar">
  <div class="modal-header"><h5 class="modal-title">Bombonas del viaje</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="search" class="form-control form-control-sm mb-2" id="qDisponibles" placeholder="Filtrar serial o dueño…">
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="chkTodas"><label class="form-check-label" for="chkTodas">Marcar las visibles</label></div>
    <div class="gas-picker" id="listaDisponibles"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary btn-sm" type="submit">Agregar al despacho</button></div>
</form></div></div>

<div class="modal fade" id="modalReparar" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" id="formReparar">
  <div class="modal-header"><h5 class="modal-title">Enviar a reparación</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="row g-2 mb-2">
      <div class="col-md-7"><label class="form-label" for="repResponsable">Quién la tiene</label><input class="form-control form-control-sm" id="repResponsable" name="responsable" required maxlength="120"></div>
      <div class="col-md-5"><label class="form-label" for="repSalida">Fecha de salida</label><input class="form-control form-control-sm" type="date" id="repSalida" name="fecha_salida" required value="<?= e($hoy) ?>"></div>
    </div>
    <input type="search" class="form-control form-control-sm mb-2" id="qReparar" placeholder="Filtrar serial…">
    <div class="gas-picker" id="listaReparar"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary btn-sm" type="submit">Enviar</button></div>
</form></div></div>

<div class="modal fade" id="modalPago" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="formPago">
  <div class="modal-header"><h5 class="modal-title">Abono al despacho</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2">
    <div class="col-12"><div class="alert alert-light border py-2 mb-0 small" id="pagoSaldo"></div></div>
    <div class="col-6"><label class="form-label" for="pagoFecha">Fecha</label><input class="form-control form-control-sm" type="date" id="pagoFecha" name="fecha" required value="<?= e($hoy) ?>"></div>
    <div class="col-6"><label class="form-label" for="pagoMetodo">Método</label>
      <select class="form-select form-select-sm" id="pagoMetodo" name="metodo">
        <option value="efectivo">Efectivo</option>
        <option value="pago_movil">Pago móvil</option>
        <option value="transferencia">Transferencia</option>
        <option value="otro">Otro</option>
      </select>
    </div>
    <div class="col-6"><label class="form-label" for="pagoMoneda">Moneda</label>
      <select class="form-select form-select-sm" id="pagoMoneda" name="moneda"><option value="USD">USD</option><option value="VES">Bs</option></select>
    </div>
    <div class="col-6"><label class="form-label" for="pagoMonto">Monto</label><input class="form-control form-control-sm" id="pagoMonto" name="monto" inputmode="decimal" required></div>
    <div class="col-6"><label class="form-label" for="pagoTasa">Tasa del día (Bs/$)</label><input class="form-control form-control-sm" id="pagoTasa" name="tasa_bs_por_usd" inputmode="decimal" required value="<?= $tasa !== null ? e(number_format((float) $tasa, 4, '.', '')) : '' ?>"></div>
    <div class="col-6" id="pagoCuentaWrap" hidden><label class="form-label" for="pagoCuenta">Cuenta</label>
      <select class="form-select form-select-sm" id="pagoCuenta" name="cuenta"><option value="banco">Banco</option><option value="caja">Caja</option></select>
    </div>
    <div class="col-12"><label class="form-label" for="pagoRef">Referencia</label><input class="form-control form-control-sm" id="pagoRef" name="referencia" maxlength="40"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary btn-sm" type="submit">Registrar abono</button></div>
</form></div></div>

<script type="application/json" id="gasConfig" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.15.10/dist/sweetalert2.min.js" defer></script>
<script src="<?= e(asset_v('js/gas.js')) ?>" defer></script>
<?php
layout_end();
