-- =====================================================================
-- ADARA · Migración 034: empaques en el rastro de distribución
-- Cada movimiento a cava guarda kilos (cantidad) y empaques (cantidad_secundaria).
-- inventario_almacenes.stock_secundario ya existe desde 032; se reafirma aquí.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS adara_add_col_dist_sec;
DELIMITER //
CREATE PROCEDURE adara_add_col_dist_sec()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventario_almacenes' AND COLUMN_NAME = 'stock_secundario') THEN
        ALTER TABLE inventario_almacenes ADD COLUMN stock_secundario INT NOT NULL DEFAULT 0 AFTER stock;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produccion_distribuciones' AND COLUMN_NAME = 'cantidad_secundaria') THEN
        ALTER TABLE produccion_distribuciones ADD COLUMN cantidad_secundaria INT NOT NULL DEFAULT 0 AFTER cantidad;
    END IF;
END //
DELIMITER ;
CALL adara_add_col_dist_sec();
DROP PROCEDURE IF EXISTS adara_add_col_dist_sec;
