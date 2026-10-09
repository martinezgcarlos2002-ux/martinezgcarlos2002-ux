4<?php
session_start();

function get_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    // local-config.php permite personalizar la conexión (opcional)
    $local_cfg = __DIR__ . '/local-config.php';
    if (file_exists($local_cfg)) {
        require_once $local_cfg;
    }

    $db_url = getenv('DATABASE_URL');

    // Usar PostgreSQL solo si: hay DATABASE_URL, el driver pdo_pgsql está
    // instalado, y el usuario no forzó MySQL con DB_DRIVER en local-config.php.
    $use_pg = $db_url
              && !defined('DB_DRIVER')
              && extension_loaded('pdo_pgsql');

    if (!$use_pg) {
        // ── Modo local / XAMPP: MySQL ─────────────────────────────────────
        $driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
        $host   = defined('DB_HOST')   ? DB_HOST   : 'localhost';
        $port   = defined('DB_PORT')   ? DB_PORT   : '3306';
        $dbname = defined('DB_NAME')   ? DB_NAME   : 'userp';
        $user   = defined('DB_USER')   ? DB_USER   : 'root';
        $pass   = defined('DB_PASS')   ? DB_PASS   : '';
        $dsn    = "{$driver}:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    } else {
        // ── Producción: PostgreSQL desde DATABASE_URL ─────────────────────
        $p      = parse_url($db_url);
        $host   = $p['host']               ?? '127.0.0.1';
        $port   = $p['port']               ?? 5432;
        $dbname = ltrim($p['path'] ?? '/postgres', '/');
        $user   = urldecode($p['user']     ?? '');
        $pass   = urldecode($p['pass']     ?? '');
        $dsn    = "pgsql:host={$host};port={$port};dbname={$dbname}";
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    // Aplicar parches de schema en MySQL (silencioso, una sola vez por request)
    static $patched = false;
    if (!$patched) {
        $patched = true;
        try { ensure_schema_mysql(); } catch (Throwable $e) {}
    }
    return $pdo;
}

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ?p=login');
        exit;
    }
}

/**
 * Devuelve el array de permisos del usuario actual directamente desde la BD.
 * Admin siempre tiene acceso total (devuelve null = sin restricciones).
 * Empleado sin permisos_json configurado → array vacío (sin acceso, solo dashboard).
 * Nunca se cachea en sesión para que los cambios del admin surtan efecto de inmediato.
 */
function get_permisos(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    // admin = acceso total
    if (($_SESSION['user_rol'] ?? '') === 'admin') return null;

    try {
        $db = get_db();
        $st = $db->prepare('SELECT permisos_json FROM usuarios WHERE id = ?');
        $st->execute([$_SESSION['user_id']]);
        $row = $st->fetch();
        $json = $row['permisos_json'] ?? null;
        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) return $decoded;
        }
        // Sin permisos configurados → solo dashboard por defecto
        return ['dashboard' => true];
    } catch (Exception $e) {
        return ['dashboard' => true];
    }
}

/**
 * Verifica si el usuario tiene permiso para un módulo dado.
 * Admin → siempre true. De lo contrario, checa el array de permisos.
 */
function has_permiso(string $modulo): bool {
    $permisos = get_permisos();
    if ($permisos === null) return true;      // admin: acceso total
    return !empty($permisos[$modulo]);        // true solo si está explícitamente en true
}

/**
 * Mapea un nombre de página al módulo correspondiente.
 * Las sub-páginas se mapean al módulo padre.
 */
function page_to_modulo(string $page): string {
    $map = [
        'proveedor_detalle'   => 'proveedores',
        'proveedores_control' => 'proveedores',
        'productor_detalle'   => 'productores',
        'control_leche'       => 'productores',
        'produccion_queso'    => 'productores',
        'resumen_diario'      => 'finanzas',
        'control_semanal'     => 'productores',
        'cierres'             => 'productores',
        'factura_imprimir'    => 'ventas',
        'proveedor_reporte'   => 'proveedores',
        'proveedor_factura'   => 'proveedores',
        'productor_reporte'   => 'productores',
        'productor_factura'   => 'productores',
        'sql_fix'             => 'usuarios',
        'importar_entregas'   => 'productores',
        'transportista_detalle' => 'transportistas',
        'transportista_factura' => 'transportistas',
    ];
    return $map[$page] ?? $page;
}

/**
 * Asegura que las columnas críticas existan en MySQL (parche para BDs viejas).
 * Sólo corre en MySQL, es idempotente y silenciosa.
 */
