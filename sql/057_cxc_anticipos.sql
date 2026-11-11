-- =====================================================================
-- ADARA · Migración 057: anticipos de clientes
--   Un pago puede no tener factura (factura_id NULL) y queda amarrado al cliente.
--   Ese pago suma cxc_clientes.saldo_favor con un movimiento tipo 'anticipo'.
--   El ingreso de caja no exige factura: cxc_factura_id puede ser NULL.
-- Ejecutar en la base física de CADA empresa.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE cxc_pagos
    MODIFY factura_id INT UNSIGNED NULL,
    ADD CONSTRAINT ck_cxc_pagos_anticipo CHECK (factura_id IS NOT NULL OR metodo <> 'saldo_favor');

DROP TRIGGER IF EXISTS trg_cxc_pagos_no_update;
DELIMITER $$
CREATE TRIGGER trg_cxc_pagos_no_update BEFORE UPDATE ON cxc_pagos FOR EACH ROW
BEGIN
    IF NOT (
        OLD.estado = 'aplicado' AND NEW.estado = 'anulado'
        AND NEW.monto = OLD.monto AND NEW.factura_id <=> OLD.factura_id
        AND NEW.cliente_id = OLD.cliente_id AND NEW.metodo = OLD.metodo
        AND NEW.fecha = OLD.fecha
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos de CxC no se modifican; solo se anulan';
    END IF;
END$$
DELIMITER ;

ALTER TABLE cxc_saldo_movimientos
    MODIFY tipo ENUM('devolucion','abono_excedente','aplicado_factura','reverso','anticipo') NOT NULL;

ALTER TABLE cxc_saldo_movimientos DROP CONSTRAINT ck_cxc_smov_signo;
ALTER TABLE cxc_saldo_movimientos ADD CONSTRAINT ck_cxc_smov_signo CHECK (
       (tipo = 'aplicado_factura' AND monto < 0 AND pago_id IS NOT NULL)
    OR (tipo = 'devolucion' AND monto > 0 AND devolucion_id IS NOT NULL)
    OR (tipo = 'abono_excedente' AND monto > 0 AND lote IS NOT NULL)
    OR (tipo = 'anticipo' AND monto > 0 AND pago_id IS NOT NULL AND factura_id IS NULL AND devolucion_id IS NULL)
    OR (tipo = 'reverso' AND monto <> 0 AND (pago_id IS NOT NULL OR devolucion_id IS NOT NULL))
);

-- caja_movimientos.ck_caja_origen se ajusta en bin/migrar_057_anticipos.php:
-- un anticipo deja cxc_factura_id en NULL y el CHECK anterior lo rechazaba.
-- El script conserva los orígenes que esa base ya tenga (gas, nómina, etc.).
