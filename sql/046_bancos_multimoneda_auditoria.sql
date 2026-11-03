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
USE adara;

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
