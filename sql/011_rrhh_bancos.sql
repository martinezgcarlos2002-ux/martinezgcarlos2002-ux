-- =====================================================================
-- ADARA · Migración 011: datos bancarios del empleado
--   * tipo_cuenta reemplaza a cuenta_propia (1 → 'propia', 0 → 'tercero').
--   * Cuenta propia: el titular es el empleado; titular_cedula puede quedar vacía.
--     Cuenta de tercero: titular_cedula es obligatoria.
--   * numero_cuenta: 20 dígitos (formato bancario venezolano); se guarda sin guiones.
--   * cargos ya es tabla (009): los cargos nuevos se insertan vía endpoint cargos_nuevo.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE empleados
    ADD COLUMN tipo_cuenta      ENUM('propia','tercero') NOT NULL DEFAULT 'propia' AFTER dias_libres_mes,
    ADD COLUMN banco            VARCHAR(80) NULL AFTER tipo_cuenta,
    ADD COLUMN titular_cedula   VARCHAR(14) NULL AFTER banco,
    ADD COLUMN titular_telefono VARCHAR(20) NULL AFTER titular_cedula,
    ADD COLUMN numero_cuenta    CHAR(20)    NULL AFTER titular_telefono;

UPDATE empleados SET tipo_cuenta = IF(cuenta_propia = 1, 'propia', 'tercero');

ALTER TABLE empleados
    DROP CONSTRAINT ck_empleados_flags;

ALTER TABLE empleados
    DROP COLUMN cuenta_propia,
    ADD CONSTRAINT ck_empleados_flags          CHECK (activo IN (0,1)),
    ADD CONSTRAINT ck_empleados_cuenta         CHECK (numero_cuenta IS NULL OR numero_cuenta REGEXP '^[0-9]{20}$'),
    ADD CONSTRAINT ck_empleados_titular_cedula CHECK (titular_cedula IS NULL OR titular_cedula REGEXP '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
    ADD CONSTRAINT ck_empleados_titular_tel    CHECK (titular_telefono IS NULL OR titular_telefono REGEXP '^0[24][0-9]{2}-[0-9]{7}$'),
    ADD CONSTRAINT ck_empleados_tercero        CHECK (tipo_cuenta = 'propia' OR titular_cedula IS NOT NULL);
