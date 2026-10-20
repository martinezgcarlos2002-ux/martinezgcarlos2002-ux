-- =====================================================================
-- ADARA · Migración 039: bitácora de contratos finalizados
-- Permite reingresar a la misma ficha (cédula UNIQUE) sin perder
-- el periodo que acaba de cerrarse.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS hr_empleados_historial (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empleado_id   INT UNSIGNED NOT NULL,
    fecha_ingreso DATE         NULL,
    fecha_salida  DATE         NULL,
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_hr_hist_empleado (empleado_id, fecha_ingreso, id),
    CONSTRAINT fk_hr_hist_empleado FOREIGN KEY (empleado_id) REFERENCES empleados (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT ck_hr_hist_fechas CHECK (fecha_salida IS NULL OR fecha_ingreso IS NULL OR fecha_salida >= fecha_ingreso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
