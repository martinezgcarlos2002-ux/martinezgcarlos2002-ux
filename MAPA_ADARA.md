# Mapa del código fuente — ERP ADARA

Radiografía de la arquitectura vigente (PHP 8.1+, MySQL, vistas en PHP y JavaScript sin framework). El punto de entrada en producción es la raíz del proyecto. Las carpetas `aserp/` y `aserp-php/` son copias anteriores y no forman parte del enrutador actual.

## 1. Árbol de directorios

```
ADARA/
├── index.php                  Front controller: seguridad, ruta ?p= y despacho
├── layout.php                 Menú lateral, cabecera y pie de las pantallas internas
├── MAPA_ADARA.md
├── .env.example               Variables de conexión (ADARA_DB_*)
│
├── config/
│   ├── app.php                Nombre, zona horaria, constantes de la empresa
│   ├── bootstrap.php          Carga modelos, controladores, sesión y manejadores de error
│   ├── database.php           PDO único y Database::transaction()
│   ├── routes.php             Mapa página → controlador y permiso RBAC
│   └── modulos.php            Catálogo del menú y de permisos
│
├── controllers/               Un controlador por módulo de menú
│   ├── AuthController.php
│   ├── DashboardController.php
│   ├── InventarioController.php
│   ├── ProduccionController.php
│   ├── CatalogoController.php
│   ├── VentasController.php
│   ├── EmpleadosController.php
│   ├── NominaController.php
│   ├── DiasLibresController.php
│   ├── CobranzasController.php
│   ├── UsuariosController.php
│   ├── TransporteController.php
│   ├── ProductoresController.php
│   ├── ControlLecheController.php
│   ├── ResumenController.php
│   ├── FacturaController.php
│   ├── FinanzasController.php
│   ├── AuditoriaController.php
│   └── RespaldoController.php
│
├── models/                    Acceso a tablas y reglas de persistencia
├── services/                  CxC, nómina, transporte, catálogo, resumen, correlativos
├── helpers/                   HTTP, formularios, Excel, PDF/impresión, archivos de empleados
├── security/                  Sesión, CSRF, login, RBAC y bitácora
│
├── pages/                     Vistas principales (no hay una carpeta views por módulo)
│   ├── login.php, dashboard.php
│   ├── ventas_nueva.php, ventas_historial.php, ventas_ticket.php
│   ├── empleados.php, planilla_asistencia.php, rrhh_cv.php, nomina_pdf.php
│   ├── control_leche.php, control_leche_planilla.php
│   ├── partials/control_diario_hoja.php
│   ├── productores_*.php, productor_control.php
│   ├── produccion_diaria.php, produccion_catalogo.php, produccion_registro.php
│   ├── inventario_lista.php
│   ├── facturas.php, facturas_pdf.php, facturas_imprimir.php
│   ├── finanzas.php, resumen.php, auditoria.php, respaldo.php, usuarios_lista.php
│   └── transporte.php, transporte_directorio.php, transporte_cxp.php
│
├── views/                     Vistas sueltas reutilizadas por cuatro módulos
│   ├── catalogo/admin.php, catalogo/pantalla.php
│   ├── facturas/index.php
│   ├── resumen/index.php
│   └── transporte/index.php
│
├── assets/
│   ├── css/                   adara.css, control_diario.css, planilla_asistencia.css, rrhh, ventas, transporte
│   ├── js/                    rrhh.js, transporte.js, catalogo.js, produccion_*.js, resumen.js
│   └── img/                   Logo y fondo de la empresa
│
├── sql/
│   ├── schema.sql             Esquema base
│   └── 002_ … 030_*.sql       Migraciones incrementales
│
├── public/uploads/empleados/  Fotos y documentos de RRHH
└── storage/logs/              php-error.log
```

Recursos Humanos no tiene carpeta propia: vive en `pages/empleados.php`, `NominaController`, `EmpleadosController` y el módulo de permiso `empleados`. Control de Leche vive en `pages/control_leche.php` y `ControlLecheController`. Ventas vive en `pages/ventas_*.php` y `VentasController`.

