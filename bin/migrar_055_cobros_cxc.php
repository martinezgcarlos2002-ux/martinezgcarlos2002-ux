<?php
declare(strict_types=1);

/**
 * Migración 055 + parche de datos: cobros de facturas (cxc_pagos) en el libro de caja.
 *
 *   php bin/migrar_055_cobros_cxc.php --dry-run            # simula todo y deshace al final
 *   php bin/migrar_055_cobros_cxc.php                       # aplica desde 2026-09-01
 *   php bin/migrar_055_cobros_cxc.php --desde=2026-10-01
 *   Multiempresa: ADARA_EMPRESA_DB=adara_castillo php bin/migrar_055_cobros_cxc.php
 *
 * Paso 1 (esquema): agrega 'cobro_factura' al enum origen, las columnas cxc_pago_id / cxc_factura_id
 *   y amplía ck_caja_origen. Lee el enum y el CHECK actuales en vez de reescribirlos, así sirve
 *   también en bases que no tienen las migraciones de gas. Cada cambio se salta si ya está.
 *   El DDL de MySQL hace commit implícito: por eso va antes y fuera de la transacción del paso 2.
 *
 * Paso 2 (datos): cada pago aplicado (no saldo a favor) desde --desde sin asiento en caja recibe su
 *   ingreso con la fecha original del pago, a través de Caja::ingresoCobroFactura (misma lógica que
 *   el flujo en vivo). Todo en una transacción: o entran todos o ninguno. Re-ejecutarlo no duplica.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/bootstrap.php';

$opts = getopt('', ['desde::', 'dry-run']);
$desde = (string) ($opts['desde'] ?? '2026-09-01');
$simular = array_key_exists('dry-run', $opts);
if (DateTimeImmutable::createFromFormat('!Y-m-d', $desde)?->format('Y-m-d') !== $desde) {
    fwrite(STDERR, "Fecha --desde inválida (AAAA-MM-DD): $desde\n");
    exit(2);
}

try {
    $db = get_db();
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo conectar: ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Paso 1: esquema
// ---------------------------------------------------------------------
try {
    $columna = $db->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimientos' AND COLUMN_NAME = :c"
    );

    $columna->execute(['c' => 'origen']);
    $tipoOrigen = (string) $columna->fetchColumn();
    if (!preg_match("/^enum\((.*)\)$/i", $tipoOrigen, $m)) {
        throw new RuntimeException("caja_movimientos.origen no es ENUM: $tipoOrigen");
    }
    if (!str_contains($m[1], "'cobro_factura'")) {
        $db->exec("ALTER TABLE caja_movimientos MODIFY origen ENUM({$m[1]},'cobro_factura') NOT NULL");
        echo "OK   origen += cobro_factura\n";
    } else {
        echo "SKIP origen ya incluye cobro_factura\n";
    }

    $columna->execute(['c' => 'cxc_pago_id']);
    if ($columna->fetchColumn() === false) {
        $db->exec(
            'ALTER TABLE caja_movimientos
                 ADD COLUMN cxc_pago_id    BIGINT UNSIGNED NULL,
                 ADD COLUMN cxc_factura_id INT UNSIGNED    NULL,
                 ADD UNIQUE KEY uq_caja_cxc_pago (cxc_pago_id),
                 ADD KEY ix_caja_cxc_factura (cxc_factura_id),
                 ADD CONSTRAINT fk_caja_cxc_pago FOREIGN KEY (cxc_pago_id) REFERENCES cxc_pagos (id)
                     ON DELETE RESTRICT ON UPDATE RESTRICT,
                 ADD CONSTRAINT fk_caja_cxc_factura FOREIGN KEY (cxc_factura_id) REFERENCES cxc_facturas (id)
                     ON DELETE RESTRICT ON UPDATE RESTRICT'
        );
        echo "OK   columnas cxc_pago_id, cxc_factura_id\n";
    } else {
        echo "SKIP columnas cxc_pago_id, cxc_factura_id\n";
    }

    $check = $db->query(
        "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimientos' AND CONSTRAINT_NAME = 'ck_caja_origen'"
    )->fetchColumn();
    if ($check === false) {
        echo "SKIP ck_caja_origen no existe en esta base\n";
    } elseif (!str_contains((string) $check, 'cobro_factura')) {
        $db->exec('ALTER TABLE caja_movimientos DROP CONSTRAINT ck_caja_origen');
        $db->exec(
            "ALTER TABLE caja_movimientos ADD CONSTRAINT ck_caja_origen CHECK (($check)
                 OR (origen = 'cobro_factura' AND cxc_pago_id IS NOT NULL AND cxc_factura_id IS NOT NULL))"
        );
        echo "OK   ck_caja_origen += cobro_factura\n";
    } else {
        echo "SKIP ck_caja_origen ya incluye cobro_factura\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR en el esquema (los cambios previos de este paso ya quedaron aplicados): ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Paso 2: parche de datos
// ---------------------------------------------------------------------
$caja = new Caja($db);
$creados = 0;
$totalUsd = 0;
try {
    $db->beginTransaction();

    // FOR UPDATE: un pago registrado o anulado mientras corre el parche espera a que termine.
    $pendientes = $db->prepare(
        "SELECT p.id, p.fecha, p.monto, p.metodo, p.registrado_por, f.numero_factura
         FROM cxc_pagos p
         JOIN cxc_facturas f ON f.id = p.factura_id
         WHERE p.estado = 'aplicado'
           AND p.metodo <> 'saldo_favor'
           AND p.fecha >= :desde
           AND NOT EXISTS (SELECT 1 FROM caja_movimientos m WHERE m.cxc_pago_id = p.id)
         ORDER BY p.fecha, p.id
         FOR UPDATE"
    );
    $pendientes->execute(['desde' => $desde]);
    $pagos = $pendientes->fetchAll();
    echo sprintf("Pagos sin asiento desde %s: %d\n", $desde, count($pagos));

    foreach ($pagos as $p) {
        try {
            $movId = $caja->ingresoCobroFactura((int) $p['id'], (int) $p['registrado_por']);
        } catch (ValidationException $e) {
            throw new RuntimeException(sprintf('Pago #%d (factura %s): %s', $p['id'], $p['numero_factura'], implode(' ', $e->errors)), 0, $e);
        }
        if ($movId === null) {
            continue;
        }
        $creados++;
        $totalUsd += Caja::aCentavos($p['monto']);
        echo sprintf("  + caja #%d  pago #%d  factura %-12s %s  %-13s $%s\n",
            $movId, $p['id'], $p['numero_factura'], $p['fecha'], $p['metodo'], $p['monto']);
    }

    if ($simular) {
        $db->rollBack();
        echo sprintf("DRY-RUN: se crearían %d asientos por $%s. No se guardó nada.\n", $creados, Caja::desdeCentavos($totalUsd));
    } else {
        $db->commit();
        echo sprintf("Listo: %d asientos creados por $%s.\n", $creados, Caja::desdeCentavos($totalUsd));
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[ADARA][migrar_055] ' . $e);
    fwrite(STDERR, 'ERROR en el parche de datos, no se guardó ningún asiento: ' . $e->getMessage() . "\n");
    exit(1);
}
