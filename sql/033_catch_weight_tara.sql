-- =====================================================================
-- ADARA · Migración 033: bruto, tara unitaria y tara total (catch weight)
-- El stock y el cobro usan peso neto = bruto − (empaques × tara).
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS adara_add_col_tara;
DELIMITER //
CREATE PROCEDURE adara_add_col_tara()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produccion_diaria' AND COLUMN_NAME = 'peso_bruto') THEN
        ALTER TABLE produccion_diaria ADD COLUMN peso_bruto DECIMAL(12,3) NULL AFTER cantidad_secundaria;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produccion_diaria' AND COLUMN_NAME = 'tara_unitaria') THEN
        ALTER TABLE produccion_diaria ADD COLUMN tara_unitaria DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER peso_bruto;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produccion_diaria' AND COLUMN_NAME = 'tara_total') THEN
        ALTER TABLE produccion_diaria ADD COLUMN tara_total DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER tara_unitaria;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'peso_bruto') THEN
        ALTER TABLE venta_items ADD COLUMN peso_bruto DECIMAL(12,3) NULL AFTER cantidad_secundaria;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'tara_unitaria') THEN
        ALTER TABLE venta_items ADD COLUMN tara_unitaria DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER peso_bruto;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'tara_total') THEN
        ALTER TABLE venta_items ADD COLUMN tara_total DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER tara_unitaria;
    END IF;
END //
DELIMITER ;
CALL adara_add_col_tara();
DROP PROCEDURE IF EXISTS adara_add_col_tara;
