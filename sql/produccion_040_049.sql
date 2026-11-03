-- =====================================================================
-- ADARA · Paso a producción: migraciones 040 a 049 consolidadas
--   Base destino: la seleccionada al ejecutar (sin USE). En producción: u980302731_adarabd.
--   Requisito: producción al día hasta la migración 039 (verificado contra el dump 2026-11-02 22:19:33).
--   Ejecutar UNA sola vez, con respaldo previo. MariaDB no revierte DDL: si falla a mitad, restaurar el respaldo.
--   Usa DELIMITER (triggers y procedimiento): ejecutar con el cliente mysql o la pestaña SQL de phpMyAdmin.
-- =====================================================================

-- ##################### 040_modulo_gas.sql #####################
-- =====================================================================
-- ADARA · Migración 040: Módulo de Gestión de Gas
--   Proveedores, bombonas, despachos de recarga y abonos al proveedor.
--   Semana operativa: sábado a viernes (igual que el resto del ERP).
--
-- Cilindros de terceros
--   gas_cilindros guarda al dueño VIGENTE con llaves excluyentes: uno solo
--   entre empleado_id, transportista_id, productor_id o propietario_nombre
--   (tipo 'otro'). Así se puede unir después a nómina, liquidación de leche
--   o CxP de transporte sin un id polimórfico.
--   gas_despacho_cilindros fotografía ese dueño al armar el viaje. Si la
--   bombona cambia de manos, el viaje ya cerrado conserva a quién había
--   que cobrarle ese llenado. cargo_estado queda 'pendiente' hasta que
--   nómina, liquidación o CxP tome la línea (cargo_destino + cargo_ref_id).
--   Una bombona en un despacho sin entregar ocupa gas_cilindros.despacho_abierto_id
--   para que no salga en dos viajes a la vez.
--
-- Saldo deudor (USD)
--   costo_total_usd = precio_unitario_usd × cantidad de bombonas del viaje.
--   pagado_usd      = SUM(gas_pagos.monto_usd) con anulado_en IS NULL.
--   saldo_usd       = costo_total_usd − pagado_usd   (columna generada).
--   estado_deuda, calculado por gas_estado_deuda():
--       pagado = 0                  → pendiente
--       0 < pagado < costo          → saldo_restante
--       pagado = costo y costo > 0  → cancelado
--   Esos tres importes no se editan: los reescribe gas_recalcular_despacho().
--   El precio del viaje sí se edita, y se copia a cada línea, mientras no
--   exista un abono vigente. Con abonos, primero se anula el pago.
--
-- Libro mayor
--   Cada abono es la cuenta por pagar del despacho. El egreso de caja NO se
--   inserta aquí: la gaveta no puede quedar negativa y esa regla vive en
--   Caja.php. Después del INSERT en gas_pagos la aplicación inserta
--   caja_movimientos (tipo egreso, origen 'pago_gas', gas_pago_id).
--   Al anular, no se borra el abono: se marca anulado_* y se revierte el
--   asiento con Caja::contraAsiento(). El recálculo reabre el saldo.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- Proveedores. eliminado = archivo: la fila sigue para no romper viajes.
-- ---------------------------------------------------------------------