function ensure_schema_mysql(): void {
    static $done = false;
    if ($done) return;
    try {
        if (!is_mysql()) { $done = true; return; }
        $db = get_db();
        $patches = [
            ['productores',  'precio_litro_usd', "DECIMAL(12,4) NOT NULL DEFAULT 0"],
            ['productores',  'credito_dias',     "INT NOT NULL DEFAULT 7"],
            ['productores',  'estado',           "TINYINT(1) NOT NULL DEFAULT 1"],
            ['productores',  'documento',        "VARCHAR(40)"],
            ['productores',  'correo',           "VARCHAR(150)"],
            ['productores',  'direccion',        "VARCHAR(255)"],
            ['proveedores',  'precio_litro_usd', "DECIMAL(12,4) NOT NULL DEFAULT 0"],
            ['empleados',    'cedula',           "VARCHAR(40)"],
            ['empleados',    'apellidos',        "VARCHAR(150)"],
            ['empleados',    'fecha_salida',     "DATE"],
            ['empleados',    'salario_semanal',  "DECIMAL(12,2) NOT NULL DEFAULT 0"],
            ['empleados',    'cuenta_propia',    "TINYINT(1) NOT NULL DEFAULT 0"],
            ['empleados',    'direccion',        "VARCHAR(255)"],
            ['empleados',    'expediente_penal', "TINYINT(1) NOT NULL DEFAULT 0"],
            ['empleados',    'fecha_nacimiento', "DATE"],
            ['empleados_nomina','pendiente',    "DECIMAL(12,2) NOT NULL DEFAULT 0"],
        ];
        // Tabla empleados_nomina
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS empleados_nomina (
                id INT AUTO_INCREMENT PRIMARY KEY,
                empleado_id INT NOT NULL,
                semana_inicio DATE NOT NULL,
                dias_no_trabajados DECIMAL(4,1) NOT NULL DEFAULT 0,
                pagos_extras DECIMAL(12,2) NOT NULL DEFAULT 0,
                vales_mercancia DECIMAL(12,2) NOT NULL DEFAULT 0,
                bodega_bs DECIMAL(12,2) NOT NULL DEFAULT 0,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_emp_sem (empleado_id, semana_inicio),
                FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
            )");
        } catch (Exception $e) { error_log('[empleados_nomina] '.$e->getMessage()); }
        foreach ($patches as [$tbl, $col, $type]) {
            $st = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $st->execute([$tbl, $col]);
            if (!$st->fetchColumn()) {
                // Sólo agregar si la tabla existe
                $stx = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
                $stx->execute([$tbl]);
                if ($stx->fetchColumn()) {
                    $db->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $type");
                }
            }
        }
        $done = true;
    } catch (Exception $e) {
        error_log('[ensure_schema_mysql] ' . $e->getMessage());
    }
}

/**
 * Asegura que productores_abonos tenga la columna semana_aplicada.
 * Esta columna indica a qué semana se aplica el pago (puede diferir de la fecha
 * en que se registró). Funciona en MySQL y PostgreSQL.
 */
function ensure_abono_semana_column(): void {
    static $checked = false;
    if ($checked) return;
    try {
        $db = get_db();
        if (is_mysql()) {
            $st = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productores_abonos'
                                  AND COLUMN_NAME='semana_aplicada'");
            $st->execute();
            if (!$st->fetchColumn()) {
                $db->exec("ALTER TABLE productores_abonos ADD COLUMN semana_aplicada DATE NULL");
            }
        } else {
            $db->exec("ALTER TABLE productores_abonos ADD COLUMN IF NOT EXISTS semana_aplicada DATE");
        }
        $checked = true;
    } catch (Exception $e) {
        error_log('[ensure_abono_semana_column] ' . $e->getMessage());
    }
}

/**
 * Asegura que exista la tabla productores_cierres en MySQL o PostgreSQL.
 * Se llama al entrar a las páginas que la usan.
 */
