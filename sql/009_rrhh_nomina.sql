-- =====================================================================
-- ADARA · Migración 009: Recursos Humanos (nómina, días libres, cobranzas a terceros)
--   * empleados.salario_semanal_usd es la base de la nómina: salario diario = semanal / 7.
--   * empleados.dias_libres_mes: cupo de libres pagados por mes calendario. Se pueden asignar más;
--     los que exceden el cupo se descuentan como días no trabajados.
--   * cargos.cobertura_minima: personal presente por debajo del cual un libre genera alerta.
--     La alerta nunca bloquea la asignación.
--   * dias_libres: un registro por empleado y fecha ('libre' o 'no_trabajado').
--   * nomina_semanal pierde bodega_bs: las deudas con terceros viven en cobranzas_terceros
--     y no se restan del neto de la nómina oficial.
--   * Neto = (salario semanal − desc. días) + pagos extras − vales.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE empleados
    MODIFY salario_semanal_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN expediente_penal TINYINT(1)       NOT NULL DEFAULT 0 AFTER cuenta_propia,
    ADD COLUMN dias_libres_mes  TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER salario_semanal_usd,
    ADD KEY ix_empleados_cargo (activo, cargo),
    ADD CONSTRAINT ck_empleados_penal  CHECK (expediente_penal IN (0,1)),
    ADD CONSTRAINT ck_empleados_libres CHECK (dias_libres_mes <= 31);

CREATE TABLE cargos (
    nombre           VARCHAR(60)      NOT NULL,
    cobertura_minima TINYINT UNSIGNED NOT NULL DEFAULT 1,
    creado_en        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (nombre),
    CONSTRAINT ck_cargos_nombre    CHECK (CHAR_LENGTH(TRIM(nombre)) >= 2 AND nombre = UPPER(nombre)),
    CONSTRAINT ck_cargos_cobertura CHECK (cobertura_minima BETWEEN 1 AND 20)
) ENGINE=InnoDB;

INSERT INTO cargos (nombre) VALUES
    ('ADMINISTRADOR'), ('AYUDANTE'), ('AYUDANTE DE MANTENIMIENTO'), ('COCINERA'), ('CONTROL DE LECHE'),
    ('CUADOR'), ('EXPENDIDOR'), ('MANTENIMIENTO'), ('SERVIDOR'), ('UTILITY');

UPDATE empleados SET cargo = UPPER(TRIM(cargo)) WHERE cargo IS NOT NULL;
UPDATE empleados SET cargo = NULL WHERE cargo = '';

INSERT IGNORE INTO cargos (nombre)
SELECT DISTINCT cargo FROM empleados WHERE cargo IS NOT NULL AND CHAR_LENGTH(cargo) >= 2;

