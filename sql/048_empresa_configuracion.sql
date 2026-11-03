-- =====================================================================
-- ADARA · Migración 048: marca blanca
--   Un solo registro (id = 1). El layout lee esta fila en cada página.
--   Los colores actuales del ERP: naranja #F97316 y azul #1A2E5A.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS empresa_configuracion (
    id                TINYINT UNSIGNED NOT NULL,
    nombre_empresa    VARCHAR(120) NOT NULL,
    rif_documento     VARCHAR(20)  NOT NULL,
    telefono          VARCHAR(30)  NOT NULL DEFAULT '',
    direccion         VARCHAR(180) NOT NULL DEFAULT '',
    color_primario    VARCHAR(7)   NOT NULL,
    color_secundario  VARCHAR(7)   NOT NULL,
    ruta_logo         VARCHAR(180) NULL,
    ruta_sello        VARCHAR(180) NULL,
    actualizado_en    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT ck_empresa_config_unica CHECK (id = 1),
    CONSTRAINT ck_empresa_color_primario CHECK (color_primario REGEXP '^#[0-9A-Fa-f]{6}$'),
    CONSTRAINT ck_empresa_color_secundario CHECK (color_secundario REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa_configuracion
    (id, nombre_empresa, rif_documento, telefono, direccion, color_primario, color_secundario, ruta_logo, ruta_sello)
VALUES
    (1, 'El Castillo del Queso Artesanal F.P.', 'V-286013356', '', '', '#F97316', '#1A2E5A', 'img/logo.jpg', NULL)
ON DUPLICATE KEY UPDATE id = id;
