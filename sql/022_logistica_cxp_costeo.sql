-- =====================================================================
-- ADARA · Migración 022: métodos de pago CxP + vehículos asignados en ruta
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE log_pagos_transporte
    MODIFY COLUMN tipo ENUM('efectivo','transferencia','pago_movil','mercancia','otros') NOT NULL;

SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_rutas' AND COLUMN_NAME = 'vehiculos_asignados');
SET @s1 = IF(@c1 = 0,
    "ALTER TABLE log_rutas ADD COLUMN vehiculos_asignados INT UNSIGNED NOT NULL DEFAULT 1 AFTER precio_viaje",
    'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @ck = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_rutas' AND CONSTRAINT_NAME = 'ck_log_ruta_nveh');
SET @sk = IF(@ck = 0,
    "ALTER TABLE log_rutas ADD CONSTRAINT ck_log_ruta_nveh CHECK (vehiculos_asignados >= 1 AND vehiculos_asignados <= 99)",
    'SELECT 1');
PREPARE stk FROM @sk; EXECUTE stk; DEALLOCATE PREPARE stk;