function ensure_cierres_table(): void {
    static $checked = false;
    if ($checked) return;
    try {
        $db = get_db();
        if (is_mysql()) {
            $db->exec("CREATE TABLE IF NOT EXISTS productores_cierres (
                id INT AUTO_INCREMENT PRIMARY KEY,
                productor_id INT NOT NULL,
                semana_inicio DATE NOT NULL,
                semana_fin DATE NOT NULL,
                litros DECIMAL(12,3) NOT NULL DEFAULT 0,
                precio_litro DECIMAL(12,4) NOT NULL DEFAULT 0,
                total_factura DECIMAL(12,2) NOT NULL DEFAULT 0,
                abonos_semana DECIMAL(12,2) NOT NULL DEFAULT 0,
                arrastre_anterior DECIMAL(12,2) NOT NULL DEFAULT 0,
                monto_pagado DECIMAL(12,2) NOT NULL DEFAULT 0,
                estado VARCHAR(20) NOT NULL DEFAULT 'cerrada',
                notas TEXT,
                cerrado_por INT,
                cerrado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                pagado_en TIMESTAMP NULL,
                UNIQUE KEY uniq_prod_semana (productor_id, semana_inicio),
                INDEX idx_semana (semana_inicio),
                FOREIGN KEY (productor_id) REFERENCES productores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS productores_cierres (
                id SERIAL PRIMARY KEY,
                productor_id INTEGER NOT NULL REFERENCES productores(id) ON DELETE CASCADE,
                semana_inicio DATE NOT NULL,
                semana_fin DATE NOT NULL,
                litros NUMERIC(12,3) NOT NULL DEFAULT 0,
                precio_litro NUMERIC(12,4) NOT NULL DEFAULT 0,
                total_factura NUMERIC(12,2) NOT NULL DEFAULT 0,
                abonos_semana NUMERIC(12,2) NOT NULL DEFAULT 0,
                arrastre_anterior NUMERIC(12,2) NOT NULL DEFAULT 0,
                monto_pagado NUMERIC(12,2) NOT NULL DEFAULT 0,
                estado VARCHAR(20) NOT NULL DEFAULT 'cerrada',
                notas TEXT,
                cerrado_por INTEGER,
                cerrado_en TIMESTAMP NOT NULL DEFAULT NOW(),
                pagado_en TIMESTAMP,
                CONSTRAINT productores_cierres_unq UNIQUE (productor_id, semana_inicio)
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_pc_semana ON productores_cierres(semana_inicio DESC)");
        }
        $checked = true;
    } catch (Exception $e) {
        error_log('[ensure_cierres_table] ' . $e->getMessage());
    }
}

/**
 * Crea las tablas transportistas y transportistas_abonos, y agrega la columna
 * transportista_id a productores_entregas (para saber quién trajo cada entrega
 * de leche). Idempotente, funciona en MySQL y PostgreSQL.
 */
function ensure_transportistas_schema(): void {
    static $done = false; if ($done) return;
    try {
        $db = get_db();
        if (is_mysql()) {
            $db->exec("CREATE TABLE IF NOT EXISTS transportistas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(120) NOT NULL,
                documento VARCHAR(40),
                telefono VARCHAR(20),
                direccion VARCHAR(255),
                tarifa_litro_usd DECIMAL(12,4) NOT NULL DEFAULT 0,
                estado TINYINT(1) NOT NULL DEFAULT 1,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("CREATE TABLE IF NOT EXISTS transportistas_abonos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transportista_id INT NOT NULL,
                fecha DATE NOT NULL,
                monto_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
                tipo VARCHAR(20) NOT NULL DEFAULT 'efectivo',
                descripcion VARCHAR(255),
                semana_aplicada DATE NULL,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ta_transp (transportista_id),
                FOREIGN KEY (transportista_id) REFERENCES transportistas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try { $db->exec("ALTER TABLE transportistas ADD COLUMN productor_id INT NULL"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE productores_entregas ADD COLUMN transportista_id INT NULL"); } catch (Throwable $e) {}
            try { $db->exec("CREATE INDEX idx_pe_transp ON productores_entregas(transportista_id)"); } catch (Throwable $e) {}
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS transportistas (
                id SERIAL PRIMARY KEY,
                nombre VARCHAR(120) NOT NULL,
                documento VARCHAR(40),
                telefono VARCHAR(20),
                direccion VARCHAR(255),
                tarifa_litro_usd NUMERIC(12,4) NOT NULL DEFAULT 0,
                estado BOOLEAN NOT NULL DEFAULT TRUE,
                creado_en TIMESTAMP NOT NULL DEFAULT NOW()
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS transportistas_abonos (
                id SERIAL PRIMARY KEY,
                transportista_id INTEGER NOT NULL REFERENCES transportistas(id) ON DELETE CASCADE,
                fecha DATE NOT NULL,
                monto_usd NUMERIC(12,2) NOT NULL DEFAULT 0,
                tipo VARCHAR(20) NOT NULL DEFAULT 'efectivo',
                descripcion VARCHAR(255),
                semana_aplicada DATE,
                creado_en TIMESTAMP NOT NULL DEFAULT NOW()
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ta_transp ON transportistas_abonos(transportista_id)");
            try { $db->exec("ALTER TABLE transportistas ADD COLUMN IF NOT EXISTS productor_id INTEGER"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE productores_entregas ADD COLUMN IF NOT EXISTS transportista_id INTEGER"); } catch (Throwable $e) {}
            try { $db->exec("CREATE INDEX IF NOT EXISTS idx_pe_transp ON productores_entregas(transportista_id)"); } catch (Throwable $e) {}
        }
        $done = true;
    } catch (Throwable $e) {
        error_log('[ensure_transportistas_schema] ' . $e->getMessage());
    }
}

/**
 * Devuelve el id del transportista vinculado (perfil) a un productor, o null.
 * Prefiere el transportista activo; si sólo hay inactivos, devuelve ese.
 */
function transportista_de_productor(PDO $db, int $productor_id): ?int {
    if ($productor_id <= 0) return null;
    try {
        $st = $db->prepare('SELECT id FROM transportistas WHERE productor_id=? ORDER BY estado DESC, id ASC LIMIT 1');
        $st->execute([$productor_id]);
        $v = $st->fetchColumn();
        return $v !== false ? (int)$v : null;
    } catch (Throwable $e) { return null; }
}

/**
 * Asigna un transportista a las entregas de leche de un productor que aún no
 * tengan transportista (transportista_id IS NULL). Así los litros del control de
 * leche se reflejan en el transportista que las trae. No pisa asignaciones ya
 * hechas explícitamente. Idempotente. Devuelve nº de filas afectadas.
 */
function backfill_entregas_transportista(PDO $db, int $productor_id, int $transportista_id): int {
    if ($productor_id <= 0 || $transportista_id <= 0) return 0;
    try {
        $st = $db->prepare('UPDATE productores_entregas SET transportista_id=? WHERE productor_id=? AND transportista_id IS NULL');
        $st->execute([$transportista_id, $productor_id]);
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('[backfill_entregas_transportista] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Crea tablas almacenes y productos_stock; agrega almacen_id a produccion_diaria y facturas.
 * Idempotente, funciona en MySQL y Postgres.
 */
function ensure_almacenes_schema(): void {
    static $done = false; if ($done) return;
    try {
        $db = get_db();
        if (is_mysql()) {
            $db->exec("CREATE TABLE IF NOT EXISTS almacenes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(80) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                orden INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("CREATE TABLE IF NOT EXISTS productos_stock (
                producto_id INT NOT NULL,
                almacen_id INT NOT NULL,
                stock DECIMAL(14,3) NOT NULL DEFAULT 0,
                PRIMARY KEY (producto_id, almacen_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try { $db->exec("ALTER TABLE produccion_diaria ADD COLUMN almacen_id INT NULL"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE facturas ADD COLUMN almacen_id INT NULL"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE detalle_factura ADD COLUMN almacen_id INT NULL"); } catch (Throwable $e) {}
            // Permitir varias filas por (fecha, pp_id) — una por almacén
            try { $db->exec("ALTER TABLE produccion_diaria DROP INDEX uq_fecha_prod"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE produccion_diaria ADD UNIQUE KEY uq_fecha_prod_alm (fecha, producto_produccion_id, almacen_id)"); } catch (Throwable $e) {}
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS almacenes (
                id SERIAL PRIMARY KEY,
                nombre VARCHAR(80) NOT NULL,
                activo BOOLEAN NOT NULL DEFAULT TRUE,
                orden INTEGER NOT NULL DEFAULT 0
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS productos_stock (
                producto_id INTEGER NOT NULL,
                almacen_id INTEGER NOT NULL,
                stock NUMERIC(14,3) NOT NULL DEFAULT 0,
                PRIMARY KEY (producto_id, almacen_id)
            )");
            try { $db->exec("ALTER TABLE produccion_diaria ADD COLUMN IF NOT EXISTS almacen_id INTEGER"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE facturas ADD COLUMN IF NOT EXISTS almacen_id INTEGER"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE detalle_factura ADD COLUMN IF NOT EXISTS almacen_id INTEGER"); } catch (Throwable $e) {}
            // Permitir varias filas por (fecha, pp_id) — una por almacén
            try { $db->exec("ALTER TABLE produccion_diaria DROP CONSTRAINT IF EXISTS uq_fecha_prod"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE produccion_diaria DROP CONSTRAINT IF EXISTS produccion_diaria_fecha_producto_produccion_id_key"); } catch (Throwable $e) {}
            try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_fecha_prod_alm ON produccion_diaria (fecha, producto_produccion_id, almacen_id)"); } catch (Throwable $e) {}
        }
        $n = (int)$db->query("SELECT COUNT(*) FROM almacenes")->fetchColumn();
        if ($n === 0) {
            // Instalación nueva: crear 4 almacenes por defecto.
            $st = $db->prepare("INSERT INTO almacenes (nombre, orden) VALUES (?,?)");
            $st->execute(['Almacén 1', 10]);
            $st->execute(['Almacén 2', 20]);
            $st->execute(['Almacén 3', 30]);
            $st->execute(['Almacén 4', 40]);
        }
        // Nota: no se rellena automáticamente hasta 4. Si el usuario elimina almacenes,
        // su elección se respeta (siempre debe quedar al menos 1, garantizado por el borrado).
        // Seed inicial: si productos_stock está vacío pero hay productos con stock,
        // volcar todo el stock al primer almacén (para no perder inventario actual).
        $hayPS = (int)$db->query("SELECT COUNT(*) FROM productos_stock")->fetchColumn();
        if ($hayPS === 0) {
            try {
                $alm1 = (int)$db->query("SELECT id FROM almacenes ORDER BY orden, id LIMIT 1")->fetchColumn();
                $tieneProds = false;
                try { $tieneProds = (int)$db->query("SELECT COUNT(*) FROM productos WHERE stock > 0")->fetchColumn() > 0; } catch (Throwable $e) {}
                if ($alm1 && $tieneProds) {
                    $rows = $db->query("SELECT id, stock FROM productos WHERE stock > 0")->fetchAll();
                    $ins  = $db->prepare("INSERT INTO productos_stock (producto_id, almacen_id, stock) VALUES (?,?,?)");
                    foreach ($rows as $r) $ins->execute([(int)$r['id'], $alm1, (float)$r['stock']]);
                }
            } catch (Throwable $e) { error_log('[almacenes seed stock] ' . $e->getMessage()); }
        }
        $done = true;
    } catch (Throwable $e) {
        error_log('[ensure_almacenes_schema] ' . $e->getMessage());
    }
}

/**
 * Incrementa (o decrementa, si delta es negativo) el stock por almacén.
 * Upsert: si no existe la fila, la crea.
 */
function ajustar_stock_almacen(int $producto_id, int $almacen_id, float $delta): void {
    if ($delta == 0.0 || $producto_id <= 0 || $almacen_id <= 0) return;
    $db = get_db();
    if (is_mysql()) {
        $db->prepare("INSERT INTO productos_stock (producto_id, almacen_id, stock) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock)")
           ->execute([$producto_id, $almacen_id, $delta]);
    } else {
        $db->prepare("INSERT INTO productos_stock (producto_id, almacen_id, stock) VALUES (?,?,?)
                      ON CONFLICT (producto_id, almacen_id) DO UPDATE SET stock = productos_stock.stock + EXCLUDED.stock")
           ->execute([$producto_id, $almacen_id, $delta]);
    }
}

/**
 * Snap una fecha al sábado anterior (o el mismo día si ya es sábado).
 * Las semanas del sistema de cierres van de sábado a viernes.
 */
function snap_sat(string $date): DateTime {
    $dt  = new DateTime($date);
    $dow = (int)$dt->format('N'); // 1=Lun … 6=Sáb, 7=Dom
    if ($dow !== 6) {
        $snap = ($dow === 7) ? 1 : ($dow + 1);
        $dt->modify("-$snap days");
    }
    return $dt;
}

/**
 * Cierra automáticamente todas las semanas pasadas (sábado–viernes ya terminadas)
 * que tengan entregas pero aún no tengan cierre. Idempotente: si ya hay cierre,
 * no lo toca. Se llama lazy al entrar a páginas relacionadas a productores.
 *
 * Returns: número de cierres creados.
 */
function auto_close_past_weeks(?int $productor_id = null): int {
    ensure_cierres_table();
    $db  = get_db();
    $hoy = new DateTime();
    $dow = (int)$hoy->format('N');
    $off = ($dow === 6) ? 0 : (($dow === 7) ? 1 : ($dow + 1));
    $current_sat = (clone $hoy)->modify("-$off days")->format('Y-m-d');

    // Encontrar todas las semanas con entregas anteriores a la semana actual
    $sql    = "SELECT DISTINCT productor_id, fecha FROM productores_entregas WHERE fecha < ?";
    $params = [$current_sat];
    if ($productor_id) { $sql .= " AND productor_id=?"; $params[] = $productor_id; }
    $st = $db->prepare($sql);
    $st->execute($params);

    $pares = [];
    foreach ($st->fetchAll() as $r) {
        $ws = snap_sat($r['fecha'])->format('Y-m-d');
        if ($ws >= $current_sat) continue;
        $pares[$r['productor_id'].'|'.$ws] = [(int)$r['productor_id'], $ws];
    }

    $created = 0;
    foreach ($pares as [$pid, $ws]) {
        $st = $db->prepare("SELECT id FROM productores_cierres WHERE productor_id=? AND semana_inicio=?");
        $st->execute([$pid, $ws]);
        if ($st->fetchColumn()) continue;

        $we = (clone snap_sat($ws))->modify('+6 days')->format('Y-m-d');

        $st = $db->prepare("SELECT COALESCE(SUM(litros),0) lit, COALESCE(SUM(subtotal_usd),0) tot
                            FROM productores_entregas WHERE productor_id=? AND fecha BETWEEN ? AND ?");
        $st->execute([$pid, $ws, $we]);
        $r = $st->fetch();
        $litros = (float)$r['lit']; $total = (float)$r['tot'];
        if ($litros == 0 && $total == 0) continue;

        ensure_abono_semana_column();
        $st = $db->prepare("SELECT COALESCE(SUM(monto_usd),0) FROM productores_abonos
                            WHERE productor_id=? AND COALESCE(semana_aplicada,fecha) BETWEEN ? AND ?
                              AND (descripcion IS NULL OR descripcion NOT LIKE 'Pago cierre%')");
        $st->execute([$pid, $ws, $we]);
        $abonos = (float)$st->fetchColumn();

        // Arrastre = saldo pendiente SOLO de la semana cerrada inmediatamente anterior
        // (esa semana ya absorbió todo lo previo en su propio arrastre, así que no
        // se suman semanas más antiguas para no duplicar).
        $st = $db->prepare("SELECT (total_factura + arrastre_anterior - abonos_semana - monto_pagado) AS pend
                            FROM productores_cierres
                            WHERE productor_id=? AND semana_inicio<?
                            ORDER BY semana_inicio DESC LIMIT 1");
        $st->execute([$pid, $ws]);
        $arrastre = max(0, (float)($st->fetchColumn() ?: 0));

        $st = $db->prepare("SELECT precio_litro_usd FROM productores WHERE id=?");
        $st->execute([$pid]);
        $precio = (float)$st->fetchColumn();

        $estado = ($total + $arrastre - $abonos) <= 0.01 ? 'pagada' : 'cerrada';
        try {
            $db->prepare("INSERT INTO productores_cierres
                          (productor_id, semana_inicio, semana_fin, litros, precio_litro, total_factura,
                           abonos_semana, arrastre_anterior, estado, notas)
                          VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$pid, $ws, $we, $litros, $precio, $total, $abonos, $arrastre, $estado, 'Cierre automático']);
            $created++;
        } catch (Exception $e) {
            error_log('[auto_close_past_weeks] ' . $e->getMessage());
        }
    }
    return $created;
}

/**
 * Recalcula arrastre y estado de TODOS los cierres de un productor en orden
 * cronológico. Regla: arrastre = pendiente neto de la semana inmediatamente
 * anterior (esa semana ya consolidó las previas). Una vez consolidado se
 * vuelve "neto" — no se vuelve a buscar deuda de semanas más antiguas.
 */
function recompute_cierres(PDO $db, int $pid): void {
    ensure_abono_semana_column();
    $st = $db->prepare("SELECT id, semana_inicio, semana_fin, total_factura
                        FROM productores_cierres
                        WHERE productor_id=? ORDER BY semana_inicio ASC");
    $st->execute([$pid]);
    $rows = $st->fetchAll();

    // Semanas de cierre existentes (orden ascendente) para ubicar cada abono.
    $cierre_weeks = array_map(fn($r) => $r['semana_inicio'], $rows);
    $cierre_set   = array_flip($cierre_weeks);

    // Semanas que tienen entregas. Una semana con entregas pero SIN cierre es la
    // semana en curso: se muestra como fila aparte en la pestaña Cierres, así que
    // su abono NO debe traerse a una semana cerrada anterior (evita doble conteo).
    $ent_weeks = [];
    $ew = $db->prepare("SELECT DISTINCT fecha FROM productores_entregas WHERE productor_id=?");
    $ew->execute([$pid]);
    foreach ($ew->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $ent_weeks[snap_sat($f)->format('Y-m-d')] = true;
    }

    // Resuelve a qué semana de cierre pertenece un abono. Los pagos casi siempre
    // se hacen DESPUÉS de terminar la semana, así que un abono cae en la semana
    // siguiente. Lo aplicamos a la ÚLTIMA semana de cierre cuyo inicio sea <= la
    // semana del abono (esa semana arrastra la deuda). Si el abono es anterior a
    // todos los cierres, se aplica al primero. Así NINGÚN abono se pierde y el
    // "Total pagado" de la pestaña Cierres cuadra con el Saldo global de la
    // cabecera, sin importar desde dónde se haya registrado el pago.
    $resolve_week = function (string $sw) use ($cierre_weeks, $cierre_set, $ent_weeks): ?string {
        if (!$cierre_weeks) return null;
        // Semana en curso (tiene entregas pero aún no cierra): se maneja en su
        // propia fila; no la atribuimos a una semana cerrada anterior.
        if (!isset($cierre_set[$sw]) && isset($ent_weeks[$sw])) return null;
        $target = null;
        foreach ($cierre_weeks as $cw) {
            if ($cw <= $sw) { $target = $cw; } else { break; }
        }
        return $target ?? $cierre_weeks[0];
    };

    // Pre-calcular abonos por semana (todos los abonos del productor agrupados por semana_aplicada o fecha)
    $st2 = $db->prepare("SELECT fecha, semana_aplicada, monto_usd, descripcion
                         FROM productores_abonos WHERE productor_id=?");
    $st2->execute([$pid]);
    $abo_semana = []; // [semana_inicio => abonos_normales]
    $pago_cierre_semana = []; // [semana_inicio => pagos cierre]
    foreach ($st2->fetchAll() as $a) {
        $base = $a['semana_aplicada'] ?: $a['fecha'];
        $sw   = snap_sat($base)->format('Y-m-d');
        $sw   = $resolve_week($sw) ?? $sw; // atribuir a un cierre existente
        $monto = (float)$a['monto_usd'];
        if (!empty($a['descripcion']) && strpos($a['descripcion'], 'Pago cierre') === 0) {
            $pago_cierre_semana[$sw] = ($pago_cierre_semana[$sw] ?? 0) + $monto;
        } else {
            $abo_semana[$sw] = ($abo_semana[$sw] ?? 0) + $monto;
        }
    }

    $prev_pend = 0.0;
    $upd = $db->prepare("UPDATE productores_cierres
                         SET arrastre_anterior=?, abonos_semana=?, monto_pagado=?, estado=? WHERE id=?");
    foreach ($rows as $r) {
        $arr   = max(0.0, $prev_pend);
        $sw    = $r['semana_inicio'];
        $abo   = (float)($abo_semana[$sw] ?? 0);
        $pag   = (float)($pago_cierre_semana[$sw] ?? 0);
        $deuda = (float)$r['total_factura'] + $arr - $abo - $pag;
        if ($deuda <= 0.01) {
            $estado = 'pagada';
        } elseif ($pag > 0 || $abo > 0) {
            $estado = 'parcial';
        } else {
            $estado = 'cerrada';
        }
        $upd->execute([$arr, $abo, $pag, $estado, $r['id']]);
        $prev_pend = max(0.0, $deuda);
    }
}

/**
 * Totales unificados de un productor para la CABECERA (litros y facturado).
 * Usa EXACTAMENTE la misma fuente que la pestaña Cierres para que ambos bloques
 * coincidan siempre: el snapshot del cierre en semanas ya cerradas + las
 * entregas reales en las semanas sin cierre (semana en curso). De este modo, si
 * un cierre se edita a mano, la cabecera refleja ese valor editado; y si se edita
 * una entrega de una semana cerrada (que sincroniza el snapshot), también.
 */
function producer_unified_facturado(PDO $db, int $pid): array {
    ensure_cierres_table();
    // Snapshots de semanas cerradas (fuente de verdad de la semana cerrada).
    $c = $db->prepare("SELECT semana_inicio, litros, total_factura
                       FROM productores_cierres WHERE productor_id=?");
    $c->execute([$pid]);
    $lit = 0.0; $fac = 0.0; $cierre_weeks = [];
    foreach ($c->fetchAll() as $r) {
        $lit += (float)$r['litros'];
        $fac += (float)$r['total_factura'];
        $cierre_weeks[$r['semana_inicio']] = true;
    }
    // Entregas de semanas SIN cierre (semana en curso): se cuentan en vivo.
    $e = $db->prepare("SELECT fecha, litros, subtotal_usd
                       FROM productores_entregas WHERE productor_id=?");
    $e->execute([$pid]);
    $open = []; // [semana_inicio => ['lit'=>,'tot'=>]]
    foreach ($e->fetchAll() as $r) {
        $sw = snap_sat($r['fecha'])->format('Y-m-d');
        if (isset($cierre_weeks[$sw])) continue; // ya contada por el snapshot
        if (!isset($open[$sw])) $open[$sw] = ['lit'=>0.0, 'tot'=>0.0];
        $open[$sw]['lit'] += (float)$r['litros'];
        $open[$sw]['tot'] += (float)$r['subtotal_usd'];
    }
    foreach ($open as $w) {
        $lit += $w['lit'];
        $fac += round($w['tot'], 2); // redondeo por semana, igual que la pestaña
    }
    return ['litros' => $lit, 'facturado' => $fac];
}

/**
 * Sincroniza el snapshot de un cierre con las entregas reales de su semana.
 * Si la fecha cae en una semana que YA tiene cierre, refresca litros, precio y
 * total_factura del snapshot desde las entregas y recalcula arrastre/estado. Así,
 * editar una entrega de una semana pasada se refleja en el cierre y en toda la
 * sección de productores (última edición manda, venga del lado que venga). Las
 * semanas sin cierre (semana en curso) se ignoran: ya se leen en vivo.
 */
function sync_cierre_from_entregas(PDO $db, int $pid, ?string $fecha): void {
    if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) return;
    ensure_cierres_table();
    $ws = snap_sat($fecha)->format('Y-m-d');
    $we = (clone snap_sat($fecha))->modify('+6 days')->format('Y-m-d');
    $c = $db->prepare("SELECT id FROM productores_cierres WHERE productor_id=? AND semana_inicio=?");
    $c->execute([$pid, $ws]);
    $cid = $c->fetchColumn();
    if (!$cid) return; // semana sin cierre: nada que sincronizar
    $e = $db->prepare("SELECT COALESCE(SUM(litros),0) lit, COALESCE(SUM(subtotal_usd),0) tot
                       FROM productores_entregas WHERE productor_id=? AND fecha BETWEEN ? AND ?");
    $e->execute([$pid, $ws, $we]);
    $row = $e->fetch();
    $lit = (float)$row['lit'];
    $tot = round((float)$row['tot'], 2);
    $precio = $lit > 0 ? round($tot / $lit, 4) : 0;
    $db->prepare("UPDATE productores_cierres SET litros=?, precio_litro=?, total_factura=? WHERE id=?")
       ->execute([$lit, $precio, $tot, $cid]);
    recompute_cierres($db, $pid);
}

/**
 * Cierre automático DIARIO para TODOS los productores.
 * auto_close_past_weeks() corre normalmente al abrir la ficha de un productor,
 * pero eso obliga a entrar a cada uno. Esta función corre una vez al día (la
 * primera visita de cualquier usuario al sistema cada día) y cierra/recalcula
 * las semanas vencidas de todos los productores sin tener que abrirlos.
 *
 * Marca la última corrida en un archivo (no requiere tabla extra) para que sea
 * idempotente y no se repita en cada request.
 */
function daily_auto_close_all(): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    $marker = __DIR__ . '/public/.autocierre';
    $hoy    = date('Y-m-d');
    if (is_file($marker) && trim((string)@file_get_contents($marker)) === $hoy) return;

    try {
        auto_close_past_weeks(null); // todas las semanas vencidas, todos los productores
        $db  = get_db();
        $ids = $db->query("SELECT DISTINCT productor_id FROM productores_cierres")
                  ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $pid) recompute_cierres($db, (int)$pid);
        // Marcar como hecho SOLO si todo salió bien; si falla, se reintenta hoy mismo.
        @file_put_contents($marker, $hoy);
    } catch (Throwable $e) {
        error_log('[daily_auto_close_all] ' . $e->getMessage());
    }
}

function current_user(): array {
    return [
        'id'     => $_SESSION['user_id']   ?? null,
        'nombre' => $_SESSION['user_nombre'] ?? '',
        'email'  => $_SESSION['user_email']  ?? '',
        'rol'    => $_SESSION['user_rol']    ?? '',
    ];
}

function is_admin(): bool {
    return ($_SESSION['user_rol'] ?? '') === 'admin';
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    $token = $_POST['_csrf'] ?? '';
    if ($token !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        die('Token CSRF inválido.');
    }
}

function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = compact('msg', 'type');
}

function get_flash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_money(mixed $v, string $currency = 'USD'): string {
    $n = number_format((float)$v, 2, '.', ',');
    return $currency === 'USD' ? "\${$n}" : "Bs. {$n}";
}

/**
 * Devuelve la tasa de cambio actual (Bs/USD) desde la BD.
 * Se cachea en memoria por request para no hacer múltiples queries.
 */
function get_tasa(): float {
    static $tasa = null;
    if ($tasa !== null) return $tasa;
    try {
        $row = get_db()->query('SELECT tasa FROM tasa_cambio ORDER BY fecha DESC LIMIT 1')->fetchColumn();
        $tasa = $row ? (float)$row : 1.0;
    } catch (Exception $e) {
        $tasa = 1.0;
    }
    return $tasa;
}

/**
 * Muestra un monto en las dos monedas: USD y Bolívares.
 * Si el monto ya está en VES, lo convierte a USD usando la tasa.
 * Devuelve HTML con dos líneas: dólares encima, bolívares abajo.
 */
function fmt_dual(mixed $v, string $currency = 'USD', bool $compact = false): string {
    $tasa = get_tasa();
    $val  = (float)$v;
    if ($currency === 'USD') {
        $usd = $val;
        $ves = $tasa > 0 ? $val * $tasa : 0;
    } else {
        $ves = $val;
        $usd = $tasa > 0 ? $val / $tasa : 0;
    }
    $susd = '$' . number_format($usd, 2, '.', ',');
    $sves = 'Bs. ' . number_format($ves, 2, '.', ',');
    if ($compact) {
        return "<span class=\"fw-semibold\">{$susd}</span> <small class=\"text-muted\">/ {$sves}</small>";
    }
    return "<span class=\"d-block fw-bold\">{$susd}</span><small class=\"text-muted d-block\">{$sves}</small>";
}

/**
 * Convierte cualquier representación de booleano (PostgreSQL 't'/'f', MySQL 1/0,
 * PHP true/false, o strings 'activo'/'inactivo') a bool PHP para mostrar en pantalla.
 */
function bool_estado(mixed $v): bool {
    if ($v === true || $v === 1) return true;
    if ($v === false || $v === 0) return false;
    return in_array((string)$v, ['1', 't', 'true', 'True', 'TRUE', 'activo'], true);
}

/**
 * Convierte 'activo'/'inactivo' del formulario a entero 1/0 para guardar en BD.
 * Usar enteros en vez de bool PHP: PDO convierte false→'' (cadena vacía) que
 * PostgreSQL no puede castear a boolean.
 */
function form_bool(mixed $v): int {
    return (in_array($v, ['activo', '1', 'true', true, 1], true)) ? 1 : 0;
}

/**
 * Devuelve true si el motor activo es MySQL/MariaDB (XAMPP local).
 * Devuelve false si es PostgreSQL (Replit producción).
 */
function is_mysql(): bool {
    static $r = null;
    if ($r === null) $r = !getenv('DATABASE_URL');
    return $r;
}

function fmt_date(?string $d): string {
    if (!$d) return '—';
    $dt = new DateTime($d);
    return $dt->format('d/m/Y');
}

/**
 * Devuelve la IP del cliente actual (respetando proxies comunes).
 */
function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            return substr($ip, 0, 45);
        }
    }
    return '';
}

/**
 * Registra una entrada en auditoria_sistema. No lanza excepciones —
 * la auditoría nunca debe romper el flujo principal.
 *
 * @param string $modulo   Nombre corto del módulo (productores, ventas, etc.)
 * @param string $accion   Texto descriptivo de la acción realizada
 * @param mixed  $detalle  Datos adicionales (se guardan como JSON)
 */
function audit_log(string $modulo, string $accion, mixed $detalle = null): void {
    try {
        $db = get_db();
        $st = $db->prepare(
            'INSERT INTO auditoria_sistema
             (usuario_id, bitacora_sesion_id, modulo, accion, detalle_json, metodo_http, ruta, ip)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $_SESSION['user_id']         ?? null,
            $_SESSION['bitacora_sesion_id'] ?? null,
            substr($modulo, 0, 100),
            substr($accion, 0, 150),
            $detalle !== null ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
            substr($_SERVER['REQUEST_METHOD'] ?? '', 0, 10),
            substr($_SERVER['REQUEST_URI']    ?? '', 0, 255),
            client_ip(),
        ]);
    } catch (Exception $e) {
        // Silencioso: no romper la app si la auditoría falla
        error_log('[audit_log] ' . $e->getMessage());
    }
}

/**
 * Abre una entrada en bitacora_sesiones cuando el usuario inicia sesión.
 * Guarda el ID en $_SESSION['bitacora_sesion_id'] para enlazar futuras auditorías.
 */
function audit_session_start(array $usuario): void {
    try {
        $db = get_db();
        $token = bin2hex(random_bytes(32));
        $st = $db->prepare(
            'INSERT INTO bitacora_sesiones
             (usuario_id, nombre_usuario, email, rol, session_token, ip, user_agent, estado)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $usuario['id'],
            substr($usuario['nombre'] ?? '', 0, 150),
            substr($usuario['email']  ?? '', 0, 150),
            substr($usuario['rol']    ?? '', 0, 50),
            $token,
            client_ip(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'activa',
        ]);
        // Recuperar ID compatible con MySQL y PostgreSQL
        $_SESSION['bitacora_sesion_id'] = (int)$db->lastInsertId(
            is_mysql() ? null : 'bitacora_sesiones_id_seq'
        );
        $_SESSION['session_token'] = $token;
    } catch (Exception $e) {
        error_log('[audit_session_start] ' . $e->getMessage());
    }
}

/**
 * Cierra la entrada en bitacora_sesiones cuando el usuario hace logout.
 */
function audit_session_end(): void {
    if (empty($_SESSION['bitacora_sesion_id'])) return;
    try {
        $db = get_db();
        $db->prepare("UPDATE bitacora_sesiones SET fin_sesion = NOW(), estado = 'cerrada' WHERE id = ?")
           ->execute([(int)$_SESSION['bitacora_sesion_id']]);
    } catch (Exception $e) {
        error_log('[audit_session_end] ' . $e->getMessage());
    }
}

/**
 * Actualiza ultima_actividad de la sesión activa (llamada en cada request).
 */
function audit_session_touch(): void {
    if (empty($_SESSION['bitacora_sesion_id'])) return;
    try {
        $db = get_db();
        $db->prepare('UPDATE bitacora_sesiones SET ultima_actividad = NOW() WHERE id = ?')
           ->execute([(int)$_SESSION['bitacora_sesion_id']]);
    } catch (Exception $e) {
        // Silencioso
    }
}