CREATE TABLE gas_proveedores (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    documento       VARCHAR(12)  NULL,
    nombre          VARCHAR(120) NOT NULL,
    contacto        VARCHAR(80)  NULL,
    telefono        VARCHAR(20)  NULL,
    correo          VARCHAR(100) NULL,
    direccion       VARCHAR(160) NULL,
    notas           VARCHAR(255) NULL,
    estado          ENUM('activo','inactivo','eliminado') NOT NULL DEFAULT 'activo',
    registrado_por  INT UNSIGNED NOT NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gas_prov_documento (documento),
    UNIQUE KEY uq_gas_prov_nombre (nombre),
    KEY ix_gas_prov_estado (estado, nombre),
    CONSTRAINT fk_gas_prov_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_gas_prov_documento CHECK (documento IS NULL OR documento REGEXP '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
    CONSTRAINT ck_gas_prov_nombre    CHECK (CHAR_LENGTH(TRIM(nombre)) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Bombona. serial es la etiqueta física. estado_fisico alerta; no bloquea
-- el despacho (a veces hay que devolver la bombona dañada al proveedor).
-- ---------------------------------------------------------------------

CREATE TABLE gas_cilindros (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    serial              VARCHAR(40)  NOT NULL,
    estado_fisico       ENUM('optimo','regular','mal_estado') NOT NULL DEFAULT 'optimo',
    observacion_fisica  VARCHAR(255) NULL,
    pertenencia         ENUM('propio','tercero') NOT NULL DEFAULT 'propio',
    tipo_tercero        ENUM('empleado','transportista','productor','otro') NULL,
    empleado_id         INT UNSIGNED NULL,
    transportista_id    INT UNSIGNED NULL,
    productor_id        INT UNSIGNED NULL,
    propietario_nombre  VARCHAR(120) NULL,
    despacho_abierto_id INT UNSIGNED NULL,
    estado_registro     ENUM('activo','archivado') NOT NULL DEFAULT 'activo',
    registrado_por      INT UNSIGNED NOT NULL,
    creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gas_cil_serial (serial),
    KEY ix_gas_cil_fisico (estado_fisico, estado_registro),
    KEY ix_gas_cil_empleado (empleado_id),
    KEY ix_gas_cil_transportista (transportista_id),
    KEY ix_gas_cil_productor (productor_id),
    KEY ix_gas_cil_abierto (despacho_abierto_id),
    CONSTRAINT fk_gas_cil_empleado FOREIGN KEY (empleado_id) REFERENCES empleados (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_cil_transportista FOREIGN KEY (transportista_id) REFERENCES log_transportistas (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_cil_productor FOREIGN KEY (productor_id) REFERENCES productores (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_cil_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    -- Dueño vigente: propio no lleva tercero; cada tipo de tercero llena una sola llave.
    CONSTRAINT ck_gas_cil_dueno CHECK (
        (pertenencia = 'propio'
            AND tipo_tercero IS NULL
            AND empleado_id IS NULL AND transportista_id IS NULL AND productor_id IS NULL
            AND propietario_nombre IS NULL)
        OR (pertenencia = 'tercero' AND tipo_tercero = 'empleado'
            AND empleado_id IS NOT NULL
            AND transportista_id IS NULL AND productor_id IS NULL AND propietario_nombre IS NULL)
        OR (pertenencia = 'tercero' AND tipo_tercero = 'transportista'
            AND transportista_id IS NOT NULL
            AND empleado_id IS NULL AND productor_id IS NULL AND propietario_nombre IS NULL)
        OR (pertenencia = 'tercero' AND tipo_tercero = 'productor'
            AND productor_id IS NOT NULL
            AND empleado_id IS NULL AND transportista_id IS NULL AND propietario_nombre IS NULL)
        OR (pertenencia = 'tercero' AND tipo_tercero = 'otro'
            AND empleado_id IS NULL AND transportista_id IS NULL AND productor_id IS NULL
            AND propietario_nombre IS NOT NULL AND CHAR_LENGTH(TRIM(propietario_nombre)) >= 2)
    ),
    CONSTRAINT ck_gas_cil_serial CHECK (CHAR_LENGTH(TRIM(serial)) >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Viaje de recarga. cantidad, costo, pagado y estado_deuda son caché.
-- saldo_usd siempre es costo − pagado.
-- semana_inicio cae en sábado: fecha − (DAYOFWEEK(fecha) % 7).
-- ---------------------------------------------------------------------

CREATE TABLE gas_despachos (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    proveedor_id        INT UNSIGNED  NOT NULL,
    fecha_salida        DATE          NOT NULL,
    fecha_entrega       DATE          NULL,
    semana_inicio       DATE          AS (DATE_SUB(fecha_salida, INTERVAL (DAYOFWEEK(fecha_salida) % 7) DAY)) STORED,
    precio_unitario_usd DECIMAL(12,2) NOT NULL,
    cantidad            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    costo_total_usd     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pagado_usd          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    saldo_usd           DECIMAL(12,2) AS (costo_total_usd - pagado_usd) STORED,
    estado_deuda        ENUM('pendiente','saldo_restante','cancelado') NOT NULL DEFAULT 'pendiente',
    nro_factura         VARCHAR(40)   NULL,
    observaciones       VARCHAR(255)  NULL,
    registrado_por      INT UNSIGNED  NOT NULL,
    creado_en           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_gas_despacho_prov (proveedor_id, fecha_salida),
    KEY ix_gas_despacho_semana (semana_inicio, estado_deuda),
    KEY ix_gas_despacho_entrega (fecha_entrega),
    CONSTRAINT fk_gas_despacho_prov FOREIGN KEY (proveedor_id) REFERENCES gas_proveedores (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_despacho_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_gas_despacho_precio CHECK (precio_unitario_usd > 0),
    CONSTRAINT ck_gas_despacho_fechas CHECK (fecha_entrega IS NULL OR fecha_entrega >= fecha_salida),
    CONSTRAINT ck_gas_despacho_costo  CHECK (costo_total_usd = precio_unitario_usd * cantidad),
    CONSTRAINT ck_gas_despacho_pagado CHECK (pagado_usd >= 0 AND pagado_usd <= costo_total_usd),
    -- Debe coincidir con gas_estado_deuda().
    CONSTRAINT ck_gas_despacho_deuda CHECK (
        (pagado_usd = 0 AND estado_deuda = 'pendiente')
        OR (pagado_usd > 0 AND pagado_usd < costo_total_usd AND estado_deuda = 'saldo_restante')
        OR (costo_total_usd > 0 AND pagado_usd = costo_total_usd AND estado_deuda = 'cancelado')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE gas_cilindros
    ADD CONSTRAINT fk_gas_cil_abierto FOREIGN KEY (despacho_abierto_id) REFERENCES gas_despachos (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

-- ---------------------------------------------------------------------
-- Bombonas de un viaje. La foto del dueño y el precio quedan congelados.
-- subtotal_usd es el monto a descontar si la bombona es de un tercero.
-- ---------------------------------------------------------------------

CREATE TABLE gas_despacho_cilindros (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    despacho_id         INT UNSIGNED    NOT NULL,
    cilindro_id         INT UNSIGNED    NOT NULL,
    -- El BEFORE INSERT reescribe la foto del dueño y el precio; estos
    -- defaults solo permiten insertar (despacho_id, cilindro_id).
    pertenencia         ENUM('propio','tercero') NOT NULL DEFAULT 'propio',
    tipo_tercero        ENUM('empleado','transportista','productor','otro') NULL,
    empleado_id         INT UNSIGNED    NULL,
    transportista_id    INT UNSIGNED    NULL,
    productor_id        INT UNSIGNED    NULL,
    propietario_nombre  VARCHAR(120)    NULL,
    estado_fisico       ENUM('optimo','regular','mal_estado') NOT NULL DEFAULT 'optimo',
    precio_unitario_usd DECIMAL(12,2)   NOT NULL DEFAULT 0.01,
    subtotal_usd        DECIMAL(12,2)   NOT NULL DEFAULT 0.01,
    cargo_estado        ENUM('no_aplica','pendiente','cargado') NOT NULL DEFAULT 'no_aplica',
    cargo_destino       ENUM('nomina','liquidacion_leche','cxp_transporte','manual') NULL,
    cargo_ref_id        BIGINT UNSIGNED NULL,
    cargo_en            DATETIME        NULL,
    creado_en           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gas_dc_despacho_cil (despacho_id, cilindro_id),
    KEY ix_gas_dc_cilindro (cilindro_id),
    KEY ix_gas_dc_cargo_empleado (cargo_estado, empleado_id),
    KEY ix_gas_dc_cargo_productor (cargo_estado, productor_id),
    KEY ix_gas_dc_cargo_transportista (cargo_estado, transportista_id),
    CONSTRAINT fk_gas_dc_despacho FOREIGN KEY (despacho_id) REFERENCES gas_despachos (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_dc_cilindro FOREIGN KEY (cilindro_id) REFERENCES gas_cilindros (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_dc_empleado FOREIGN KEY (empleado_id) REFERENCES empleados (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_dc_transportista FOREIGN KEY (transportista_id) REFERENCES log_transportistas (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_dc_productor FOREIGN KEY (productor_id) REFERENCES productores (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_gas_dc_precio CHECK (precio_unitario_usd > 0 AND subtotal_usd = precio_unitario_usd),
    -- Misma regla de dueño que gas_cilindros, con el nombre ya resuelto para el histórico.
    CONSTRAINT ck_gas_dc_dueno CHECK (
        (pertenencia = 'propio'
            AND tipo_tercero IS NULL
            AND empleado_id IS NULL AND transportista_id IS NULL AND productor_id IS NULL
            AND propietario_nombre IS NULL)
        OR (pertenencia = 'tercero' AND tipo_tercero = 'empleado'
            AND empleado_id IS NOT NULL AND propietario_nombre IS NOT NULL
            AND transportista_id IS NULL AND productor_id IS NULL)
        OR (pertenencia = 'tercero' AND tipo_tercero = 'transportista'
            AND transportista_id IS NOT NULL AND propietario_nombre IS NOT NULL
            AND empleado_id IS NULL AND productor_id IS NULL)
        OR (pertenencia = 'tercero' AND tipo_tercero = 'productor'
            AND productor_id IS NOT NULL AND propietario_nombre IS NOT NULL
            AND empleado_id IS NULL AND transportista_id IS NULL)
        OR (pertenencia = 'tercero' AND tipo_tercero = 'otro'
            AND propietario_nombre IS NOT NULL AND CHAR_LENGTH(TRIM(propietario_nombre)) >= 2
            AND empleado_id IS NULL AND transportista_id IS NULL AND productor_id IS NULL)
    ),
    CONSTRAINT ck_gas_dc_cargo CHECK (
        (pertenencia = 'propio' AND cargo_estado = 'no_aplica'
            AND cargo_destino IS NULL AND cargo_ref_id IS NULL AND cargo_en IS NULL)
        OR (pertenencia = 'tercero' AND cargo_estado = 'pendiente'
            AND cargo_destino IS NULL AND cargo_ref_id IS NULL AND cargo_en IS NULL)
        OR (pertenencia = 'tercero' AND cargo_estado = 'cargado'
            AND cargo_destino IS NOT NULL AND cargo_ref_id IS NOT NULL AND cargo_en IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Abonos al proveedor. monto_usd es lo que baja el saldo del despacho.
-- La tasa del día se guarda siempre (también en USD) para el libro mayor.
-- ---------------------------------------------------------------------

CREATE TABLE gas_pagos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    despacho_id     INT UNSIGNED    NOT NULL,
    fecha           DATE            NOT NULL,
    semana_inicio   DATE            AS (DATE_SUB(fecha, INTERVAL (DAYOFWEEK(fecha) % 7) DAY)) STORED,
    metodo          ENUM('efectivo','pago_movil','transferencia','otro') NOT NULL,
    cuenta          ENUM('caja','banco') NOT NULL,
    moneda          ENUM('USD','VES') NOT NULL,
    monto           DECIMAL(12,2)   NOT NULL,
    tasa_bs_por_usd DECIMAL(14,4)   NOT NULL,
    monto_usd       DECIMAL(12,2)   NOT NULL,
    referencia      VARCHAR(40)     NULL,
    registrado_por  INT UNSIGNED    NOT NULL,
    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    anulado_en      DATETIME        NULL,
    anulado_por     INT UNSIGNED    NULL,
    motivo_anulacion VARCHAR(255)   NULL,
    PRIMARY KEY (id),
    KEY ix_gas_pago_despacho (despacho_id, anulado_en),
    KEY ix_gas_pago_semana (semana_inicio),
    CONSTRAINT fk_gas_pago_despacho FOREIGN KEY (despacho_id) REFERENCES gas_despachos (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_pago_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gas_pago_anulador FOREIGN KEY (anulado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_gas_pago_montos CHECK (monto > 0 AND monto_usd > 0 AND tasa_bs_por_usd > 0),
    CONSTRAINT ck_gas_pago_usd CHECK (
        (moneda = 'USD' AND monto_usd = monto)
        OR (moneda = 'VES' AND monto_usd = ROUND(monto / tasa_bs_por_usd, 2))
    ),
    CONSTRAINT ck_gas_pago_cuenta CHECK (
        (metodo = 'efectivo' AND cuenta = 'caja')
        OR (metodo IN ('pago_movil','transferencia') AND cuenta = 'banco')
        OR metodo = 'otro'
    ),
    CONSTRAINT ck_gas_pago_referencia CHECK (
        metodo NOT IN ('pago_movil','transferencia')
        OR (referencia IS NOT NULL AND TRIM(referencia) <> '')
    ),
    CONSTRAINT ck_gas_pago_anulacion CHECK (
        (anulado_en IS NULL AND anulado_por IS NULL AND motivo_anulacion IS NULL)
        OR (anulado_en IS NOT NULL AND anulado_por IS NOT NULL
            AND motivo_anulacion IS NOT NULL AND CHAR_LENGTH(TRIM(motivo_anulacion)) >= 3)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Gancho al libro de caja. Mismo patrón que abono_id de productores.
-- ---------------------------------------------------------------------

ALTER TABLE caja_movimientos
    MODIFY origen ENUM(
        'manual','venta','anulacion_venta','pago_productor','nomina','contra_asiento','pago_gas'
    ) NOT NULL,
    ADD COLUMN gas_pago_id BIGINT UNSIGNED NULL AFTER abono_id,
    ADD UNIQUE KEY uq_caja_gas_pago (gas_pago_id),
    ADD CONSTRAINT fk_caja_gas_pago FOREIGN KEY (gas_pago_id) REFERENCES gas_pagos (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE caja_movimientos DROP CONSTRAINT ck_caja_origen;
ALTER TABLE caja_movimientos ADD CONSTRAINT ck_caja_origen CHECK (
    (origen IN ('venta','anulacion_venta') AND venta_id IS NOT NULL)
    OR (origen = 'pago_productor' AND (abono_id IS NOT NULL OR cierre_id IS NOT NULL))
    OR (origen = 'nomina' AND nomina_id IS NOT NULL)
    OR (origen = 'contra_asiento' AND revierte_id IS NOT NULL)
    OR (origen = 'pago_gas' AND gas_pago_id IS NOT NULL)
    OR origen = 'manual'
);

-- ---------------------------------------------------------------------
-- Consultas de auditoría semanal y de cargos a terceros todavía abiertos.
-- ---------------------------------------------------------------------

CREATE OR REPLACE VIEW v_gas_inversion_semanal AS
SELECT d.semana_inicio,
       DATE_ADD(d.semana_inicio, INTERVAL 6 DAY) AS semana_fin,
       d.proveedor_id,
       p.nombre AS proveedor,
       COUNT(*) AS despachos,
       SUM(d.cantidad) AS cilindros,
       SUM(d.costo_total_usd) AS invertido_usd,
       SUM(d.pagado_usd) AS pagado_usd,
       SUM(d.saldo_usd) AS saldo_usd
  FROM gas_despachos d
  JOIN gas_proveedores p ON p.id = d.proveedor_id
 GROUP BY d.semana_inicio, d.proveedor_id, p.nombre, semana_fin;

CREATE OR REPLACE VIEW v_gas_egresos_semana AS
SELECT g.semana_inicio,
       DATE_ADD(g.semana_inicio, INTERVAL 6 DAY) AS semana_fin,
       COUNT(*) AS abonos,
       SUM(g.monto_usd) AS egreso_usd
  FROM gas_pagos g
 WHERE g.anulado_en IS NULL
 GROUP BY g.semana_inicio, semana_fin;

CREATE OR REPLACE VIEW v_gas_cargos_terceros AS
SELECT dc.id AS linea_id,
       dc.despacho_id,
       d.fecha_salida,
       d.semana_inicio,
       dc.cilindro_id,
       c.serial,
       dc.tipo_tercero,
       dc.empleado_id,
       dc.transportista_id,
       dc.productor_id,
       dc.propietario_nombre,
       dc.subtotal_usd
  FROM gas_despacho_cilindros dc
  JOIN gas_despachos d ON d.id = dc.despacho_id
  JOIN gas_cilindros c ON c.id = dc.cilindro_id
 WHERE dc.cargo_estado = 'pendiente';

DELIMITER $$

CREATE FUNCTION gas_estado_deuda(p_costo DECIMAL(12,2), p_pagado DECIMAL(12,2))
RETURNS VARCHAR(20)
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_pagado <= 0 THEN 'pendiente'
        WHEN p_pagado < p_costo THEN 'saldo_restante'
        ELSE 'cancelado'
    END;
END$$

CREATE PROCEDURE gas_recalcular_despacho(IN p_despacho_id INT UNSIGNED)
BEGIN
    DECLARE v_cant INT UNSIGNED;
    DECLARE v_precio DECIMAL(12,2);
    DECLARE v_costo DECIMAL(12,2);
    DECLARE v_pagado DECIMAL(12,2);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @gas_recalc = NULL;
        RESIGNAL;
    END;

    SELECT COUNT(*) INTO v_cant
      FROM gas_despacho_cilindros
     WHERE despacho_id = p_despacho_id;

    SELECT precio_unitario_usd INTO v_precio
      FROM gas_despachos
     WHERE id = p_despacho_id;

    SELECT COALESCE(SUM(monto_usd), 0) INTO v_pagado
      FROM gas_pagos
     WHERE despacho_id = p_despacho_id
       AND anulado_en IS NULL;

    SET v_costo = ROUND(v_precio * v_cant, 2);
    SET @gas_recalc = 1;
    UPDATE gas_despachos
       SET cantidad = v_cant,
           costo_total_usd = v_costo,
           pagado_usd = v_pagado,
           estado_deuda = gas_estado_deuda(v_costo, v_pagado)
     WHERE id = p_despacho_id;
    SET @gas_recalc = NULL;
END$$

CREATE TRIGGER trg_gas_prov_no_delete BEFORE DELETE ON gas_proveedores FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El proveedor no se elimina: paselo a estado eliminado';
END$$

CREATE TRIGGER trg_gas_cil_no_delete BEFORE DELETE ON gas_cilindros FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La bombona no se elimina: archivela para conservar los viajes';
END$$

CREATE TRIGGER trg_gas_cil_bu BEFORE UPDATE ON gas_cilindros FOR EACH ROW
BEGIN
    IF NEW.serial <> OLD.serial THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El serial de la bombona no se modifica';
    END IF;
    IF @gas_ciclo IS NULL AND NOT (NEW.despacho_abierto_id <=> OLD.despacho_abierto_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El viaje abierto de la bombona lo asigna el despacho';
    END IF;
    IF NEW.estado_registro = 'archivado' AND NEW.despacho_abierto_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se archiva una bombona que esta en un viaje abierto';
    END IF;
END$$

CREATE TRIGGER trg_gas_despacho_bi BEFORE INSERT ON gas_despachos FOR EACH ROW
BEGIN
    DECLARE v_estado VARCHAR(20);

    SELECT estado INTO v_estado FROM gas_proveedores WHERE id = NEW.proveedor_id;
    IF v_estado IS NULL OR v_estado <> 'activo' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El proveedor de gas no esta activo';
    END IF;

    SET NEW.cantidad = 0;
    SET NEW.costo_total_usd = 0.00;
    SET NEW.pagado_usd = 0.00;
    SET NEW.estado_deuda = 'pendiente';
END$$

CREATE TRIGGER trg_gas_despacho_bu BEFORE UPDATE ON gas_despachos FOR EACH ROW
BEGIN
    DECLARE v_cant INT UNSIGNED;
    DECLARE v_estado_prov VARCHAR(20);

    IF @gas_recalc = 1 THEN
        IF NOT (NEW.proveedor_id = OLD.proveedor_id
                AND NEW.fecha_salida = OLD.fecha_salida
                AND NEW.fecha_entrega <=> OLD.fecha_entrega
                AND NEW.precio_unitario_usd = OLD.precio_unitario_usd
                AND NEW.nro_factura <=> OLD.nro_factura
                AND NEW.observaciones <=> OLD.observaciones
                AND NEW.registrado_por = OLD.registrado_por) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El recalculo del despacho altero datos operativos';
        END IF;
    ELSE
        IF OLD.pagado_usd > 0 AND (
            NEW.precio_unitario_usd <> OLD.precio_unitario_usd
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

        IF NEW.precio_unitario_usd <> OLD.precio_unitario_usd THEN
            SELECT COUNT(*) INTO v_cant FROM gas_despacho_cilindros WHERE despacho_id = OLD.id;
            SET NEW.cantidad = v_cant;
            SET NEW.costo_total_usd = ROUND(NEW.precio_unitario_usd * v_cant, 2);
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

    IF @gas_recalc IS NULL AND NEW.precio_unitario_usd <> OLD.precio_unitario_usd THEN
        SET @gas_precio = 1;
        UPDATE gas_despacho_cilindros
           SET precio_unitario_usd = NEW.precio_unitario_usd,
               subtotal_usd = NEW.precio_unitario_usd
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

CREATE TRIGGER trg_gas_despacho_bd BEFORE DELETE ON gas_despachos FOR EACH ROW
BEGIN
    IF OLD.cantidad > 0 OR OLD.pagado_usd > 0 OR OLD.fecha_entrega IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se borra un despacho vacio y sin entrega';
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
    DECLARE v_precio DECIMAL(12,2);
    DECLARE v_pagado DECIMAL(12,2);
    DECLARE v_entrega DATE;
    DECLARE v_cilindro_id INT UNSIGNED;
    DECLARE v_despacho_id INT UNSIGNED;
    DECLARE v_foto_nombre VARCHAR(120);

    SET v_cilindro_id = NEW.cilindro_id;
    SET v_despacho_id = NEW.despacho_id;

    SELECT pertenencia, tipo_tercero, empleado_id, transportista_id, productor_id,
           propietario_nombre, estado_fisico, estado_registro, despacho_abierto_id
      INTO v_pertenencia, v_tipo, v_empleado, v_transportista, v_productor,
           v_nombre, v_fisico, v_registro, v_abierto
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

    SELECT precio_unitario_usd, pagado_usd, fecha_entrega
      INTO v_precio, v_pagado, v_entrega
      FROM gas_despachos
     WHERE id = v_despacho_id
       FOR UPDATE;

    IF v_precio IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho no existe';
    END IF;
    IF v_entrega IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho ya fue entregado';
    END IF;
    IF v_pagado > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho ya tiene abonos';
    END IF;

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

CREATE TRIGGER trg_gas_dc_bu BEFORE UPDATE ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    IF NOT (NEW.despacho_id = OLD.despacho_id AND NEW.cilindro_id = OLD.cilindro_id
            AND NEW.pertenencia = OLD.pertenencia
            AND NEW.tipo_tercero <=> OLD.tipo_tercero
            AND NEW.empleado_id <=> OLD.empleado_id
            AND NEW.transportista_id <=> OLD.transportista_id
            AND NEW.productor_id <=> OLD.productor_id
            AND NEW.propietario_nombre <=> OLD.propietario_nombre
            AND NEW.estado_fisico = OLD.estado_fisico) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La foto del cilindro en el viaje no se reescribe';
    END IF;

    IF @gas_precio = 1 THEN
        IF NOT (NEW.cargo_estado = OLD.cargo_estado
                AND NEW.cargo_destino <=> OLD.cargo_destino
                AND NEW.cargo_ref_id <=> OLD.cargo_ref_id
                AND NEW.cargo_en <=> OLD.cargo_en) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cambio de precio no toca el cargo al tercero';
        END IF;
    ELSE
        IF NEW.precio_unitario_usd <> OLD.precio_unitario_usd OR NEW.subtotal_usd <> OLD.subtotal_usd THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El precio se edita en el despacho, no en la linea';
        END IF;
        IF OLD.cargo_estado = 'cargado' AND NOT (
            NEW.cargo_estado = OLD.cargo_estado
            AND NEW.cargo_destino <=> OLD.cargo_destino
            AND NEW.cargo_ref_id <=> OLD.cargo_ref_id
            AND NEW.cargo_en <=> OLD.cargo_en
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cargo al tercero ya fue aplicado';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_gas_dc_bd BEFORE DELETE ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    DECLARE v_pagado DECIMAL(12,2);
    DECLARE v_entrega DATE;

    SELECT pagado_usd, fecha_entrega INTO v_pagado, v_entrega
      FROM gas_despachos WHERE id = OLD.despacho_id;

    IF v_entrega IS NOT NULL OR v_pagado > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se quitan bombonas de un despacho entregado o con abonos';
    END IF;
END$$

CREATE TRIGGER trg_gas_dc_ai AFTER INSERT ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    DECLARE v_rows INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @gas_ciclo = NULL;
        RESIGNAL;
    END;

    SET @gas_ciclo = 1;
    UPDATE gas_cilindros
       SET despacho_abierto_id = NEW.despacho_id
     WHERE id = NEW.cilindro_id
       AND despacho_abierto_id IS NULL;
    SET v_rows = ROW_COUNT();
    SET @gas_ciclo = NULL;

    IF v_rows <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se pudo reservar la bombona para este despacho';
    END IF;

    CALL gas_recalcular_despacho(NEW.despacho_id);
END$$

CREATE TRIGGER trg_gas_dc_au AFTER UPDATE ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    IF @gas_precio IS NULL AND NEW.subtotal_usd <> OLD.subtotal_usd THEN
        CALL gas_recalcular_despacho(NEW.despacho_id);
    END IF;
END$$

CREATE TRIGGER trg_gas_dc_ad AFTER DELETE ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @gas_ciclo = NULL;
        RESIGNAL;
    END;

    SET @gas_ciclo = 1;
    UPDATE gas_cilindros
       SET despacho_abierto_id = NULL
     WHERE id = OLD.cilindro_id
       AND despacho_abierto_id = OLD.despacho_id;
    SET @gas_ciclo = NULL;

    CALL gas_recalcular_despacho(OLD.despacho_id);
END$$

CREATE TRIGGER trg_gas_pago_bi BEFORE INSERT ON gas_pagos FOR EACH ROW
BEGIN
    DECLARE v_saldo DECIMAL(12,2);
    DECLARE v_costo DECIMAL(12,2);
    DECLARE v_despacho_id INT UNSIGNED;

    SET v_despacho_id = NEW.despacho_id;

    IF NEW.moneda = 'USD' THEN
        SET NEW.monto_usd = NEW.monto;
    ELSE
        SET NEW.monto_usd = ROUND(NEW.monto / NEW.tasa_bs_por_usd, 2);
    END IF;

    SELECT saldo_usd, costo_total_usd INTO v_saldo, v_costo
      FROM gas_despachos
     WHERE id = v_despacho_id
       FOR UPDATE;

    IF v_costo IS NULL OR v_costo <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho no tiene bombonas que cobrar';
    END IF;
    IF NEW.monto_usd > v_saldo THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El abono supera el saldo del despacho';
    END IF;
    IF NEW.anulado_en IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un abono nuevo no nace anulado';
    END IF;
END$$

CREATE TRIGGER trg_gas_pago_bu BEFORE UPDATE ON gas_pagos FOR EACH ROW
BEGIN
    IF OLD.anulado_en IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El abono ya esta anulado';
    END IF;
    IF NOT (NEW.despacho_id = OLD.despacho_id AND NEW.fecha = OLD.fecha
            AND NEW.metodo = OLD.metodo AND NEW.cuenta = OLD.cuenta
            AND NEW.moneda = OLD.moneda AND NEW.monto = OLD.monto
            AND NEW.tasa_bs_por_usd = OLD.tasa_bs_por_usd AND NEW.monto_usd = OLD.monto_usd
            AND NEW.referencia <=> OLD.referencia AND NEW.registrado_por = OLD.registrado_por) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los datos economicos del abono son inmutables';
    END IF;
END$$

CREATE TRIGGER trg_gas_pago_bd BEFORE DELETE ON gas_pagos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los abonos de gas no se eliminan: se anulan';
END$$

CREATE TRIGGER trg_gas_pago_ai AFTER INSERT ON gas_pagos FOR EACH ROW
BEGIN
    CALL gas_recalcular_despacho(NEW.despacho_id);
END$$

CREATE TRIGGER trg_gas_pago_au AFTER UPDATE ON gas_pagos FOR EACH ROW
BEGIN
    CALL gas_recalcular_despacho(NEW.despacho_id);
END$$

DELIMITER ;

-- ##################### 041_gas_capacidades_reparaciones.sql #####################
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

-- ##################### 042_gas_serial_cilindro.sql #####################
-- =====================================================================
-- ADARA · Migración 042: serial automático de bombonas (CIL-000001)
--
-- MariaDB 10.4 rechaza GENERATED ALWAYS AS sobre una columna
-- AUTO_INCREMENT (ERROR 1901). El número se arma en el BEFORE INSERT
-- con el mismo correlativo de los despachos, y coincide con el id:
-- la bombona 101 queda CIL-000101 y la siguiente será CIL-000102.
--
-- No se borra la fila de prueba: está en un despacho. Solo se reemplaza
-- el serial escrito a mano (042462852) por el código del sistema.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP TRIGGER IF EXISTS trg_gas_cil_bu;
DROP TRIGGER IF EXISTS trg_gas_cil_bi;

UPDATE gas_cilindros
   SET serial = CONCAT('CIL-', LPAD(id, 6, '0'));

INSERT INTO gas_correlativos (clave, ultimo)
SELECT 'cilindro', COALESCE(MAX(id), 0) FROM gas_cilindros
ON DUPLICATE KEY UPDATE ultimo = VALUES(ultimo);

DELIMITER $$

-- MariaDB 10.4 rechaza GENERATED ALWAYS AS sobre AUTO_INCREMENT (ERROR 1901).
-- El BEFORE INSERT toma el siguiente número del correlativo, lo escribe en id
-- y arma el serial CIL-000102. Así el código sigue al id aunque un INSERT
-- revertido haya consumido el contador interno de la tabla.
CREATE TRIGGER trg_gas_cil_bi BEFORE INSERT ON gas_cilindros FOR EACH ROW
BEGIN
    UPDATE gas_correlativos
       SET ultimo = LAST_INSERT_ID(ultimo + 1)
     WHERE clave = 'cilindro';
    SET NEW.id = LAST_INSERT_ID();
    SET NEW.serial = CONCAT('CIL-', LPAD(NEW.id, 6, '0'));
END$$

CREATE TRIGGER trg_gas_cil_bu BEFORE UPDATE ON gas_cilindros FOR EACH ROW
BEGIN
    IF NEW.serial <> OLD.serial THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El serial de la bombona no se modifica';
    END IF;
    SET NEW.serial = OLD.serial;
    IF @gas_ciclo IS NULL AND NOT (NEW.despacho_abierto_id <=> OLD.despacho_abierto_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El viaje abierto de la bombona lo asigna el despacho';
    END IF;
    IF NEW.estado_registro = 'archivado' AND NEW.despacho_abierto_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se archiva una bombona que esta en un viaje abierto';
    END IF;
END$$

DELIMITER ;

-- ##################### 043_gas_quitar_viaje_vacio.sql #####################
-- =====================================================================
-- ADARA · Migración 043: quitar un viaje vacío aunque tenga fecha de entrega
-- Un despacho sin bombonas y sin abonos no conserva historial.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_gas_despacho_bd;

DELIMITER $$

CREATE TRIGGER trg_gas_despacho_bd BEFORE DELETE ON gas_despachos FOR EACH ROW
BEGIN
    IF OLD.cantidad > 0 OR OLD.pagado_usd > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se borra un despacho vacio, sin abonos';
    END IF;
END$$

DELIMITER ;

-- ##################### 044_modulo_bancos.sql #####################
-- =====================================================================
-- ADARA · Migración 044: Conciliación bancaria
--   El día no se digita movimiento por movimiento. Se sube el Excel del
--   cash manager y, si la caja cuadra, se inyecta en una sola transacción.
--
--   Por cuenta y fecha:
--     saldo_final = saldo_inicio + ingresos − egresos − comisiones
--   Solo entra un cierre con estado 'cuadrado'. La pareja cuenta+fecha
--   no se repite: un día ya cerrado no se vuelve a cargar.
--
--   saldo_actual_bs es el saldo_final del cierre más reciente de la cuenta.
--   Los movimientos quedan ligados a ese cierre.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE bancos_cuentas (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alias_cuenta     VARCHAR(80)  NOT NULL,
    saldo_actual_bs  DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    activa           TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bancos_alias (alias_cuenta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bancos_cierres_diarios (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id         INT UNSIGNED NOT NULL,
    fecha             DATE         NOT NULL,
    saldo_inicio      DECIMAL(18,2) NOT NULL,
    total_ingresos    DECIMAL(18,2) NOT NULL,
    total_egresos     DECIMAL(18,2) NOT NULL,
    total_comisiones  DECIMAL(18,2) NOT NULL,
    saldo_final       DECIMAL(18,2) NOT NULL,
    estado            ENUM('cuadrado') NOT NULL DEFAULT 'cuadrado',
    archivo           VARCHAR(180) NULL,
    registrado_por    INT UNSIGNED NOT NULL,
    creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_banco_cierre (cuenta_id, fecha),
    KEY ix_banco_cierre_fecha (fecha),
    CONSTRAINT fk_banco_cierre_cuenta FOREIGN KEY (cuenta_id) REFERENCES bancos_cuentas (id),
    CONSTRAINT fk_banco_cierre_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_banco_cierre_formula CHECK (
        saldo_final = saldo_inicio + total_ingresos - total_egresos - total_comisiones
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bancos_movimientos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id       INT UNSIGNED NOT NULL,
    cierre_id       INT UNSIGNED NOT NULL,
    fecha           DATE         NOT NULL,
    tipo            ENUM('ingreso','egreso') NOT NULL,
    categoria       VARCHAR(80)  NOT NULL,
    descripcion     VARCHAR(255) NOT NULL DEFAULT '',
    beneficiario    VARCHAR(120) NULL,
    referencia      VARCHAR(40)  NULL,
    monto_bs        DECIMAL(18,2) NOT NULL,
    comision_bs     DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    monto_usd       DECIMAL(18,4) NULL,
    tasa_usd_dia    DECIMAL(18,6) NOT NULL,
    hoja            VARCHAR(40)  NULL,
    fila            INT UNSIGNED NULL,
    registrado_por  INT UNSIGNED NOT NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_banco_mov_cuenta_fecha (cuenta_id, fecha),
    KEY ix_banco_mov_cierre (cierre_id),
    CONSTRAINT fk_banco_mov_cuenta FOREIGN KEY (cuenta_id) REFERENCES bancos_cuentas (id),
    CONSTRAINT fk_banco_mov_cierre FOREIGN KEY (cierre_id) REFERENCES bancos_cierres_diarios (id),
    CONSTRAINT fk_banco_mov_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_banco_mov_monto CHECK (monto_bs > 0),
    CONSTRAINT ck_banco_mov_comision CHECK (comision_bs >= 0),
    CONSTRAINT ck_banco_mov_tasa CHECK (tasa_usd_dia > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ##################### 045_bancos_catalogo.sql #####################
-- =====================================================================
-- ADARA · Migración 045: tarifario y catálogo de bancos
--   La comisión no se digita: Excel la calcula (Pago Móvil 1,5 %,
--   transferencia a otros bancos 0,30 %, mismo banco 0) y ADARA guarda
--   el valor ya resuelto junto con el método y el banco de la operación.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE bancos_catalogo (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_banco  VARCHAR(80)  NOT NULL,
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bancos_catalogo_nombre (nombre_banco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bancos_catalogo (nombre_banco) VALUES
    ('Banco de Venezuela'),
    ('Banesco'),
    ('Banco Provincial'),
    ('BNC'),
    ('Bancamiga'),
    ('Banco del Tesoro'),
    ('Mercantil'),
    ('Bancaribe'),
    ('Banco Exterior'),
    ('Banco Activo'),
    ('Banco Plaza'),
    ('Banco Caroní'),
    ('Banplus'),
    ('100% Banco'),
    ('Banco Venezolano de Crédito')
ON DUPLICATE KEY UPDATE nombre_banco = VALUES(nombre_banco);

ALTER TABLE bancos_movimientos
    ADD COLUMN metodo_pago VARCHAR(40) NULL AFTER categoria,
    ADD COLUMN banco_origen_destino VARCHAR(80) NULL AFTER metodo_pago;

-- ##################### 046_bancos_multimoneda_auditoria.sql #####################
-- =====================================================================
-- ADARA · Migración 046: moneda nativa y auditoría cruzada de bancos
--
-- bancos_cuentas.moneda es la unidad del cuadre (BS o USD nominal).
-- La tasa no convierte el saldo: un cierre en USD se compara en USD.
--
-- bancos_movimientos.monto_usd ya existía como DECIMAL(18,4). Se deja
-- así: alcanza montos grandes y conserva los 4 decimales del libro.
-- DECIMAL(10,2) se quedaría corto y recortaría esa escala.
-- id_origen es BIGINT porque productores_abonos y gas_pagos usan BIGINT.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_cuentas' AND COLUMN_NAME = 'moneda');
SET @s1 = IF(@c1 = 0,
    "ALTER TABLE bancos_cuentas ADD COLUMN moneda ENUM('BS','USD') NOT NULL DEFAULT 'BS' AFTER alias_cuenta",
    'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_movimientos' AND COLUMN_NAME = 'entidad_relacionada');
SET @s2 = IF(@c2 = 0,
    "ALTER TABLE bancos_movimientos ADD COLUMN entidad_relacionada VARCHAR(100) NULL AFTER beneficiario",
    'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

SET @c3 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_movimientos' AND COLUMN_NAME = 'estado_auditoria');
SET @s3 = IF(@c3 = 0,
    "ALTER TABLE bancos_movimientos ADD COLUMN estado_auditoria ENUM('conciliado','huerfano','no_aplica') NOT NULL DEFAULT 'no_aplica' AFTER fila",
    'SELECT 1');
PREPARE st3 FROM @s3; EXECUTE st3; DEALLOCATE PREPARE st3;

SET @c4 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_movimientos' AND COLUMN_NAME = 'modulo_origen');
SET @s4 = IF(@c4 = 0,
    "ALTER TABLE bancos_movimientos ADD COLUMN modulo_origen VARCHAR(50) NULL AFTER estado_auditoria",
    'SELECT 1');
PREPARE st4 FROM @s4; EXECUTE st4; DEALLOCATE PREPARE st4;

SET @c5 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_movimientos' AND COLUMN_NAME = 'id_origen');
SET @s5 = IF(@c5 = 0,
    "ALTER TABLE bancos_movimientos ADD COLUMN id_origen BIGINT UNSIGNED NULL AFTER modulo_origen",
    'SELECT 1');
PREPARE st5 FROM @s5; EXECUTE st5; DEALLOCATE PREPARE st5;

SET @i1 = (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_movimientos' AND INDEX_NAME = 'ix_banco_mov_auditoria');
SET @q1 = IF(@i1 = 0,
    'ALTER TABLE bancos_movimientos ADD KEY ix_banco_mov_auditoria (estado_auditoria, fecha)',
    'SELECT 1');
PREPARE qi1 FROM @q1; EXECUTE qi1; DEALLOCATE PREPARE qi1;

SET @i2 = (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_movimientos' AND INDEX_NAME = 'ix_banco_mov_origen');
SET @q2 = IF(@i2 = 0,
    'ALTER TABLE bancos_movimientos ADD KEY ix_banco_mov_origen (modulo_origen, id_origen)',
    'SELECT 1');
PREPARE qi2 FROM @q2; EXECUTE qi2; DEALLOCATE PREPARE qi2;

-- ##################### 047_bancos_cuentas_gestor.sql #####################
-- =====================================================================
-- ADARA · Migración 047: gestor de cuentas bancarias
--   Corre después de 046 (moneda ya existe).
--   alias_cuenta sigue siendo el nombre que viaja al Excel.
--   nombre_banco, numero_cuenta, cedula y telefono son los datos
--   que el usuario copia para enviarlos por WhatsApp.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_cuentas' AND COLUMN_NAME = 'nombre_banco');
SET @s1 = IF(@c1 = 0,
    "ALTER TABLE bancos_cuentas ADD COLUMN nombre_banco VARCHAR(80) NULL AFTER alias_cuenta",
    'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_cuentas' AND COLUMN_NAME = 'numero_cuenta');
SET @s2 = IF(@c2 = 0,
    "ALTER TABLE bancos_cuentas ADD COLUMN numero_cuenta VARCHAR(30) NULL AFTER nombre_banco",
    'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

SET @c3 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_cuentas' AND COLUMN_NAME = 'cedula');
SET @s3 = IF(@c3 = 0,
    "ALTER TABLE bancos_cuentas ADD COLUMN cedula VARCHAR(24) NULL AFTER numero_cuenta",
    'SELECT 1');
PREPARE st3 FROM @s3; EXECUTE st3; DEALLOCATE PREPARE st3;

SET @c4 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bancos_cuentas' AND COLUMN_NAME = 'telefono');
SET @s4 = IF(@c4 = 0,
    "ALTER TABLE bancos_cuentas ADD COLUMN telefono VARCHAR(24) NULL AFTER cedula",
    'SELECT 1');
PREPARE st4 FROM @s4; EXECUTE st4; DEALLOCATE PREPARE st4;

-- ##################### 048_empresa_configuracion.sql #####################
-- =====================================================================
-- ADARA · Migración 048: marca blanca
--   Un solo registro (id = 1). El layout lee esta fila en cada página.
--   Los colores actuales del ERP: naranja #F97316 y azul #1A2E5A.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS empresa_configuracion (
    id                TINYINT UNSIGNED NOT NULL,
    nombre_empresa    VARCHAR(120) NOT NULL,
    rif_documento     VARCHAR(20)  NOT NULL,
    telefono          VARCHAR(30)  NOT NULL DEFAULT '',
    direccion         VARCHAR(180) NOT NULL DEFAULT '',
    color_primario    VARCHAR(7)   NOT NULL,
    color_secundario  VARCHAR(7)   NOT NULL,
    ruta_logo         VARCHAR(180) NULL,
    ruta_sello        VARCHAR(180) NULL,
    actualizado_en    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT ck_empresa_config_unica CHECK (id = 1),
    CONSTRAINT ck_empresa_color_primario CHECK (color_primario REGEXP '^#[0-9A-Fa-f]{6}$'),
    CONSTRAINT ck_empresa_color_secundario CHECK (color_secundario REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa_configuracion
    (id, nombre_empresa, rif_documento, telefono, direccion, color_primario, color_secundario, ruta_logo, ruta_sello)
VALUES
    (1, 'El Castillo del Queso Artesanal F.P.', 'V-286013356', '', '', '#F97316', '#1A2E5A', 'img/logo.jpg', NULL)
ON DUPLICATE KEY UPDATE id = id;

-- ##################### 049_api_idempotencia.sql #####################
-- =====================================================================
-- ADARA · Migración 049: idempotencia de la API (modo offline)
--   Una fila por X-Idempotency-Key procesada con éxito (2xx).
--   La fila se inserta dentro de la MISMA transacción que la operación de
--   negocio (security/idempotencia.php): o quedan ambas o ninguna. Por eso
--   no hay estado "procesando": una petición duplicada concurrente espera el
--   bloqueo de la PK y, al liberarse, lee la respuesta ya confirmada.
--   Retención: 30 días (purga en Idempotencia::purgar()).
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS api_idempotencia (
    clave           CHAR(36)          NOT NULL,
    usuario_id      INT UNSIGNED      NOT NULL,
    endpoint        VARCHAR(60)       NOT NULL,
    huella_peticion CHAR(64)          NOT NULL,
    codigo_http     SMALLINT UNSIGNED NOT NULL,
    respuesta_json  MEDIUMTEXT        NOT NULL,
    creado_en       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (clave),
    KEY ix_idempotencia_creado (creado_en),
    KEY ix_idempotencia_usuario (usuario_id, creado_en),
    CONSTRAINT fk_idempotencia_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_idempotencia_clave CHECK (clave REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'),
    CONSTRAINT ck_idempotencia_http CHECK (codigo_http BETWEEN 200 AND 299),
    CONSTRAINT ck_idempotencia_json CHECK (JSON_VALID(respuesta_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
