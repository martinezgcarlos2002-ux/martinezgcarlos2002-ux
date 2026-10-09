-- =====================================================================
-- ADARA · Migración 025: mermas y ventas diarias de leche (control semanal)
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS prod_control_leche_diario (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    fecha           DATE          NOT NULL,
    ventas_leche    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cortesias_leche DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vales_leche     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    leche_faltante  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    registrado_por  INT UNSIGNED  NULL,
    creado_en       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prod_cld_fecha (fecha),
    CONSTRAINT fk_prod_cld_user FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT ck_prod_cld_vals CHECK (
        ventas_leche >= 0 AND cortesias_leche >= 0 AND vales_leche >= 0 AND leche_faltante >= 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