## 2. Descripción de módulos

### Controladores (`controllers/`)

| Controlador | Función |
| --- | --- |
| `AuthController` | Login, bloqueo por intentos y cierre de sesión. |
| `DashboardController` | Tablero inicial después de autenticarse. |
| `InventarioController` | Productos, stock, unidades y movimientos de almacén. |
| `ProduccionController` | Producción diaria de queso, catálogo de producción y distribución a almacenes. |
| `CatalogoController` | Catálogo de precios y pantalla de publicidad. |
| `VentasController` | Factura POS: cliente genérico, frecuente o exclusivo; caja, CxC, descuento a productor, transportista o nómina. |
| `EmpleadosController` | Directorio, ficha, CV y planilla imprimible de asistencia. |
| `NominaController` | Nómina semanal, préstamos, Excel, PDF y hoja de notaría. |
| `DiasLibresController` | Libres, faltas y cobertura por cargo. |
| `CobranzasController` | Cobranzas a terceros descontadas en la nómina. |
| `UsuariosController` | Usuarios y permisos. Acceso de administrador y de usuario maestro. Un admin no gestiona a un maestro. |
| `TransporteController` | Transportistas, rutas, flotilla, fletes y cuentas por pagar. |
| `ProductoresController` | Ficha del productor, entregas de leche, pagos, cierres semanales y liquidaciones. |
| `ControlLecheController` | Planilla diaria de leche, resumen semanal y costos de producción por tipo de queso. |
| `ResumenController` | Resumen gerencial del día. |
| `FacturaController` | Control de facturas CxC: clientes, cobros, devoluciones y estado de cuenta. |
| `FinanzasController` | Caja y movimientos (efectivo y banco). |
| `AuditoriaController` | Consulta de la bitácora. Solo administradores. |
| `RespaldoController` | Descarga del respaldo SQL. Administrador y usuario maestro. El maestro descarga sin límite y puede bajar `MAPA_ADARA.md` por `respaldo_mapa_codigo`. |

### Vistas

`pages/` es la carpeta de pantallas. `view('nombre')` incluye `pages/nombre.php`. `views/` solo cubre catálogo, facturas, resumen y transporte cuando el controlador pide una plantilla compartida.

| Carpeta o grupo | Función |
| --- | --- |
| `pages/ventas_*.php` | Nueva venta, historial y ticket. |
| `pages/empleados.php` y `planilla_asistencia.php` | RRHH: nómina, directorio, préstamos, libres, cobranzas y asistencia imprimible. |
| `pages/control_leche.php` y `partials/control_diario_hoja.php` | Vista diaria, semanal y de producción; la grilla de litros, faltante y total neto. |
| `pages/productores_*.php` | Listado, ficha, control semanal, entregas y documentos de pago. |
| `pages/produccion_*.php` | Registro diario, catálogo y resúmenes de producción. |
| `pages/facturas*.php` y `views/facturas/` | Control de facturas CxC e impresos. |
| `pages/transporte*.php` y `views/transporte/` | Directorio, CxP y facturas de transporte. |
| `views/catalogo/` | Administración del catálogo y la pantalla pública de precios. |
| `views/resumen/` | Resumen gerencial. |
| `assets/css` y `assets/js` | Estilos de impresión y comportamiento de las pantallas (RRHH, leche, transporte, catálogo). |

## 3. Flujo principal

1. Apache entrega `index.php`.
2. `config/bootstrap.php` fija la zona horaria, registra errores en `storage/logs/php-error.log` y carga helpers, seguridad, modelos, servicios, controladores y `layout.php`. En web abre la sesión.
3. `index.php` envía cabeceras de seguridad (CSP con nonce, sin marcos, sin caché). Lee `?p=` (solo letras minúsculas y guion bajo, hasta 40 caracteres). Vacío redirige a `dashboard` o a `login`.
4. `config/routes.php` devuelve, para esa clave, el módulo de permiso y el par `[Clase, método]` de GET o POST. Si el método no existe, responde 405.
5. En POST valida CSRF. Si la ruta no es pública, exige sesión y el permiso `ver`, `crear`, `editar` o `anular` del módulo (`security/rbac.php` y `config/modulos.php`).
6. Instancia el controlador y llama la acción. Esa acción lee modelos o servicios y termina en `view()`, `redirect()`, `json_response()` o un HTML de impresión.

