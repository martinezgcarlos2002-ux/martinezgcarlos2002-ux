-- =====================================================================
-- ADARA · Migración 010: deuda interna del empleado y cobranzas por semana
--   * "Pendiente" deja de ser saldo a favor: es la deuda del empleado con la empresa.
--     Los préstamos viven en prestamos_empleados; las cuotas se descuentan en nomina_semanal.
--       deuda_saldo_usd = préstamos − cuotas de otras semanas (fotografía antes de la cuota)
--       neto            = (salario − desc. días) + extras − vales − cuota de deuda
--   * cobranzas_terceros.semana_descuento: semana de nómina en la que el cajero retiene la deuda.
--     nomina_semanal.cobranzas_usd congela ese total al cerrar. El neto oficial no lo incluye;
--     el pago en caja (resto) sí: resto = neto − cobranzas de la semana.
--   * Cobranzas registradas en bolívares guardan el monto y la tasa de origen; monto_usd es la conversión.
--   * Reabrir una nómina vuelve el estado a 'borrador' (Abierta); el motivo queda en auditoria.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE prestamos_empleados (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    empleado_id    INT UNSIGNED  NOT NULL,
    fecha          DATE          NOT NULL,
    monto_usd      DECIMAL(12,2) NOT NULL,
    concepto       VARCHAR(120)  NOT NULL,
    registrado_por INT UNSIGNED  NOT NULL,
    creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_prestamos_empleado (empleado_id, fecha),
    CONSTRAINT fk_prestamos_empleado FOREIGN KEY (empleado_id)    REFERENCES empleados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_prestamos_usuario  FOREIGN KEY (registrado_por) REFERENCES usuarios (id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_prestamos_monto    CHECK (monto_usd > 0),
    CONSTRAINT ck_prestamos_concepto CHECK (CHAR_LENGTH(TRIM(concepto)) >= 2)
) ENGINE=InnoDB;

ALTER TABLE nomina_semanal
    DROP CONSTRAINT ck_nomina_montos,
    DROP CONSTRAINT ck_nomina_neto;

ALTER TABLE nomina_semanal
    CHANGE pendiente_usd deuda_saldo_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN cuota_deuda_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER deuda_saldo_usd,
    ADD COLUMN cobranzas_usd   DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cuota_deuda_usd;

UPDATE nomina_semanal SET deuda_saldo_usd = 0.00;

ALTER TABLE nomina_semanal
    ADD CONSTRAINT ck_nomina_montos CHECK (salario_base_usd >= 0 AND salario_diario_usd >= 0 AND desc_dias_usd >= 0
                                           AND desc_dias_usd <= salario_base_usd AND pagos_extras_usd >= 0
                                           AND vales_mercancia_usd >= 0 AND deuda_saldo_usd >= 0
                                           AND cuota_deuda_usd >= 0 AND cuota_deuda_usd <= deuda_saldo_usd
                                           AND cobranzas_usd >= 0),
    ADD CONSTRAINT ck_nomina_neto   CHECK (neto_usd = salario_base_usd - desc_dias_usd + pagos_extras_usd
                                                      - vales_mercancia_usd - cuota_deuda_usd);

ALTER TABLE cobranzas_terceros
    ADD COLUMN moneda_origen    ENUM('USD','VES') NOT NULL DEFAULT 'USD' AFTER monto_usd,
    ADD COLUMN monto_origen     DECIMAL(14,2)     NULL AFTER moneda_origen,
    ADD COLUMN tasa_bs_por_usd  DECIMAL(14,4)     NULL AFTER monto_origen,
    ADD COLUMN semana_descuento DATE              NULL AFTER estado,
    ADD KEY ix_cobranzas_semana (semana_descuento, estado, empleado_id);

UPDATE cobranzas_terceros SET monto_origen = monto_usd WHERE monto_origen IS NULL;

-- Ciclo lunes-domingo (NOMINA_DIA_INICIO = 1): WEEKDAY() devuelve 0 el lunes.
UPDATE cobranzas_terceros
SET semana_descuento = DATE_SUB(DATE(descontado_en), INTERVAL WEEKDAY(descontado_en) DAY)
WHERE estado = 'descontado' AND semana_descuento IS NULL;

ALTER TABLE cobranzas_terceros
    MODIFY monto_origen DECIMAL(14,2) NOT NULL,
    ADD CONSTRAINT ck_cobranzas_origen  CHECK (monto_origen > 0 AND (moneda_origen = 'USD' OR tasa_bs_por_usd > 0)),
    ADD CONSTRAINT ck_cobranzas_semana  CHECK (estado <> 'descontado' OR semana_descuento IS NOT NULL);

UPDATE nomina_semanal n
JOIN (SELECT empleado_id, semana_descuento, SUM(monto_usd) AS usd
      FROM cobranzas_terceros
      WHERE estado = 'descontado'
      GROUP BY empleado_id, semana_descuento) c
  ON c.empleado_id = n.empleado_id AND c.semana_descuento = n.semana_inicio
SET n.cobranzas_usd = c.usd;
