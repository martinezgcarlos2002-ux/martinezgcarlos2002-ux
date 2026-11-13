-- Fondo de login por empresa (marca blanca).
-- Se ejecuta en la base de cada cliente, no en la base maestra.
-- Idempotente: si la columna ya existe, no hace nada.

SET @existe := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'empresa_configuracion'
      AND COLUMN_NAME = 'fondo_login'
);

SET @sql := IF(
    @existe = 0,
    'ALTER TABLE empresa_configuracion ADD COLUMN fondo_login VARCHAR(180) NULL DEFAULT NULL AFTER ruta_sello',
    'SELECT 1'
);

PREPARE adara_fondo_login FROM @sql;
EXECUTE adara_fondo_login;
DEALLOCATE PREPARE adara_fondo_login;
