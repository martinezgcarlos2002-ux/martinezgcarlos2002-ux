-- =====================================================================
-- ADARA · Migración 047: gestor de cuentas bancarias
--   Corre después de 046 (moneda ya existe).
--   alias_cuenta sigue siendo el nombre que viaja al Excel.
--   nombre_banco, numero_cuenta, cedula y telefono son los datos
--   que el usuario copia para enviarlos por WhatsApp.
-- =====================================================================
USE adara;

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