CREATE TABLE dias_libres (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empleado_id    INT UNSIGNED NOT NULL,
    fecha          DATE         NOT NULL,
    tipo           ENUM('libre','no_trabajado') NOT NULL DEFAULT 'libre',
    registrado_por INT UNSIGNED NOT NULL,
    creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dias_libres_empleado_fecha (empleado_id, fecha),
    KEY ix_dias_libres_fecha (fecha, tipo),
    CONSTRAINT fk_dias_libres_empleado FOREIGN KEY (empleado_id)    REFERENCES empleados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_dias_libres_usuario  FOREIGN KEY (registrado_por) REFERENCES usuarios (id)  ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE cobranzas_terceros (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    empleado_id    INT UNSIGNED  NOT NULL,
    acreedor       VARCHAR(80)   NOT NULL,
    concepto       VARCHAR(120)  NOT NULL,
    monto_usd      DECIMAL(12,2) NOT NULL,
    fecha          DATE          NOT NULL,
    estado         ENUM('pendiente','descontado','anulado') NOT NULL DEFAULT 'pendiente',
    descontado_en  DATETIME      NULL,
    registrado_por INT UNSIGNED  NOT NULL,
    actualizado_por INT UNSIGNED NULL,
    creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_cobranzas_empleado (empleado_id, estado),
    KEY ix_cobranzas_estado_fecha (estado, fecha),
    CONSTRAINT fk_cobranzas_empleado   FOREIGN KEY (empleado_id)     REFERENCES empleados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cobranzas_usuario    FOREIGN KEY (registrado_por)  REFERENCES usuarios (id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cobranzas_actualizo  FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cobranzas_monto      CHECK (monto_usd > 0),
    CONSTRAINT ck_cobranzas_textos     CHECK (CHAR_LENGTH(TRIM(acreedor)) >= 2 AND CHAR_LENGTH(TRIM(concepto)) >= 2),
    CONSTRAINT ck_cobranzas_descontado CHECK (estado <> 'descontado' OR descontado_en IS NOT NULL)
) ENGINE=InnoDB;

-- Lo que hubiera en bodega_bs pasa a cobranzas antes de eliminar la columna.
INSERT INTO cobranzas_terceros (empleado_id, acreedor, concepto, monto_usd, fecha, estado, descontado_en, registrado_por)
SELECT n.empleado_id, 'Bodega', CONCAT('Bodega semana ', DATE_FORMAT(n.semana_inicio, '%d/%m/%Y')),
       ROUND(n.bodega_bs / n.tasa_bs_por_usd, 2), n.semana_inicio,
       IF(n.estado = 'borrador', 'pendiente', 'descontado'),
       IF(n.estado = 'borrador', NULL, COALESCE(n.actualizado_en, n.creado_en)),
       n.registrado_por
FROM nomina_semanal n
WHERE n.bodega_bs > 0 AND n.tasa_bs_por_usd > 0 AND ROUND(n.bodega_bs / n.tasa_bs_por_usd, 2) > 0;

ALTER TABLE nomina_semanal
    DROP CONSTRAINT ck_nomina_tasa,
    DROP CONSTRAINT ck_nomina_montos;

ALTER TABLE nomina_semanal
    DROP COLUMN bodega_bs,
    ADD COLUMN semana_fin            DATE          NULL AFTER semana_inicio,
    ADD COLUMN salario_diario_usd    DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER salario_base_usd,
    ADD COLUMN dias_ausencia         DECIMAL(3,1)  NOT NULL DEFAULT 0.0 AFTER dias_no_trabajados,
    ADD COLUMN dias_libres_excedidos DECIMAL(3,1)  NOT NULL DEFAULT 0.0 AFTER dias_ausencia,
    ADD COLUMN dias_fuera_contrato   DECIMAL(3,1)  NOT NULL DEFAULT 0.0 AFTER dias_libres_excedidos,
    ADD COLUMN desc_dias_usd         DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER dias_fuera_contrato,
    ADD COLUMN pendiente_usd         DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER neto_usd;

UPDATE nomina_semanal SET semana_fin = DATE_ADD(semana_inicio, INTERVAL 6 DAY) WHERE semana_fin IS NULL;

ALTER TABLE nomina_semanal
    MODIFY semana_fin DATE NOT NULL,
    ADD KEY ix_nomina_semana (semana_inicio, estado),
    ADD CONSTRAINT ck_nomina_periodo  CHECK (semana_fin = DATE_ADD(semana_inicio, INTERVAL 6 DAY)),
    ADD CONSTRAINT ck_nomina_montos   CHECK (salario_base_usd >= 0 AND salario_diario_usd >= 0 AND desc_dias_usd >= 0
                                             AND desc_dias_usd <= salario_base_usd AND pagos_extras_usd >= 0
                                             AND vales_mercancia_usd >= 0 AND pendiente_usd >= 0),
    ADD CONSTRAINT ck_nomina_desglose CHECK (dias_ausencia >= 0 AND dias_libres_excedidos >= 0 AND dias_fuera_contrato >= 0
                                             AND dias_ausencia + dias_libres_excedidos + dias_fuera_contrato = dias_no_trabajados),
    ADD CONSTRAINT ck_nomina_neto     CHECK (neto_usd = salario_base_usd - desc_dias_usd + pagos_extras_usd - vales_mercancia_usd),
    ADD CONSTRAINT ck_nomina_tasa     CHECK (tasa_bs_por_usd IS NULL OR tasa_bs_por_usd > 0);
