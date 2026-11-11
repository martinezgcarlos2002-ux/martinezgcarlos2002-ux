-- =====================================================================
-- ADARA · Paso a producción: migraciones 050 a 057 consolidadas
--   Base destino: la seleccionada al ejecutar (sin USE). En producción: u980302731_adarabd.
--   Requisito: sql/produccion_040_049.sql ya aplicado (producción estaba en la 039,
--   verificado contra el dump 2026-11-10 23:37:56).
--   Ejecutar UNA sola vez, con respaldo previo. MariaDB no revierte DDL: si falla a mitad,
--   restaurar el respaldo y volver a empezar desde produccion_040_049.sql.
--   Usa DELIMITER (triggers): ejecutar con el cliente mysql o la pestaña SQL de phpMyAdmin.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

-- ##################### 050 · recuperación de contraseña #####################
ALTER TABLE usuarios
    ADD COLUMN reset_token      VARCHAR(64) NULL AFTER password_hash,
    ADD COLUMN reset_expiracion DATETIME    NULL AFTER reset_token,
    ADD UNIQUE KEY uq_usuarios_reset_token (reset_token),
    ADD CONSTRAINT ck_usuarios_reset CHECK ((reset_token IS NULL) = (reset_expiracion IS NULL));

-- ##################### 051 · el cierre de productor es un corte de cuenta #####################
DROP TRIGGER IF EXISTS trg_entregas_semana_abierta_ins;
DELIMITER $$
CREATE TRIGGER trg_entregas_semana_abierta_ins BEFORE INSERT ON productores_entregas FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM productores_cierres c
        WHERE c.productor_id = NEW.productor_id
          AND NEW.fecha <= c.semana_fin
          AND c.estado <> 'anulada'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La recoleccion hasta el ultimo corte ya esta cerrada';
    END IF;
END$$
DELIMITER ;

-- ##################### 052 · trazabilidad del abono distribuido #####################
ALTER TABLE cxc_pagos
    ADD COLUMN lote_hash           VARCHAR(20)    NULL AFTER lote,
    ADD COLUMN monto_global_origen DECIMAL(10,2)  NULL AFTER lote_hash,
    ADD COLUMN tasa_cambio_dia     DECIMAL(10,2)  NULL AFTER monto_global_origen,
    ADD KEY ix_cxc_pagos_lote_hash (lote_hash);

-- ##################### 053 · foto del cilindro de gas #####################
ALTER TABLE gas_cilindros
    ADD COLUMN foto_url VARCHAR(255) NULL AFTER estado_registro;

