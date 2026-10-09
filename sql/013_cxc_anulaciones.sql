-- =====================================================================
-- ADARA · Migración 013: Anulación inmutable de CxC
--   No se borra ni se reescribe el asiento original: solo pasa a estado 'anulado'/'anulada'.
--   Los triggers de UPDATE permiten únicamente esa transición.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE cxc_facturas
    MODIFY estado ENUM('vigente','por_vencer','vencida','pagada','anulada') NOT NULL DEFAULT 'vigente';

ALTER TABLE cxc_facturas DROP CONSTRAINT ck_cxc_facturas_saldo;
ALTER TABLE cxc_facturas
    ADD CONSTRAINT ck_cxc_facturas_saldo CHECK (
        (estado = 'anulada' AND saldo_pendiente = 0)
        OR (estado <> 'anulada' AND saldo_pendiente = monto_total - total_pagado - total_devuelto)
    );

ALTER TABLE cxc_pagos
    ADD COLUMN estado ENUM('aplicado','anulado') NOT NULL DEFAULT 'aplicado' AFTER notas;

ALTER TABLE cxc_devoluciones
    MODIFY estado ENUM('aplicada','pendiente','anulada') NOT NULL;

ALTER TABLE cxc_devoluciones DROP CONSTRAINT ck_cxc_dev_destino;
ALTER TABLE cxc_devoluciones
    ADD CONSTRAINT ck_cxc_dev_destino CHECK (
        (estado = 'anulada')
     OR (destino = 'origen'            AND factura_origen_id IS NOT NULL AND factura_aplicada_id = factura_origen_id AND estado = 'aplicada')
     OR (destino = 'otra_factura'      AND factura_aplicada_id IS NOT NULL AND estado = 'aplicada')
     OR (destino = 'credito_pendiente' AND factura_aplicada_id IS NULL AND estado = 'pendiente')
    );

ALTER TABLE cxc_saldo_movimientos
    MODIFY tipo ENUM('devolucion','abono_excedente','aplicado_factura','reverso') NOT NULL;

ALTER TABLE cxc_saldo_movimientos DROP CONSTRAINT ck_cxc_smov_signo;
ALTER TABLE cxc_saldo_movimientos
    ADD CONSTRAINT ck_cxc_smov_signo CHECK (
        (tipo = 'aplicado_factura' AND monto < 0 AND pago_id IS NOT NULL)
     OR (tipo = 'devolucion' AND monto > 0 AND devolucion_id IS NOT NULL)
     OR (tipo = 'abono_excedente' AND monto > 0 AND lote IS NOT NULL)
     OR (tipo = 'reverso' AND monto <> 0 AND (pago_id IS NOT NULL OR devolucion_id IS NOT NULL))
    );

DROP TRIGGER IF EXISTS trg_cxc_pagos_no_update;
DROP TRIGGER IF EXISTS trg_cxc_dev_no_update;

DELIMITER $$
CREATE TRIGGER trg_cxc_pagos_no_update BEFORE UPDATE ON cxc_pagos FOR EACH ROW
BEGIN
    IF NOT (
        OLD.estado = 'aplicado' AND NEW.estado = 'anulado'
        AND NEW.monto = OLD.monto AND NEW.factura_id = OLD.factura_id
        AND NEW.cliente_id = OLD.cliente_id AND NEW.metodo = OLD.metodo
        AND NEW.fecha = OLD.fecha
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos de CxC no se modifican; solo se anulan';
    END IF;
END$$

CREATE TRIGGER trg_cxc_dev_no_update BEFORE UPDATE ON cxc_devoluciones FOR EACH ROW
BEGIN
    IF NOT (
        OLD.estado IN ('aplicada','pendiente') AND NEW.estado = 'anulada'
        AND NEW.monto = OLD.monto AND NEW.cliente_id = OLD.cliente_id
        AND NEW.destino = OLD.destino
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las devoluciones de CxC no se modifican; solo se anulan';
    END IF;
END$$
DELIMITER ;
