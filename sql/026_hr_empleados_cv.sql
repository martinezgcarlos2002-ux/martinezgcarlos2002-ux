-- =====================================================================
-- ADARA · Migración 026: ficha / currículum de empleados
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS adara_add_col_empleados_cv;
DELIMITER //
CREATE PROCEDURE adara_add_col_empleados_cv()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados' AND COLUMN_NAME = 'profesion'
    ) THEN
        ALTER TABLE empleados ADD COLUMN profesion VARCHAR(80) NULL DEFAULT NULL AFTER cargo;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados' AND COLUMN_NAME = 'direccion_domicilio'
    ) THEN
        ALTER TABLE empleados ADD COLUMN direccion_domicilio TEXT NULL AFTER direccion;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados' AND COLUMN_NAME = 'foto_perfil'
    ) THEN
        ALTER TABLE empleados ADD COLUMN foto_perfil VARCHAR(180) NULL DEFAULT NULL AFTER expediente_penal;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados' AND COLUMN_NAME = 'foto_documento'
    ) THEN
        ALTER TABLE empleados ADD COLUMN foto_documento VARCHAR(180) NULL DEFAULT NULL AFTER foto_perfil;
    END IF;
END //
DELIMITER ;

CALL adara_add_col_empleados_cv();
DROP PROCEDURE IF EXISTS adara_add_col_empleados_cv;

UPDATE empleados
   SET direccion_domicilio = direccion
 WHERE direccion_domicilio IS NULL AND direccion IS NOT NULL AND direccion <> '';