-- ##################### 054 · eliminar bombonas sin historial #####################
DROP TRIGGER IF EXISTS trg_gas_cil_no_delete;
DELIMITER $$
CREATE TRIGGER trg_gas_cil_no_delete BEFORE DELETE ON gas_cilindros FOR EACH ROW
BEGIN
    IF OLD.despacho_abierto_id IS NOT NULL
       OR EXISTS (SELECT 1 FROM gas_despacho_cilindros WHERE cilindro_id = OLD.id)
       OR EXISTS (SELECT 1 FROM gas_reparaciones WHERE cilindro_id = OLD.id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La bombona ya tiene historial: archivela, no se elimina';
    END IF;
END$$
DELIMITER ;

-- ##################### 055 + 056 · cobros de CxC en caja y autor en modo soporte #####################
-- Sin PREPARE: MariaDB 10.4 rechaza un ALTER armado en variable (error cerca de NULL).
-- El procedimiento usa IF y ALTER directo, y no pide gas_reparacion_id si la columna no existe.
DROP PROCEDURE IF EXISTS adara_caja_055;
DELIMITER $$
CREATE PROCEDURE adara_caja_055()
BEGIN
    DECLARE v_pago INT;
    DECLARE v_rep INT;

    SELECT COUNT(*) INTO v_pago
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimientos' AND COLUMN_NAME = 'gas_pago_id';
    IF v_pago = 0 AND (SELECT COUNT(*) FROM information_schema.TABLES
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gas_pagos') = 1 THEN
        ALTER TABLE caja_movimientos
            ADD COLUMN gas_pago_id BIGINT UNSIGNED NULL AFTER abono_id,
            ADD UNIQUE KEY uq_caja_gas_pago (gas_pago_id),
            ADD CONSTRAINT fk_caja_gas_pago FOREIGN KEY (gas_pago_id) REFERENCES gas_pagos (id)
                ON DELETE RESTRICT ON UPDATE RESTRICT;
        SET v_pago = 1;
    END IF;

    SELECT COUNT(*) INTO v_rep
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimientos' AND COLUMN_NAME = 'gas_reparacion_id';
    IF v_rep = 0 AND v_pago = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES
                                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gas_reparaciones') = 1 THEN
        ALTER TABLE caja_movimientos
            ADD COLUMN gas_reparacion_id INT UNSIGNED NULL AFTER gas_pago_id,
            ADD UNIQUE KEY uq_caja_gas_reparacion (gas_reparacion_id),
            ADD CONSTRAINT fk_caja_gas_reparacion FOREIGN KEY (gas_reparacion_id) REFERENCES gas_reparaciones (id)
                ON DELETE RESTRICT ON UPDATE RESTRICT;
        SET v_rep = 1;
    END IF;

    ALTER TABLE caja_movimientos
        MODIFY origen ENUM(
            'manual','venta','anulacion_venta','pago_productor','nomina','contra_asiento','pago_gas','reparacion_gas','cobro_factura'
        ) NOT NULL;

    IF (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimientos' AND COLUMN_NAME = 'soporte_super_admin_id') = 0 THEN
        ALTER TABLE caja_movimientos
            ADD COLUMN soporte_super_admin_id INT UNSIGNED NULL AFTER registrado_por;
    END IF;

    IF (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimientos' AND COLUMN_NAME = 'cxc_pago_id') = 0 THEN
        ALTER TABLE caja_movimientos
            ADD COLUMN cxc_pago_id BIGINT UNSIGNED NULL AFTER soporte_super_admin_id,
            ADD COLUMN cxc_factura_id INT UNSIGNED NULL AFTER cxc_pago_id,
            ADD UNIQUE KEY uq_caja_cxc_pago (cxc_pago_id),
            ADD KEY ix_caja_cxc_factura (cxc_factura_id),
            ADD CONSTRAINT fk_caja_cxc_pago FOREIGN KEY (cxc_pago_id) REFERENCES cxc_pagos (id)
                ON DELETE RESTRICT ON UPDATE RESTRICT,
            ADD CONSTRAINT fk_caja_cxc_factura FOREIGN KEY (cxc_factura_id) REFERENCES cxc_facturas (id)
                ON DELETE RESTRICT ON UPDATE RESTRICT;
    END IF;

    BEGIN
        DECLARE CONTINUE HANDLER FOR 1091 BEGIN END;
        ALTER TABLE caja_movimientos DROP CONSTRAINT ck_caja_origen;
    END;

    IF v_pago = 1 AND v_rep = 1 THEN
        ALTER TABLE caja_movimientos ADD CONSTRAINT ck_caja_origen CHECK (
            (origen IN ('venta','anulacion_venta') AND venta_id IS NOT NULL)
            OR (origen = 'pago_productor' AND (abono_id IS NOT NULL OR cierre_id IS NOT NULL))
            OR (origen = 'nomina' AND nomina_id IS NOT NULL)
            OR (origen = 'contra_asiento' AND revierte_id IS NOT NULL)
            OR (origen = 'pago_gas' AND gas_pago_id IS NOT NULL)
            OR (origen = 'reparacion_gas' AND gas_reparacion_id IS NOT NULL)
            OR (origen = 'cobro_factura' AND cxc_pago_id IS NOT NULL)
            OR origen = 'manual'
        );
    ELSEIF v_pago = 1 THEN
        ALTER TABLE caja_movimientos ADD CONSTRAINT ck_caja_origen CHECK (
            (origen IN ('venta','anulacion_venta') AND venta_id IS NOT NULL)
            OR (origen = 'pago_productor' AND (abono_id IS NOT NULL OR cierre_id IS NOT NULL))
            OR (origen = 'nomina' AND nomina_id IS NOT NULL)
            OR (origen = 'contra_asiento' AND revierte_id IS NOT NULL)
            OR (origen = 'pago_gas' AND gas_pago_id IS NOT NULL)
            OR (origen = 'cobro_factura' AND cxc_pago_id IS NOT NULL)
            OR origen = 'manual'
        );
    ELSE
        ALTER TABLE caja_movimientos ADD CONSTRAINT ck_caja_origen CHECK (
            (origen IN ('venta','anulacion_venta') AND venta_id IS NOT NULL)
            OR (origen = 'pago_productor' AND (abono_id IS NOT NULL OR cierre_id IS NOT NULL))
            OR (origen = 'nomina' AND nomina_id IS NOT NULL)
            OR (origen = 'contra_asiento' AND revierte_id IS NOT NULL)
            OR (origen = 'cobro_factura' AND cxc_pago_id IS NOT NULL)
            OR origen = 'manual'
        );
    END IF;
END$$
DELIMITER ;
CALL adara_caja_055();
DROP PROCEDURE adara_caja_055;

-- ##################### 057 · anticipos de clientes #####################
ALTER TABLE cxc_pagos
    MODIFY factura_id INT UNSIGNED NULL,
    ADD CONSTRAINT ck_cxc_pagos_anticipo CHECK (factura_id IS NOT NULL OR metodo <> 'saldo_favor');

DROP TRIGGER IF EXISTS trg_cxc_pagos_no_update;
DELIMITER $$
CREATE TRIGGER trg_cxc_pagos_no_update BEFORE UPDATE ON cxc_pagos FOR EACH ROW
BEGIN
    IF NOT (
        OLD.estado = 'aplicado' AND NEW.estado = 'anulado'
        AND NEW.monto = OLD.monto AND NEW.factura_id <=> OLD.factura_id
        AND NEW.cliente_id = OLD.cliente_id AND NEW.metodo = OLD.metodo
        AND NEW.fecha = OLD.fecha
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos de CxC no se modifican; solo se anulan';
    END IF;
END$$
DELIMITER ;

ALTER TABLE cxc_saldo_movimientos
    MODIFY tipo ENUM('devolucion','abono_excedente','aplicado_factura','reverso','anticipo') NOT NULL;

ALTER TABLE cxc_saldo_movimientos DROP CONSTRAINT ck_cxc_smov_signo;
ALTER TABLE cxc_saldo_movimientos ADD CONSTRAINT ck_cxc_smov_signo CHECK (
       (tipo = 'aplicado_factura' AND monto < 0 AND pago_id IS NOT NULL)
    OR (tipo = 'devolucion' AND monto > 0 AND devolucion_id IS NOT NULL)
    OR (tipo = 'abono_excedente' AND monto > 0 AND lote IS NOT NULL)
    OR (tipo = 'anticipo' AND monto > 0 AND pago_id IS NOT NULL AND factura_id IS NULL AND devolucion_id IS NULL)
    OR (tipo = 'reverso' AND monto <> 0 AND (pago_id IS NOT NULL OR devolucion_id IS NOT NULL))
);

-- ##################### 055 · carga retroactiva de cobros desde 2026-09-01 #####################
-- Misma regla que Caja::ingresoCobroFactura(): fecha del pago con la hora de registro, banco para
-- pago móvil y transferencia, VES si el pago trae monto_bs, y la tasa del pago o la vigente ese día.
-- Los pagos sin tasa posible se saltan y salen en la consulta de verificación de abajo.
INSERT INTO caja_movimientos
    (fecha, tipo, cuenta, moneda, monto, tasa_bs_por_usd, monto_usd, concepto, origen, cxc_pago_id, cxc_factura_id, registrado_por)
SELECT x.momento, 'ingreso',
       IF(x.metodo IN ('pago_movil','transferencia'), 'banco', 'caja'),
       IF(x.monto_bs IS NULL, 'USD', 'VES'),
       COALESCE(x.monto_bs, x.monto),
       x.tasa,
       x.monto,
       LEFT(CONCAT('Cobro factura Nro ', x.numero_factura, ' - Cliente: ', x.cliente,
                   IF(TRIM(COALESCE(x.referencia, '')) <> '', CONCAT(' Ref ', TRIM(x.referencia)), '')), 160),
       'cobro_factura', x.id, x.factura_id, x.registrado_por
FROM (
    SELECT p.id, p.factura_id, p.monto, p.monto_bs, p.metodo, p.referencia, p.registrado_por,
           CAST(CONCAT(p.fecha, ' ', TIME(p.creado_en)) AS DATETIME) AS momento,
           f.numero_factura, c.nombre AS cliente,
           COALESCE(p.tasa_cambio, (
               SELECT t.tasa_bs_por_usd FROM tasas_cambio t
               WHERE t.vigente_desde <= CAST(CONCAT(p.fecha, ' ', TIME(p.creado_en)) AS DATETIME)
               ORDER BY t.vigente_desde DESC, t.id DESC LIMIT 1
           )) AS tasa
    FROM cxc_pagos p
    JOIN cxc_facturas f ON f.id = p.factura_id
    JOIN cxc_clientes c ON c.id = p.cliente_id
    WHERE p.estado = 'aplicado' AND p.metodo <> 'saldo_favor' AND p.fecha >= '2026-09-01'
      AND NOT EXISTS (SELECT 1 FROM caja_movimientos m WHERE m.cxc_pago_id = p.id)
) x
WHERE x.tasa IS NOT NULL
ORDER BY x.momento, x.id;

-- Verificación: debe dar 0 filas. Si aparece alguna, registra la tasa de esa fecha y repite solo el INSERT.
SELECT p.id AS pago_sin_asiento, p.fecha, p.monto, p.metodo
FROM cxc_pagos p
WHERE p.estado = 'aplicado' AND p.metodo <> 'saldo_favor' AND p.fecha >= '2026-09-01'
  AND NOT EXISTS (SELECT 1 FROM caja_movimientos m WHERE m.cxc_pago_id = p.id);
