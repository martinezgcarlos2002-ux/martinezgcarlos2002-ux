-- =====================================================================
-- ADARA · Migración 031: teléfono y dirección en clientes frecuentes POS
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS adara_add_col_vcli_contacto;
DELIMITER //
CREATE PROCEDURE adara_add_col_vcli_contacto()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas_clientes' AND COLUMN_NAME = 'telefono'
    ) THEN
        ALTER TABLE ventas_clientes ADD COLUMN telefono VARCHAR(20) NULL AFTER documento;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas_clientes' AND COLUMN_NAME = 'direccion'
    ) THEN
        ALTER TABLE ventas_clientes ADD COLUMN direccion VARCHAR(160) NULL AFTER telefono;
    END IF;
END //
DELIMITER ;
CALL adara_add_col_vcli_contacto();
DROP PROCEDURE IF EXISTS adara_add_col_vcli_contacto;
