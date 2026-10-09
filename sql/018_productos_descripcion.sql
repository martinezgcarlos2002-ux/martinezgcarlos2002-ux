-- =====================================================================
-- ADARA · Migración 018: descripción también en el maestro de inventario
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'descripcion'
);
SET @sql = IF(@col = 0,
    'ALTER TABLE productos ADD COLUMN descripcion TEXT NULL AFTER nombre',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
