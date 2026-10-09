-- =====================================================================
-- ADARA · Migración 024: etiqueta de grupo de cierre para facturas masivas
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE productores
    ADD COLUMN grupo_cierre VARCHAR(50) NULL DEFAULT NULL AFTER dia_cierre,
    ADD KEY ix_productores_grupo_cierre (grupo_cierre);

UPDATE productores
   SET grupo_cierre = ELT(dia_cierre, 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo')
 WHERE grupo_cierre IS NULL;
