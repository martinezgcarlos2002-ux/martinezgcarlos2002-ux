-- =====================================================================
-- ADARA · Migración 029: categoría de préstamo manual en RRHH
--   El alta manual desde Recursos Humanos usa tipo = 'prestamo'.
--   'mercancia' sigue reservado al descuento que nace en Ventas.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE hr_prestamos
    MODIFY COLUMN tipo ENUM('prestamo','efectivo','transferencia','pago_movil','mercancia','otros')
        NOT NULL DEFAULT 'prestamo';
