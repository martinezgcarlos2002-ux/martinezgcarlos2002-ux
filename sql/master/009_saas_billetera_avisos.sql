-- Billetera, suspensión manual, detalle de pago y avisos de vencimiento.
-- Base maestra. Idempotente.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes_saas' AND COLUMN_NAME = 'saldo_a_favor') = 0,
    'ALTER TABLE clientes_saas ADD COLUMN saldo_a_favor DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER contrato_aceptado',
    'SELECT 1'
);
PREPARE adara_saldo FROM @sql; EXECUTE adara_saldo; DEALLOCATE PREPARE adara_saldo;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes_saas' AND COLUMN_NAME = 'estado_suscripcion') = 0,
    'ALTER TABLE clientes_saas ADD COLUMN estado_suscripcion ENUM(''activa'',''Suspendido'') NOT NULL DEFAULT ''activa'' AFTER saldo_a_favor',
    'SELECT 1'
);
PREPARE adara_estado_sus FROM @sql; EXECUTE adara_estado_sus; DEALLOCATE PREPARE adara_estado_sus;

ALTER TABLE saas_suscripciones
    MODIFY modo_pago ENUM('efectivo','transferencia','pago_movil','zelle','binance','otros') NOT NULL;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_suscripciones' AND COLUMN_NAME = 'monto_pagado') = 0,
    'ALTER TABLE saas_suscripciones ADD COLUMN monto_pagado DECIMAL(12,2) NULL AFTER tarifa_usd',
    'SELECT 1'
);
PREPARE adara_monto FROM @sql; EXECUTE adara_monto; DEALLOCATE PREPARE adara_monto;

UPDATE saas_suscripciones SET monto_pagado = tarifa_usd WHERE monto_pagado IS NULL;

ALTER TABLE saas_suscripciones
    MODIFY monto_pagado DECIMAL(12,2) NOT NULL;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_suscripciones' AND COLUMN_NAME = 'descripcion_pago') = 0,
    'ALTER TABLE saas_suscripciones ADD COLUMN descripcion_pago VARCHAR(500) NULL AFTER referencia',
    'SELECT 1'
);
PREPARE adara_desc_pago FROM @sql; EXECUTE adara_desc_pago; DEALLOCATE PREPARE adara_desc_pago;

CREATE TABLE IF NOT EXISTS cliente_notificaciones (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente  INT UNSIGNED    NOT NULL,
    mensaje     VARCHAR(255)    NOT NULL,
    leido       TINYINT(1)      NOT NULL DEFAULT 0,
    fecha       DATE            NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cliente_notificaciones (id_cliente, fecha, mensaje),
    KEY ix_cliente_notificaciones_leido (id_cliente, leido, fecha),
    CONSTRAINT fk_cliente_notificaciones_cliente
        FOREIGN KEY (id_cliente) REFERENCES clientes_saas (id),
    CONSTRAINT ck_cliente_notificaciones_mensaje CHECK (CHAR_LENGTH(mensaje) BETWEEN 1 AND 255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
