-- =====================================================================
-- ADARA · Migración 005: cuentas del libro de caja
--   caja  = efectivo físico en gaveta: nunca puede quedar en negativo.
--   banco = pago móvil / transferencias: admite sobregiro virtual.
-- El libro sigue siendo de solo inserción (trg_caja_no_update / trg_caja_no_delete).
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE caja_movimientos
    ADD COLUMN cuenta ENUM('caja','banco') NOT NULL DEFAULT 'caja' AFTER tipo,
    ADD KEY ix_caja_cuenta_moneda (cuenta, moneda),
    ADD KEY ix_caja_tipo_fecha (tipo, fecha);
