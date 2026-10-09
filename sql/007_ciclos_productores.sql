-- =====================================================================
-- ADARA · Migración 007: ciclo semanal por productor y costos de producción
--   * productores.dia_cierre: día ISO en que cierra el ciclo (1 = lunes … 7 = domingo).
--     El ciclo abre el día siguiente y dura 7 días. 5 (viernes) conserva el ciclo sábado-viernes previo.
--   * Los CHECK que fijaban el inicio en sábado se sustituyen por triggers que validan contra el
--     dia_cierre del productor (un CHECK no puede consultar otra tabla).
--   * produccion_costos / produccion_costos_quesos: fotografía financiera diaria.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE productores
    ADD COLUMN dia_cierre TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER credito_dias,
    ADD CONSTRAINT ck_productores_dia_cierre CHECK (dia_cierre BETWEEN 1 AND 7);

ALTER TABLE productores_cierres DROP CONSTRAINT ck_cierres_sabado;
ALTER TABLE productores_abonos  DROP CONSTRAINT ck_abonos_semana;

-- Cierres anulados (semanas reabiertas) conservan su fila; la búsqueda por fecha los descarta.
ALTER TABLE productores_cierres
    ADD KEY ix_cierres_productor_rango (productor_id, semana_inicio, semana_fin);

DELIMITER $$

-- WEEKDAY(): lunes = 0 … domingo = 6. El ciclo abre el día ISO (dia_cierre % 7) + 1,
-- es decir WEEKDAY = dia_cierre % 7.
CREATE TRIGGER trg_cierres_inicio_ciclo BEFORE INSERT ON productores_cierres FOR EACH ROW
BEGIN
    IF WEEKDAY(NEW.semana_inicio) <> (SELECT MOD(p.dia_cierre, 7) FROM productores p WHERE p.id = NEW.productor_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cierre no inicia en el dia de apertura del ciclo del productor';
    END IF;
END$$

CREATE TRIGGER trg_abonos_inicio_ciclo BEFORE INSERT ON productores_abonos FOR EACH ROW
BEGIN
    IF WEEKDAY(NEW.semana_inicio) <> (SELECT MOD(p.dia_cierre, 7) FROM productores p WHERE p.id = NEW.productor_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago no aplica a un ciclo valido del productor';
    END IF;
END$$

DELIMITER ;

CREATE TABLE produccion_costos (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    fecha               DATE          NOT NULL,
    litros_recibidos    DECIMAL(12,3) NOT NULL,
    litros_procesados   DECIMAL(12,3) NOT NULL,
    precio_litro_usd    DECIMAL(12,4) NOT NULL,
    costo_leche_usd     DECIMAL(12,2) NOT NULL,
    nomina_usd          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    personal_extra_usd  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    transporte_usd      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    operativo_usd       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    otros_usd           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gasto_total_usd     DECIMAL(12,2) NOT NULL,
    kg_total            DECIMAL(12,3) NOT NULL,
    costo_comun_kg_usd  DECIMAL(12,4) NOT NULL,
    margen_pct          DECIMAL(5,2)  NOT NULL,
    registrado_por      INT UNSIGNED  NOT NULL,
    creado_en           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_costos_fecha (fecha),
    CONSTRAINT fk_costos_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_costos_litros  CHECK (litros_recibidos >= 0 AND litros_procesados > 0),
    CONSTRAINT ck_costos_montos  CHECK (costo_leche_usd >= 0 AND nomina_usd >= 0 AND personal_extra_usd >= 0
                                        AND transporte_usd >= 0 AND operativo_usd >= 0 AND otros_usd >= 0
                                        AND gasto_total_usd >= 0 AND kg_total > 0 AND costo_comun_kg_usd >= 0),
    CONSTRAINT ck_costos_margen  CHECK (margen_pct >= 0 AND margen_pct <= 500)
) ENGINE=InnoDB;

CREATE TABLE produccion_costos_quesos (
    id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    costo_id            INT UNSIGNED     NOT NULL,
    orden               TINYINT UNSIGNED NOT NULL,
    nombre              VARCHAR(80)      NOT NULL,
    litros_ref          DECIMAL(10,3)    NOT NULL,
    kg_ref              DECIMAL(10,3)    NOT NULL,
    litros_dia          DECIMAL(12,3)    NOT NULL,
    kg_dia              DECIMAL(12,3)    NOT NULL,
    sal_usd             DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
    empaque_usd         DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
    otros_usd           DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
    costo_total_usd     DECIMAL(12,2)    NOT NULL,
    costo_kg_usd        DECIMAL(12,4)    NOT NULL,
    precio_venta_kg_usd DECIMAL(12,4)    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_costos_quesos_orden (costo_id, orden),
    CONSTRAINT fk_costos_quesos_costo FOREIGN KEY (costo_id) REFERENCES produccion_costos (id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT ck_costos_quesos_ref   CHECK (litros_ref > 0 AND kg_ref > 0 AND litros_dia >= 0 AND kg_dia >= 0),
    CONSTRAINT ck_costos_quesos_extra CHECK (sal_usd >= 0 AND empaque_usd >= 0 AND otros_usd >= 0)
) ENGINE=InnoDB;
