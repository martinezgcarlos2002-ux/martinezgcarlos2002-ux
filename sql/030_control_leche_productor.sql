-- =====================================================================
-- ADARA · Migración 030: densidad y faltante por productor en el día
--   TOTAL FALTANTE de la planilla es la suma de estas filas.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS prod_control_leche_productor (
    fecha         DATE          NOT NULL,
    productor_id  INT UNSIGNED  NOT NULL,
    densidad      DECIMAL(12,3) NULL,
    faltante      DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    PRIMARY KEY (fecha, productor_id),
    CONSTRAINT fk_pclp_productor FOREIGN KEY (productor_id) REFERENCES productores (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_pclp_vals CHECK (
        (densidad IS NULL OR densidad >= 0) AND faltante >= 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
