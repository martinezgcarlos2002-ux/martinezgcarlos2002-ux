-- =====================================================================
-- ADARA · Migración 023: préstamos con cuotas ligadas a la nómina
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS hr_prestamos (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    empleado_id    INT UNSIGNED  NOT NULL,
    monto_total    DECIMAL(12,2) NOT NULL,
    cuota_semanal  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tipo           ENUM('efectivo','transferencia','pago_movil','mercancia','otros') NOT NULL DEFAULT 'efectivo',
    fecha          DATE          NOT NULL,
    estado         ENUM('activo','pagado') NOT NULL DEFAULT 'activo',
    concepto       VARCHAR(120)  NOT NULL DEFAULT 'Préstamo interno',
    registrado_por INT UNSIGNED  NOT NULL,
    creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_hr_pr_emp (empleado_id, estado, fecha),
    CONSTRAINT fk_hr_pr_emp  FOREIGN KEY (empleado_id)    REFERENCES empleados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_hr_pr_user FOREIGN KEY (registrado_por) REFERENCES usuarios (id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_hr_pr_monto CHECK (monto_total > 0 AND cuota_semanal >= 0 AND cuota_semanal <= monto_total),
    CONSTRAINT ck_hr_pr_conc  CHECK (CHAR_LENGTH(TRIM(concepto)) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_prestamos_cuotas (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    prestamo_id INT UNSIGNED  NOT NULL,
    monto_cuota DECIMAL(12,2) NOT NULL,
    nomina_id   INT UNSIGNED  NOT NULL,
    creado_en   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hr_pc_nomina (prestamo_id, nomina_id),
    KEY ix_hr_pc_nomina (nomina_id),
    CONSTRAINT fk_hr_pc_pr  FOREIGN KEY (prestamo_id) REFERENCES hr_prestamos (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_hr_pc_nom FOREIGN KEY (nomina_id)   REFERENCES nomina_semanal (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_hr_pc_monto CHECK (monto_cuota > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_prestamos (empleado_id, monto_total, cuota_semanal, tipo, fecha, estado, concepto, registrado_por)
SELECT p.empleado_id, p.monto_usd, 0.00, 'efectivo', p.fecha, 'activo', p.concepto, p.registrado_por
FROM prestamos_empleados p
LEFT JOIN hr_prestamos h
       ON h.empleado_id = p.empleado_id AND h.fecha = p.fecha AND h.monto_total = p.monto_usd
WHERE h.id IS NULL;
