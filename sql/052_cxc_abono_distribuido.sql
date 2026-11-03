-- =====================================================================
-- ADARA · Migración 052: trazabilidad del abono distribuido
--   Varias filas de cxc_pagos nacen de un solo abono. Comparten lote_hash,
--   el monto original y la tasa del día, para no confundirlas con pagos sueltos.
-- Ejecutar en la base física de CADA empresa.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE cxc_pagos
    ADD COLUMN lote_hash           VARCHAR(20)    NULL AFTER lote,
    ADD COLUMN monto_global_origen DECIMAL(10,2)  NULL AFTER lote_hash,
    ADD COLUMN tasa_cambio_dia     DECIMAL(10,2)  NULL AFTER monto_global_origen,
    ADD KEY ix_cxc_pagos_lote_hash (lote_hash);
