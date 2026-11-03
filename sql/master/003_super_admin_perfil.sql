-- =====================================================================
-- ADARA SaaS · Master 003: perfil del súper-administrador
--   cedula, telefono y direccion identifican a quien firma el acuerdo.
--   NULL en filas ya existentes: el alta nueva las exige en la aplicación.
-- =====================================================================
USE adara_master;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE super_admins
    ADD COLUMN cedula    VARCHAR(20) NULL AFTER correo,
    ADD COLUMN telefono  VARCHAR(30) NULL AFTER cedula,
    ADD COLUMN direccion TEXT        NULL AFTER telefono;
