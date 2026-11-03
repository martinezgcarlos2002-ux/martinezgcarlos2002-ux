-- =====================================================================
-- ADARA · Migración 041: capacidades, código de despacho y reparaciones
--
-- El costo del viaje deja de ser precio único × cantidad.
-- Cada bombona aporta el precio de su capacidad (10, 15, 27 o 43 kg),
-- congelado en gas_despacho_cilindros.subtotal_usd.
-- costo_total_usd = SUM(subtotal_usd) de las líneas. Lo reescribe
-- gas_recalcular_despacho().
--
-- codigo_despacho (DESP-000001) lo asigna el trigger con gas_correlativos.
-- MariaDB 10.4 no permite una columna generada sobre el AUTO_INCREMENT.
-- fecha_entrega ya nacía NULL; el alta la fuerza a NULL y solo se llena
-- al marcar el viaje entregado.
--
-- La bombona que ya existía queda en 10 kg y el viaje conserva su tarifa
-- histórica en los cuatro precios, para no mover el costo ya calculado.
-- El número de factura manual, si había, pasa a observaciones.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP TRIGGER IF EXISTS trg_gas_despacho_bi;
DROP TRIGGER IF EXISTS trg_gas_despacho_bu;
DROP TRIGGER IF EXISTS trg_gas_despacho_au;
DROP TRIGGER IF EXISTS trg_gas_dc_bi;
DROP PROCEDURE IF EXISTS gas_recalcular_despacho;

ALTER TABLE gas_cilindros
    ADD COLUMN capacidad_kg TINYINT UNSIGNED NULL AFTER serial;

UPDATE gas_cilindros SET capacidad_kg = 10 WHERE capacidad_kg IS NULL;

ALTER TABLE gas_cilindros
    MODIFY capacidad_kg TINYINT UNSIGNED NOT NULL,
    ADD CONSTRAINT ck_gas_cil_cap CHECK (capacidad_kg IN (10, 15, 27, 43)),
    ADD KEY ix_gas_cil_cap_fisico (estado_registro, capacidad_kg, estado_fisico);

ALTER TABLE gas_despacho_cilindros
    ADD COLUMN capacidad_kg TINYINT UNSIGNED NULL AFTER estado_fisico;

UPDATE gas_despacho_cilindros dc
JOIN gas_cilindros c ON c.id = dc.cilindro_id
   SET dc.capacidad_kg = c.capacidad_kg
 WHERE dc.capacidad_kg IS NULL;

ALTER TABLE gas_despacho_cilindros
    MODIFY capacidad_kg TINYINT UNSIGNED NOT NULL DEFAULT 10,
    ADD CONSTRAINT ck_gas_dc_cap CHECK (capacidad_kg IN (10, 15, 27, 43));

ALTER TABLE gas_despachos
    ADD COLUMN precio_10_usd DECIMAL(12,2) NULL AFTER fecha_entrega,
    ADD COLUMN precio_15_usd DECIMAL(12,2) NULL AFTER precio_10_usd,
    ADD COLUMN precio_27_usd DECIMAL(12,2) NULL AFTER precio_15_usd,
    ADD COLUMN precio_43_usd DECIMAL(12,2) NULL AFTER precio_27_usd,
    ADD COLUMN codigo_despacho VARCHAR(20) NULL AFTER id;

UPDATE gas_despachos
   SET precio_10_usd = precio_unitario_usd,
       precio_15_usd = precio_unitario_usd,
       precio_27_usd = precio_unitario_usd,
       precio_43_usd = precio_unitario_usd,
       codigo_despacho = CONCAT('DESP-', LPAD(id, 6, '0')),
       observaciones = NULLIF(TRIM(CONCAT(COALESCE(observaciones, ''),
           CASE WHEN nro_factura IS NOT NULL AND TRIM(nro_factura) <> ''
                THEN CONCAT(' Factura ', TRIM(nro_factura)) ELSE '' END)), '');

