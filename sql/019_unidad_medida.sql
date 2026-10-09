-- =====================================================================
-- ADARA · Migración 019: unidades de medida (KG / LTS / UND)
--   prod_catalogo + productos (maestro de inventario).
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prod_catalogo' AND COLUMN_NAME = 'unidad_medida');
SET @s1 = IF(@c1 = 0,
    "ALTER TABLE prod_catalogo ADD COLUMN unidad_medida ENUM('KG','LTS','UND') NOT NULL DEFAULT 'KG' AFTER descripcion",
    'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'unidad_medida');
SET @s2 = IF(@c2 = 0,
    "ALTER TABLE productos ADD COLUMN unidad_medida ENUM('KG','LTS','UND') NOT NULL DEFAULT 'KG' AFTER unidad",
    'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

UPDATE productos
   SET unidad_medida = CASE unidad
        WHEN 'litro' THEN 'LTS'
        WHEN 'unidad' THEN 'UND'
        ELSE 'KG'
   END;

UPDATE prod_catalogo c
INNER JOIN productos p ON p.id = c.producto_id
   SET c.unidad_medida = p.unidad_medida;
