-- =====================================================================
-- ADARA SaaS · Master 005: contrato firmado por empresa
--   contrato_ruta: PDF del contrato, cuando el módulo legal lo guarde.
--   fecha_aceptacion_contrato: NULL mientras la empresa no lo haya aceptado.
-- =====================================================================
USE adara_master;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE clientes_saas
    ADD COLUMN contrato_ruta             VARCHAR(255) NULL AFTER estado_licencia,
    ADD COLUMN fecha_aceptacion_contrato DATETIME     NULL AFTER contrato_ruta;
