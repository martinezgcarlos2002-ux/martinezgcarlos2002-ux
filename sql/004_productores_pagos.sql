-- =====================================================================
-- ADARA · Migración 004: pagos a productores integrados con caja
--   * Tipos de pago: efectivo, pm_trans (pago móvil / transferencia), mercancia. Sin vales ni cortesías.
--   * Un pago no se borra: se anula (quién, cuándo, motivo) y su egreso de caja se revierte con contra-asiento.
--   * Cada egreso de caja de un pago queda ligado 1:1 al pago (abono_id).
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

-- Si existen filas 'vale' u 'otro' el último MODIFY falla en modo estricto: deben reclasificarse a mano antes.
ALTER TABLE productores_abonos
    MODIFY tipo ENUM('efectivo','pago_movil','transferencia','mercancia','vale','otro','pm_trans') NOT NULL;
UPDATE productores_abonos SET tipo = 'pm_trans' WHERE tipo IN ('pago_movil','transferencia');
ALTER TABLE productores_abonos
    MODIFY tipo ENUM('efectivo','pm_trans','mercancia') NOT NULL;

ALTER TABLE productores_abonos
    ADD COLUMN anulado_en       DATETIME     NULL AFTER registrado_por,
    ADD COLUMN anulado_por      INT UNSIGNED NULL AFTER anulado_en,
    ADD COLUMN motivo_anulacion VARCHAR(255) NULL AFTER anulado_por,
    ADD KEY ix_abonos_semana (semana_inicio),
    ADD CONSTRAINT fk_abonos_anulador FOREIGN KEY (anulado_por) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT ck_abonos_anulacion CHECK (
        (anulado_en IS NULL AND anulado_por IS NULL AND motivo_anulacion IS NULL)
        OR (anulado_en IS NOT NULL AND anulado_por IS NOT NULL AND motivo_anulacion IS NOT NULL)
    );

-- 'arrastrada': semana con saldo que ya se consolidó en el cierre siguiente (evita contarla dos veces).
ALTER TABLE productores_cierres
    MODIFY estado ENUM('cerrada','parcial','pagada','arrastrada','anulada') NOT NULL DEFAULT 'cerrada',
    MODIFY notas  VARCHAR(500) NULL;

ALTER TABLE caja_movimientos
    ADD COLUMN abono_id BIGINT UNSIGNED NULL AFTER cierre_id,
    ADD UNIQUE KEY uq_caja_abono (abono_id),
    ADD CONSTRAINT fk_caja_abono FOREIGN KEY (abono_id) REFERENCES productores_abonos (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE caja_movimientos DROP CONSTRAINT ck_caja_origen;
ALTER TABLE caja_movimientos ADD CONSTRAINT ck_caja_origen CHECK (
    (origen IN ('venta','anulacion_venta') AND venta_id IS NOT NULL)
    OR (origen = 'pago_productor' AND (abono_id IS NOT NULL OR cierre_id IS NOT NULL))
    OR (origen = 'nomina' AND nomina_id IS NOT NULL)
    OR (origen = 'contra_asiento' AND revierte_id IS NOT NULL)
    OR origen = 'manual'
);

DELIMITER $$

CREATE TRIGGER trg_abonos_no_delete BEFORE DELETE ON productores_abonos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos no se eliminan: se anulan';
END$$

-- Solo se permite ligar el pago a su cierre o anularlo una única vez; los datos económicos son inmutables.
CREATE TRIGGER trg_abonos_inmutable BEFORE UPDATE ON productores_abonos FOR EACH ROW
BEGIN
    IF OLD.anulado_en IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago ya esta anulado';
    END IF;
    IF NOT (NEW.productor_id = OLD.productor_id AND NEW.fecha = OLD.fecha AND NEW.semana_inicio = OLD.semana_inicio
            AND NEW.tipo = OLD.tipo AND NEW.moneda = OLD.moneda AND NEW.monto = OLD.monto
            AND NEW.monto_usd = OLD.monto_usd AND NEW.tasa_bs_por_usd <=> OLD.tasa_bs_por_usd
            AND NEW.registrado_por = OLD.registrado_por) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los datos economicos del pago son inmutables';
    END IF;
END$$

DELIMITER ;
