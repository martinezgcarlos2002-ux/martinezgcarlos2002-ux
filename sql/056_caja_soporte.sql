-- =====================================================================
-- ADARA · Migración 056: quién hizo el movimiento en modo soporte
--   registrado_por sigue siendo un usuarios.id de la empresa (la FK no
--   puede apuntar al super admin, que vive en otra base).
--   soporte_super_admin_id guarda el id real de adara_master.super_admins.
-- Ejecutar en la base física de CADA empresa.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE caja_movimientos
    ADD COLUMN soporte_super_admin_id INT UNSIGNED NULL AFTER registrado_por;
