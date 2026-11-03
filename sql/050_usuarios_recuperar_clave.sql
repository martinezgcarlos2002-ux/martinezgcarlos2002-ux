-- =====================================================================
-- ADARA · Migración 050: recuperación de contraseña por correo
--   reset_token guarda el SHA-256 (64 hex) del token enviado, nunca el token en claro:
--   quien lea la base no puede usar los enlaces pendientes.
--   reset_expiracion: el enlace vale 15 minutos. Ambos campos vuelven a NULL al usarse.
-- Multiempresa: ejecutar en la base física de CADA empresa (adara, adara_castillo, …).
--   mysql -u root adara_castillo < sql/050_usuarios_recuperar_clave.sql
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE usuarios
    ADD COLUMN reset_token      VARCHAR(64) NULL AFTER password_hash,
    ADD COLUMN reset_expiracion DATETIME    NULL AFTER reset_token,
    ADD UNIQUE KEY uq_usuarios_reset_token (reset_token),
    ADD CONSTRAINT ck_usuarios_reset CHECK ((reset_token IS NULL) = (reset_expiracion IS NULL));
