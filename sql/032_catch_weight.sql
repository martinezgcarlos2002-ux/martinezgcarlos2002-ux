-- =====================================================================
-- ADARA · Migración 032: Catch Weight (unidad secundaria variable)
-- Guayanés/Telita/Mozzarella: KG reales + empaques (Tobos/Piezas/Potes).
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS adara_add_col_catch_weight;
DELIMITER //
CREATE PROCEDURE adara_add_col_catch_weight()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'unidad_secundaria') THEN
        ALTER TABLE productos ADD COLUMN unidad_secundaria VARCHAR(20) NULL AFTER unidad_medida;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'stock_secundario') THEN
        ALTER TABLE productos ADD COLUMN stock_secundario INT NOT NULL DEFAULT 0 AFTER stock;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventario_almacenes' AND COLUMN_NAME = 'stock_secundario') THEN
        ALTER TABLE inventario_almacenes ADD COLUMN stock_secundario INT NOT NULL DEFAULT 0 AFTER stock;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produccion_diaria' AND COLUMN_NAME = 'cantidad_secundaria') THEN
        ALTER TABLE produccion_diaria ADD COLUMN cantidad_secundaria INT NOT NULL DEFAULT 0 AFTER cantidad;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produccion_diaria' AND COLUMN_NAME = 'distribuido_secundario') THEN
        ALTER TABLE produccion_diaria ADD COLUMN distribuido_secundario INT NOT NULL DEFAULT 0 AFTER distribuido;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'cantidad_secundaria') THEN
        ALTER TABLE venta_items ADD COLUMN cantidad_secundaria INT NOT NULL DEFAULT 0 AFTER cantidad;
    END IF;
END //
DELIMITER ;
CALL adara_add_col_catch_weight();
DROP PROCEDURE IF EXISTS adara_add_col_catch_weight;
