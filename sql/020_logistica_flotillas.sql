-- =====================================================================
-- ADARA · Migración 020: flotillas, pagos a transportistas
--   cantidad_vehiculos en log_transportistas + log_pagos_transporte.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_transportistas' AND COLUMN_NAME = 'cantidad_vehiculos');
SET @s1 = IF(@c1 = 0,
    "ALTER TABLE log_transportistas ADD COLUMN cantidad_vehiculos INT UNSIGNED NOT NULL DEFAULT 1 AFTER limite_carga_kg",
    'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @c2 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_transportistas' AND CONSTRAINT_NAME = 'ck_log_tr_vehiculos');
SET @s2 = IF(@c2 = 0,
    "ALTER TABLE log_transportistas ADD CONSTRAINT ck_log_tr_vehiculos CHECK (cantidad_vehiculos >= 1 AND cantidad_vehiculos <= 99)",
    'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

CREATE TABLE IF NOT EXISTS log_pagos_transporte (
    id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    pago_semanal_id       INT UNSIGNED  NOT NULL,
    fecha                 DATE          NOT NULL,
    monto                 DECIMAL(12,2) NOT NULL,
    descripcion_mercancia VARCHAR(255)  NULL,
    tipo                  ENUM('efectivo','transferencia','mercancia') NOT NULL,
    registrado_por        INT UNSIGNED  NOT NULL,
    creado_en             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_log_pt_pago (pago_semanal_id, fecha),
    CONSTRAINT fk_log_pt_cierre FOREIGN KEY (pago_semanal_id) REFERENCES log_pagos_semanales (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_log_pt_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_log_pt_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