ALTER TABLE gas_despachos
    DROP CONSTRAINT ck_gas_despacho_precio,
    DROP CONSTRAINT ck_gas_despacho_costo,
    DROP COLUMN nro_factura,
    DROP COLUMN precio_unitario_usd,
    MODIFY codigo_despacho VARCHAR(20) NOT NULL,
    MODIFY precio_10_usd DECIMAL(12,2) NOT NULL,
    MODIFY precio_15_usd DECIMAL(12,2) NOT NULL,
    MODIFY precio_27_usd DECIMAL(12,2) NOT NULL,
    MODIFY precio_43_usd DECIMAL(12,2) NOT NULL,
    MODIFY fecha_entrega DATE NULL,
    ADD UNIQUE KEY uq_gas_despacho_codigo (codigo_despacho),
    ADD CONSTRAINT ck_gas_despacho_precios CHECK (
        precio_10_usd > 0 AND precio_15_usd > 0 AND precio_27_usd > 0 AND precio_43_usd > 0
    ),
    ADD CONSTRAINT ck_gas_despacho_costo CHECK (costo_total_usd >= 0);

CREATE TABLE gas_correlativos (
    clave  VARCHAR(20)     NOT NULL,
    ultimo INT UNSIGNED    NOT NULL,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO gas_correlativos (clave, ultimo)
SELECT 'despacho', COALESCE(MAX(id), 0) FROM gas_despachos;

-- Reparación de una bombona. El egreso vive en caja_movimientos (origen reparacion_gas).
CREATE TABLE gas_reparaciones (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    cilindro_id     INT UNSIGNED  NOT NULL,
    responsable     VARCHAR(120)  NOT NULL,
    fecha_salida    DATE          NOT NULL,
    costo_usd       DECIMAL(12,2) NULL,
    estado          ENUM('en_reparacion','reparado') NOT NULL DEFAULT 'en_reparacion',
    fecha_retorno   DATE          NULL,
    registrado_por  INT UNSIGNED  NOT NULL,
    creado_en       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_gas_rep_cilindro (cilindro_id, estado),
    KEY ix_gas_rep_estado (estado, fecha_salida),
    CONSTRAINT fk_gas_rep_cilindro FOREIGN KEY (cilindro_id) REFERENCES gas_cilindros (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_rep_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_gas_rep_responsable CHECK (CHAR_LENGTH(TRIM(responsable)) >= 2),
    CONSTRAINT ck_gas_rep_estado CHECK (
        (estado = 'en_reparacion' AND costo_usd IS NULL AND fecha_retorno IS NULL)
        OR (estado = 'reparado' AND costo_usd > 0 AND fecha_retorno IS NOT NULL AND fecha_retorno >= fecha_salida)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE caja_movimientos
    MODIFY origen ENUM(
        'manual','venta','anulacion_venta','pago_productor','nomina','contra_asiento','pago_gas','reparacion_gas'
    ) NOT NULL,
    ADD COLUMN gas_reparacion_id INT UNSIGNED NULL AFTER gas_pago_id,
    ADD UNIQUE KEY uq_caja_gas_reparacion (gas_reparacion_id),
    ADD CONSTRAINT fk_caja_gas_reparacion FOREIGN KEY (gas_reparacion_id) REFERENCES gas_reparaciones (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE caja_movimientos DROP CONSTRAINT ck_caja_origen;
ALTER TABLE caja_movimientos ADD CONSTRAINT ck_caja_origen CHECK (
    (origen IN ('venta','anulacion_venta') AND venta_id IS NOT NULL)
    OR (origen = 'pago_productor' AND (abono_id IS NOT NULL OR cierre_id IS NOT NULL))
    OR (origen = 'nomina' AND nomina_id IS NOT NULL)
    OR (origen = 'contra_asiento' AND revierte_id IS NOT NULL)
    OR (origen = 'pago_gas' AND gas_pago_id IS NOT NULL)
    OR (origen = 'reparacion_gas' AND gas_reparacion_id IS NOT NULL)
    OR origen = 'manual'
);

DELIMITER $$

CREATE PROCEDURE gas_recalcular_despacho(IN p_despacho_id INT UNSIGNED)
BEGIN
    DECLARE v_cant INT UNSIGNED;
    DECLARE v_costo DECIMAL(12,2);
    DECLARE v_pagado DECIMAL(12,2);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @gas_recalc = NULL;
        RESIGNAL;
    END;

    SELECT COUNT(*), COALESCE(SUM(subtotal_usd), 0)
      INTO v_cant, v_costo
      FROM gas_despacho_cilindros
     WHERE despacho_id = p_despacho_id;

    SELECT COALESCE(SUM(monto_usd), 0) INTO v_pagado
      FROM gas_pagos
     WHERE despacho_id = p_despacho_id
       AND anulado_en IS NULL;

    SET @gas_recalc = 1;
    UPDATE gas_despachos
       SET cantidad = v_cant,
           costo_total_usd = v_costo,
           pagado_usd = v_pagado,
           estado_deuda = gas_estado_deuda(v_costo, v_pagado)
     WHERE id = p_despacho_id;
    SET @gas_recalc = NULL;
END$$

CREATE TRIGGER trg_gas_despacho_bi BEFORE INSERT ON gas_despachos FOR EACH ROW
BEGIN
    DECLARE v_estado VARCHAR(20);

    SELECT estado INTO v_estado FROM gas_proveedores WHERE id = NEW.proveedor_id;
    IF v_estado IS NULL OR v_estado <> 'activo' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El proveedor de gas no esta activo';
    END IF;

    UPDATE gas_correlativos
       SET ultimo = LAST_INSERT_ID(ultimo + 1)
     WHERE clave = 'despacho';
    SET NEW.codigo_despacho = CONCAT('DESP-', LPAD(LAST_INSERT_ID(), 6, '0'));
    SET NEW.fecha_entrega = NULL;
    SET NEW.cantidad = 0;
    SET NEW.costo_total_usd = 0.00;
    SET NEW.pagado_usd = 0.00;
    SET NEW.estado_deuda = 'pendiente';
END$$

CREATE TRIGGER trg_gas_despacho_bu BEFORE UPDATE ON gas_despachos FOR EACH ROW
BEGIN
    DECLARE v_cant INT UNSIGNED;
    DECLARE v_costo DECIMAL(12,2);
    DECLARE v_estado_prov VARCHAR(20);

    SET NEW.codigo_despacho = OLD.codigo_despacho;

    IF @gas_recalc = 1 THEN
        IF NOT (NEW.proveedor_id = OLD.proveedor_id
                AND NEW.fecha_salida = OLD.fecha_salida
                AND NEW.fecha_entrega <=> OLD.fecha_entrega
                AND NEW.precio_10_usd = OLD.precio_10_usd
                AND NEW.precio_15_usd = OLD.precio_15_usd
                AND NEW.precio_27_usd = OLD.precio_27_usd
                AND NEW.precio_43_usd = OLD.precio_43_usd
                AND NEW.observaciones <=> OLD.observaciones
                AND NEW.registrado_por = OLD.registrado_por) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El recalculo del despacho altero datos operativos';
        END IF;
    ELSE
        IF OLD.pagado_usd > 0 AND (
            NEW.precio_10_usd <> OLD.precio_10_usd
            OR NEW.precio_15_usd <> OLD.precio_15_usd
            OR NEW.precio_27_usd <> OLD.precio_27_usd
            OR NEW.precio_43_usd <> OLD.precio_43_usd
            OR NEW.proveedor_id <> OLD.proveedor_id
            OR NEW.fecha_salida <> OLD.fecha_salida
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho ya tiene abonos: anulelos antes de corregirlo';
        END IF;
        IF OLD.fecha_entrega IS NOT NULL AND NEW.fecha_entrega IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un despacho entregado no vuelve a transito';
        END IF;
        IF NEW.proveedor_id <> OLD.proveedor_id THEN
            SELECT estado INTO v_estado_prov FROM gas_proveedores WHERE id = NEW.proveedor_id;
            IF v_estado_prov <> 'activo' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El proveedor de gas no esta activo';
            END IF;
        END IF;

        IF NEW.precio_10_usd <> OLD.precio_10_usd
           OR NEW.precio_15_usd <> OLD.precio_15_usd
           OR NEW.precio_27_usd <> OLD.precio_27_usd
           OR NEW.precio_43_usd <> OLD.precio_43_usd THEN
            SELECT COUNT(*), COALESCE(SUM(
                       CASE capacidad_kg
                           WHEN 10 THEN NEW.precio_10_usd
                           WHEN 15 THEN NEW.precio_15_usd
                           WHEN 27 THEN NEW.precio_27_usd
                           WHEN 43 THEN NEW.precio_43_usd
                       END
                   ), 0)
              INTO v_cant, v_costo
              FROM gas_despacho_cilindros
             WHERE despacho_id = OLD.id;
            SET NEW.cantidad = v_cant;
            SET NEW.costo_total_usd = v_costo;
            SET NEW.estado_deuda = gas_estado_deuda(NEW.costo_total_usd, NEW.pagado_usd);
        ELSE
            SET NEW.cantidad = OLD.cantidad;
            SET NEW.costo_total_usd = OLD.costo_total_usd;
            SET NEW.pagado_usd = OLD.pagado_usd;
            SET NEW.estado_deuda = OLD.estado_deuda;
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_gas_despacho_au AFTER UPDATE ON gas_despachos FOR EACH ROW
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @gas_precio = NULL;
        SET @gas_ciclo = NULL;
        RESIGNAL;
    END;

    IF @gas_recalc IS NULL AND (
        NEW.precio_10_usd <> OLD.precio_10_usd
        OR NEW.precio_15_usd <> OLD.precio_15_usd
        OR NEW.precio_27_usd <> OLD.precio_27_usd
        OR NEW.precio_43_usd <> OLD.precio_43_usd
    ) THEN
        SET @gas_precio = 1;
        UPDATE gas_despacho_cilindros
           SET precio_unitario_usd = CASE capacidad_kg
                   WHEN 10 THEN NEW.precio_10_usd
                   WHEN 15 THEN NEW.precio_15_usd
                   WHEN 27 THEN NEW.precio_27_usd
                   ELSE NEW.precio_43_usd
               END,
               subtotal_usd = CASE capacidad_kg
                   WHEN 10 THEN NEW.precio_10_usd
                   WHEN 15 THEN NEW.precio_15_usd
                   WHEN 27 THEN NEW.precio_27_usd
                   ELSE NEW.precio_43_usd
               END
         WHERE despacho_id = NEW.id;
        SET @gas_precio = NULL;
    END IF;

    IF OLD.fecha_entrega IS NULL AND NEW.fecha_entrega IS NOT NULL THEN
        SET @gas_ciclo = 1;
        UPDATE gas_cilindros
           SET despacho_abierto_id = NULL
         WHERE despacho_abierto_id = NEW.id;
        SET @gas_ciclo = NULL;
    END IF;
END$$

CREATE TRIGGER trg_gas_dc_bi BEFORE INSERT ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    DECLARE v_pertenencia VARCHAR(10);
    DECLARE v_tipo VARCHAR(20);
    DECLARE v_empleado INT UNSIGNED;
    DECLARE v_transportista INT UNSIGNED;
    DECLARE v_productor INT UNSIGNED;
    DECLARE v_nombre VARCHAR(120);
    DECLARE v_fisico VARCHAR(20);
    DECLARE v_registro VARCHAR(20);
    DECLARE v_abierto INT UNSIGNED;
    DECLARE v_cap TINYINT UNSIGNED;
    DECLARE v_precio DECIMAL(12,2);
    DECLARE v_p10 DECIMAL(12,2);
    DECLARE v_p15 DECIMAL(12,2);
    DECLARE v_p27 DECIMAL(12,2);
    DECLARE v_p43 DECIMAL(12,2);
    DECLARE v_pagado DECIMAL(12,2);
    DECLARE v_entrega DATE;
    DECLARE v_cilindro_id INT UNSIGNED;
    DECLARE v_despacho_id INT UNSIGNED;
    DECLARE v_foto_nombre VARCHAR(120);
    DECLARE v_reparacion INT UNSIGNED;

    SET v_cilindro_id = NEW.cilindro_id;
    SET v_despacho_id = NEW.despacho_id;

    SELECT pertenencia, tipo_tercero, empleado_id, transportista_id, productor_id,
           propietario_nombre, estado_fisico, estado_registro, despacho_abierto_id, capacidad_kg
      INTO v_pertenencia, v_tipo, v_empleado, v_transportista, v_productor,
           v_nombre, v_fisico, v_registro, v_abierto, v_cap
      FROM gas_cilindros
     WHERE id = v_cilindro_id
       FOR UPDATE;

    IF v_pertenencia IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona no existe';
    END IF;
    IF v_registro <> 'activo' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona esta archivada';
    END IF;
    IF v_abierto IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona ya esta en un despacho sin entregar';
    END IF;

    SELECT COUNT(*) INTO v_reparacion
      FROM gas_reparaciones
     WHERE cilindro_id = v_cilindro_id AND estado = 'en_reparacion';
    IF v_reparacion > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona esta en reparacion';
    END IF;

    SELECT precio_10_usd, precio_15_usd, precio_27_usd, precio_43_usd, pagado_usd, fecha_entrega
      INTO v_p10, v_p15, v_p27, v_p43, v_pagado, v_entrega
      FROM gas_despachos
     WHERE id = v_despacho_id
       FOR UPDATE;

    IF v_p10 IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho no existe';
    END IF;
    IF v_entrega IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho ya fue entregado';
    END IF;
    IF v_pagado > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho ya tiene abonos';
    END IF;

    SET v_precio = CASE v_cap
        WHEN 10 THEN v_p10
        WHEN 15 THEN v_p15
        WHEN 27 THEN v_p27
        WHEN 43 THEN v_p43
        ELSE NULL
    END;
    IF v_precio IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona no tiene una capacidad de 10, 15, 27 o 43 kg';
    END IF;

    SET NEW.capacidad_kg = v_cap;
    SET NEW.pertenencia = v_pertenencia;
    SET NEW.tipo_tercero = v_tipo;
    SET NEW.empleado_id = v_empleado;
    SET NEW.transportista_id = v_transportista;
    SET NEW.productor_id = v_productor;
    SET NEW.estado_fisico = v_fisico;
    SET NEW.precio_unitario_usd = v_precio;
    SET NEW.subtotal_usd = v_precio;
    SET NEW.cargo_destino = NULL;
    SET NEW.cargo_ref_id = NULL;
    SET NEW.cargo_en = NULL;

    IF v_pertenencia = 'propio' THEN
        SET NEW.propietario_nombre = NULL;
        SET NEW.cargo_estado = 'no_aplica';
    ELSE
        IF v_tipo = 'empleado' THEN
            SELECT CONCAT(nombres, ' ', apellidos) INTO v_foto_nombre
              FROM empleados WHERE id = v_empleado;
        ELSEIF v_tipo = 'transportista' THEN
            SELECT nombre INTO v_foto_nombre
              FROM log_transportistas WHERE id = v_transportista;
        ELSEIF v_tipo = 'productor' THEN
            SELECT nombre INTO v_foto_nombre
              FROM productores WHERE id = v_productor;
        ELSE
            SET v_foto_nombre = v_nombre;
        END IF;
        SET NEW.propietario_nombre = v_foto_nombre;
        SET NEW.cargo_estado = 'pendiente';
    END IF;
END$$

CREATE TRIGGER trg_gas_rep_bi BEFORE INSERT ON gas_reparaciones FOR EACH ROW
BEGIN
    DECLARE v_fisico VARCHAR(20);
    DECLARE v_registro VARCHAR(20);
    DECLARE v_abierto INT UNSIGNED;
    DECLARE v_cilindro_id INT UNSIGNED;

    SET v_cilindro_id = NEW.cilindro_id;

    IF NEW.estado <> 'en_reparacion' OR NEW.costo_usd IS NOT NULL OR NEW.fecha_retorno IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La reparacion nace en curso, sin costo ni retorno';
    END IF;

    SELECT estado_fisico, estado_registro, despacho_abierto_id
      INTO v_fisico, v_registro, v_abierto
      FROM gas_cilindros
     WHERE id = v_cilindro_id
       FOR UPDATE;

    IF v_fisico IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona no existe';
    END IF;
    IF v_registro <> 'activo' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona esta archivada';
    END IF;
    IF v_fisico NOT IN ('regular', 'mal_estado') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se repara una bombona en estado regular o mal estado';
    END IF;
    IF v_abierto IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona esta en un despacho sin entregar';
    END IF;
END$$

DELIMITER ;
