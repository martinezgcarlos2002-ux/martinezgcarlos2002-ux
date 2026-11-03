-- =====================================================================
-- ADARA · Migración 053: foto física del cilindro de gas
--   foto_url guarda la ruta relativa dentro de assets/ (img/gas/...).
-- Ejecutar en la base física de CADA empresa.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE gas_cilindros
    ADD COLUMN foto_url VARCHAR(255) NULL AFTER estado_registro;
