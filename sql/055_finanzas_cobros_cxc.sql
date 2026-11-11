-- =====================================================================
-- ADARA · Migración 055: cobros de facturas (CxC) en el libro de caja
--   Cada pago aplicado de cxc_pagos (salvo saldo a favor) deja un ingreso en
--   caja_movimientos con origen 'cobro_factura'. cxc_pago_id es único: un pago
--   no se asienta dos veces. cxc_factura_id permite filtrar por factura.
--   La anulación del pago se revierte con contra-asiento (revierte_id).
--
--   Requiere 040/041 aplicadas (enum y CHECK incluyen gas). Para bases que no
--   estén al día, usar bin/migrar_055_cobros_cxc.php: ajusta el esquema leyendo
--   el enum y el CHECK actuales y además carga los cobros desde 2026-09-01.
-- Ejecutar en la base física de CADA empresa.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE caja_movimientos
    MODIFY origen ENUM(
        'manual','venta','anulacion_venta','pago_productor','nomina','contra_asiento','pago_gas','reparacion_gas','cobro_factura'
    ) NOT NULL,
    ADD COLUMN cxc_pago_id    BIGINT UNSIGNED NULL AFTER gas_reparacion_id,
    ADD COLUMN cxc_factura_id INT UNSIGNED    NULL AFTER cxc_pago_id,
    ADD UNIQUE KEY uq_caja_cxc_pago (cxc_pago_id),
    ADD KEY ix_caja_cxc_factura (cxc_factura_id),
    ADD CONSTRAINT fk_caja_cxc_pago FOREIGN KEY (cxc_pago_id) REFERENCES cxc_pagos (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_caja_cxc_factura FOREIGN KEY (cxc_factura_id) REFERENCES cxc_facturas (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE caja_movimientos DROP CONSTRAINT ck_caja_origen;
ALTER TABLE caja_movimientos ADD CONSTRAINT ck_caja_origen CHECK (
    (origen IN ('venta','anulacion_venta') AND venta_id IS NOT NULL)
    OR (origen = 'pago_productor' AND (abono_id IS NOT NULL OR cierre_id IS NOT NULL))
    OR (origen = 'nomina' AND nomina_id IS NOT NULL)
    OR (origen = 'contra_asiento' AND revierte_id IS NOT NULL)
    OR (origen = 'pago_gas' AND gas_pago_id IS NOT NULL)
    OR (origen = 'reparacion_gas' AND gas_reparacion_id IS NOT NULL)
    OR (origen = 'cobro_factura' AND cxc_pago_id IS NOT NULL AND cxc_factura_id IS NOT NULL)
    OR origen = 'manual'
);
