-- =====================================================================
-- ADARA · Migración 014: tasa Bs/$ en pagos CxC y FIFO de devoluciones
--   * cxc_pagos guarda la tasa del día y el equivalente en bolívares.
--   * Una devolución a crédito (pendiente) pasa a 'aplicada' cuando el
--     saldo a favor se consume FIFO; el CHECK y el trigger lo permiten.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE cxc_pagos
    ADD COLUMN tasa_cambio DECIMAL(12,4) NULL AFTER notas,
    ADD COLUMN monto_bs    DECIMAL(12,2) NULL AFTER tasa_cambio;

ALTER TABLE cxc_pagos
    ADD CONSTRAINT ck_cxc_pagos_tasa CHECK (
        (tasa_cambio IS NULL AND monto_bs IS NULL)
        OR (tasa_cambio > 0 AND monto_bs > 0)
    );

ALTER TABLE cxc_devoluciones DROP CONSTRAINT ck_cxc_dev_destino;
ALTER TABLE cxc_devoluciones
    ADD CONSTRAINT ck_cxc_dev_destino CHECK (
        (estado = 'anulada')
     OR (destino = 'origen'            AND factura_origen_id IS NOT NULL AND factura_aplicada_id = factura_origen_id AND estado = 'aplicada')
     OR (destino = 'otra_factura'      AND factura_aplicada_id IS NOT NULL AND estado = 'aplicada')
     OR (destino = 'credito_pendiente' AND factura_aplicada_id IS NULL AND estado IN ('pendiente', 'aplicada'))
    );

DROP TRIGGER IF EXISTS trg_cxc_dev_no_update;

DELIMITER $$
CREATE TRIGGER trg_cxc_dev_no_update BEFORE UPDATE ON cxc_devoluciones FOR EACH ROW
BEGIN
    IF NOT (
        (
            OLD.estado IN ('aplicada','pendiente') AND NEW.estado = 'anulada'
            AND NEW.monto = OLD.monto AND NEW.cliente_id = OLD.cliente_id
            AND NEW.destino = OLD.destino
        )
        OR (
            OLD.estado = 'pendiente' AND NEW.estado = 'aplicada'
            AND NEW.monto = OLD.monto AND NEW.cliente_id = OLD.cliente_id
            AND NEW.destino = OLD.destino
            AND NEW.factura_origen_id <=> OLD.factura_origen_id
            AND NEW.factura_aplicada_id <=> OLD.factura_aplicada_id
        )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las devoluciones de CxC no se modifican; solo se anulan o se aplican FIFO';
    END IF;
END$$
DELIMITER ;