La conexión es un PDO singleton. `get_db()` devuelve `Database::connection()`. Los datos salen de `ADARA_DB_HOST`, `ADARA_DB_PORT`, `ADARA_DB_NAME` (por defecto `adara`), `ADARA_DB_USER` y `ADARA_DB_PASS`. La sesión MySQL usa `utf8mb4`, zona `-04:00` y modo estricto. `Database::transaction()` abre una transacción o se une a la que ya está abierta, para que una venta, el stock, la caja y la deuda se confirmen o se reviertan juntos.

## 4. Base de datos

Tablas definidas en `sql/schema.sql` y en las migraciones `002` a `035`. El esquema vivo es el resultado de aplicarlas en orden sobre la base `adara`.

### Seguridad y sistema

- `usuarios`
- `login_intentos`
- `auditoria`

### Productores y leche

- `productores`
- `productores_precios`
- `productores_cierres`
- `productores_entregas`
- `productores_abonos`
- `prod_control_leche_diario`
- `prod_control_leche_productor`

### Producción, inventario y catálogo

- `productos`
- `producciones`
- `lotes`
- `formulas_produccion`
- `almacenes`
- `inventario_almacenes`
- `inventario_movimientos`
- `produccion_diaria`
- `produccion_distribuciones`
- `produccion_costos`
- `produccion_costos_quesos`
- `prod_catalogo`
- `ven_catalogo_publicidad`
- `ven_catalogo_tasa`
- `tasas_cambio`

### Ventas y cuentas por cobrar

- `clientes`
- `ventas_clientes`
- `ventas`
- `venta_items`
- `cxc_clientes`
- `cxc_facturas`
- `cxc_factura_detalles`
- `cxc_pagos`
- `cxc_devoluciones`
- `cxc_devolucion_detalles`
- `cxc_saldo_movimientos`

### Recursos humanos

- `empleados`
- `cargos`
- `dias_libres`
- `nomina_semanal`
- `hr_prestamos`
- `hr_prestamos_cuotas`
- `cobranzas_terceros`
- `prestamos_empleados` (tabla anterior; el módulo vigente escribe en `hr_prestamos`)

### Transporte

- `log_transportistas`
- `log_transportista_vehiculos`
- `log_rutas`
- `log_ruta_productores`
- `log_pagos_semanales`
- `log_pagos_transporte`
- `log_fletes_diarios`

### Finanzas

- `caja_movimientos`

## 5. Rol usuario maestro

`usuarios.rol` admite `maestro`, `admin` y `empleado`. El usuario maestro tiene acceso total al ERP, igual que un administrador, y además el centro de servicio técnico en `?p=respaldo`.

Reglas:

- Solo un maestro puede crear, editar, desactivar, cambiar la contraseña o eliminar a otro maestro.
- El maestro principal es Carlos Martínez (`usuarios.id = 6`). No se elimina ni se degrada de `maestro`.
- Siempre debe quedar al menos un maestro activo.
- Los permisos por módulo no se editan para `maestro` ni para `admin`.

Ruta exclusiva:

| Clave | Método | Controlador | Quién |
| --- | --- | --- | --- |
| `respaldo_mapa_codigo` | POST | `RespaldoController::descargarMapaCodigo` | Solo `maestro`. Descarga `MAPA_ADARA.md` y deja constancia en `auditoria`. |

La migración que crea el rol y el parche de POS, días libres y control de leche es `sql/035_usuario_maestro_y_parche_auxiliar.sql`.
