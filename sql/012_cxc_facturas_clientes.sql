-- =====================================================================
-- ADARA · Migración 012: Control de Facturas de Clientes (Cuentas por Cobrar)
--   Digitaliza facturas de talonario. No toca inventario ni caja: es un libro de CxC independiente.
--
--   Invariantes (CHECK + servicio CxCService, que opera en transacción con FOR UPDATE):
--     saldo_pendiente = monto_total − total_pagado − total_devuelto   y   saldo_pendiente >= 0
--     total_pagado    = SUM(cxc_pagos.monto) de la factura (incluye los pagos con saldo a favor)
--     total_devuelto  = SUM(cxc_devoluciones.monto) aplicadas a la factura
--     cxc_clientes.saldo_favor = SUM(cxc_saldo_movimientos.monto) del cliente, siempre >= 0
--   estado es derivado de saldo y fechas; CxCService lo recalcula al escribir y antes de cada lectura.
--   Orden de bloqueo en todas las operaciones: cliente → facturas por id ascendente (evita interbloqueos).
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE cxc_clientes (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(120)  NOT NULL,
    documento      VARCHAR(14)   NULL,
    telefono       VARCHAR(20)   NULL,
    domicilio      VARCHAR(160)  NULL,
    saldo_favor    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    registrado_por INT UNSIGNED  NOT NULL,
    creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cxc_clientes_documento (documento),
    KEY ix_cxc_clientes_nombre (nombre),
    CONSTRAINT fk_cxc_clientes_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cxc_clientes_nombre  CHECK (CHAR_LENGTH(TRIM(nombre)) >= 2),
    CONSTRAINT ck_cxc_clientes_doc     CHECK (documento IS NULL OR documento REGEXP '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
    CONSTRAINT ck_cxc_clientes_saldo   CHECK (saldo_favor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cxc_facturas (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    cliente_id        INT UNSIGNED  NOT NULL,
    numero_factura    VARCHAR(20)   NOT NULL,
    es_aleatorio      TINYINT(1)    NOT NULL DEFAULT 0,
    domicilio         VARCHAR(160)  NULL,
    observaciones     VARCHAR(240)  NULL,
    fecha_emision     DATE          NOT NULL,
    fecha_vencimiento DATE          NOT NULL,
    monto_total       DECIMAL(12,2) NOT NULL,
    total_pagado      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_devuelto    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    saldo_pendiente   DECIMAL(12,2) NOT NULL,
    estado            ENUM('vigente','por_vencer','vencida','pagada') NOT NULL DEFAULT 'vigente',
    registrado_por    INT UNSIGNED  NOT NULL,
    creado_en         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en    DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cxc_facturas_numero (numero_factura),
    KEY ix_cxc_facturas_cliente_saldo (cliente_id, saldo_pendiente),
    KEY ix_cxc_facturas_vencimiento (fecha_vencimiento),
    KEY ix_cxc_facturas_estado (estado, fecha_vencimiento),
    CONSTRAINT fk_cxc_facturas_cliente FOREIGN KEY (cliente_id)     REFERENCES cxc_clientes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_facturas_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cxc_facturas_numero  CHECK (numero_factura REGEXP '^[A-Za-z0-9-]{1,20}$'),
    CONSTRAINT ck_cxc_facturas_fechas  CHECK (fecha_vencimiento >= fecha_emision),
    CONSTRAINT ck_cxc_facturas_montos  CHECK (monto_total > 0 AND total_pagado >= 0 AND total_devuelto >= 0 AND saldo_pendiente >= 0),
    CONSTRAINT ck_cxc_facturas_saldo   CHECK (saldo_pendiente = monto_total - total_pagado - total_devuelto),
    CONSTRAINT ck_cxc_facturas_flag    CHECK (es_aleatorio IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cxc_factura_detalles (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    factura_id      INT UNSIGNED     NOT NULL,
    renglon         SMALLINT UNSIGNED NOT NULL,
    cantidad        DECIMAL(10,2)    NOT NULL,
    precio_unitario DECIMAL(12,2)    NOT NULL,
    subtotal        DECIMAL(12,2)    NOT NULL,
    descripcion     VARCHAR(120)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cxc_fdet_renglon (factura_id, renglon),
    CONSTRAINT fk_cxc_fdet_factura  FOREIGN KEY (factura_id) REFERENCES cxc_facturas (id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT ck_cxc_fdet_montos   CHECK (cantidad > 0 AND precio_unitario > 0 AND subtotal > 0),
    CONSTRAINT ck_cxc_fdet_subtotal CHECK (subtotal = ROUND(cantidad * precio_unitario, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cxc_pagos (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    factura_id     INT UNSIGNED    NOT NULL,
    cliente_id     INT UNSIGNED    NOT NULL,
    monto          DECIMAL(12,2)   NOT NULL,
    fecha          DATE            NOT NULL,
    metodo         ENUM('efectivo','punto','transferencia','pago_movil','saldo_favor','otro') NOT NULL,
    referencia     VARCHAR(60)     NULL,
    notas          VARCHAR(160)    NULL,
    lote           CHAR(16)        NULL COMMENT 'Agrupa los pagos creados por un mismo abono a varias facturas',
    registrado_por INT UNSIGNED    NOT NULL,
    creado_en      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_cxc_pagos_factura (factura_id),
    KEY ix_cxc_pagos_cliente_fecha (cliente_id, fecha),
    KEY ix_cxc_pagos_lote (lote),
    CONSTRAINT fk_cxc_pagos_factura FOREIGN KEY (factura_id)     REFERENCES cxc_facturas (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_pagos_cliente FOREIGN KEY (cliente_id)     REFERENCES cxc_clientes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_pagos_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cxc_pagos_monto   CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cxc_devoluciones (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    cliente_id          INT UNSIGNED  NOT NULL,
    factura_origen_id   INT UNSIGNED  NULL,
    factura_aplicada_id INT UNSIGNED  NULL,
    monto               DECIMAL(12,2) NOT NULL,
    fecha               DATE          NOT NULL,
    motivo              VARCHAR(160)  NULL,
    destino             ENUM('origen','otra_factura','credito_pendiente') NOT NULL,
    estado              ENUM('aplicada','pendiente') NOT NULL,
    registrado_por      INT UNSIGNED  NOT NULL,
    creado_en           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_cxc_dev_cliente (cliente_id, fecha),
    KEY ix_cxc_dev_aplicada (factura_aplicada_id),
    CONSTRAINT fk_cxc_dev_cliente  FOREIGN KEY (cliente_id)          REFERENCES cxc_clientes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_dev_origen   FOREIGN KEY (factura_origen_id)   REFERENCES cxc_facturas (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_dev_aplicada FOREIGN KEY (factura_aplicada_id) REFERENCES cxc_facturas (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_dev_usuario  FOREIGN KEY (registrado_por)      REFERENCES usuarios (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cxc_dev_monto    CHECK (monto > 0),
    CONSTRAINT ck_cxc_dev_destino  CHECK (
        (destino = 'origen'            AND factura_origen_id IS NOT NULL AND factura_aplicada_id = factura_origen_id AND estado = 'aplicada')
     OR (destino = 'otra_factura'      AND factura_aplicada_id IS NOT NULL AND estado = 'aplicada')
     OR (destino = 'credito_pendiente' AND factura_aplicada_id IS NULL AND estado = 'pendiente')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cxc_devolucion_detalles (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    devolucion_id   INT UNSIGNED      NOT NULL,
    renglon         SMALLINT UNSIGNED NOT NULL,
    cantidad        DECIMAL(10,2)     NOT NULL,
    precio_unitario DECIMAL(12,2)     NOT NULL,
    subtotal        DECIMAL(12,2)     NOT NULL,
    descripcion     VARCHAR(120)      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cxc_ddet_renglon (devolucion_id, renglon),
    CONSTRAINT fk_cxc_ddet_devolucion FOREIGN KEY (devolucion_id) REFERENCES cxc_devoluciones (id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT ck_cxc_ddet_montos     CHECK (cantidad > 0 AND precio_unitario > 0 AND subtotal > 0),
    CONSTRAINT ck_cxc_ddet_subtotal   CHECK (subtotal = ROUND(cantidad * precio_unitario, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro del saldo a favor: cada alta (+) o consumo (−) de cxc_clientes.saldo_favor queda trazado.
CREATE TABLE cxc_saldo_movimientos (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cliente_id       INT UNSIGNED    NOT NULL,
    tipo             ENUM('devolucion','abono_excedente','aplicado_factura') NOT NULL,
    monto            DECIMAL(12,2)   NOT NULL COMMENT '+ crédito a favor, − crédito consumido',
    saldo_resultante DECIMAL(12,2)   NOT NULL,
    factura_id       INT UNSIGNED    NULL,
    devolucion_id    INT UNSIGNED    NULL,
    pago_id          BIGINT UNSIGNED NULL,
    lote             CHAR(16)        NULL,
    fecha            DATE            NOT NULL,
    registrado_por   INT UNSIGNED    NOT NULL,
    creado_en        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_cxc_smov_cliente (cliente_id, id),
    CONSTRAINT fk_cxc_smov_cliente    FOREIGN KEY (cliente_id)     REFERENCES cxc_clientes (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_smov_factura    FOREIGN KEY (factura_id)     REFERENCES cxc_facturas (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_smov_devolucion FOREIGN KEY (devolucion_id)  REFERENCES cxc_devoluciones (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_smov_pago       FOREIGN KEY (pago_id)        REFERENCES cxc_pagos (id)        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cxc_smov_usuario    FOREIGN KEY (registrado_por) REFERENCES usuarios (id)         ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cxc_smov_monto      CHECK (monto <> 0 AND saldo_resultante >= 0),
    CONSTRAINT ck_cxc_smov_signo      CHECK ((tipo = 'aplicado_factura' AND monto < 0 AND pago_id IS NOT NULL)
                                          OR (tipo = 'devolucion' AND monto > 0 AND devolucion_id IS NOT NULL)
                                          OR (tipo = 'abono_excedente' AND monto > 0 AND lote IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pagos, devoluciones y movimientos de saldo son de solo inserción: se corrigen con nuevos asientos.
DELIMITER $$
CREATE TRIGGER trg_cxc_pagos_no_update BEFORE UPDATE ON cxc_pagos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos de CxC no se modifican';
END$$
CREATE TRIGGER trg_cxc_pagos_no_delete BEFORE DELETE ON cxc_pagos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos de CxC no se eliminan';
END$$
CREATE TRIGGER trg_cxc_dev_no_update BEFORE UPDATE ON cxc_devoluciones FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las devoluciones de CxC no se modifican';
END$$
CREATE TRIGGER trg_cxc_dev_no_delete BEFORE DELETE ON cxc_devoluciones FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las devoluciones de CxC no se eliminan';
END$$
CREATE TRIGGER trg_cxc_smov_no_update BEFORE UPDATE ON cxc_saldo_movimientos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los movimientos de saldo a favor no se modifican';
END$$
CREATE TRIGGER trg_cxc_smov_no_delete BEFORE DELETE ON cxc_saldo_movimientos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los movimientos de saldo a favor no se eliminan';
END$$
DELIMITER ;
