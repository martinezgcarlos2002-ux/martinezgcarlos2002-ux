-- =====================================================================
-- ADARA · Migración 008: tolerancia de pago y separación Pago Móvil / Transferencia
--   * productores.tolerancia_pago: días de gracia tras el fin del ciclo antes de marcar el pago
--     como atrasado (bajo = 2, medio = 5, alto = 10). La regla vive en Productor::TOLERANCIAS.
--   * productores_abonos.tipo: 'pago_movil' y 'transferencia' reemplazan a 'pm_trans'.
--     'pm_trans' queda solo para el histórico: trg_abonos_inmutable impide reclasificar pagos
--     existentes y trg_abonos_tipo_vigente impide registrar pagos nuevos con ese tipo.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE productores
    ADD COLUMN tolerancia_pago ENUM('bajo','medio','alto') NOT NULL DEFAULT 'medio' AFTER dia_cierre,
    ADD KEY ix_productores_dia_cierre (activo, dia_cierre);

ALTER TABLE productores_abonos
    MODIFY tipo ENUM('efectivo','pm_trans','mercancia','pago_movil','transferencia') NOT NULL,
    ADD CONSTRAINT ck_abonos_referencia_banco CHECK (
        tipo NOT IN ('pago_movil','transferencia') OR (referencia IS NOT NULL AND TRIM(referencia) <> '')
    );

-- Búsqueda de ciclos con saldo pendiente para el centro de notificaciones.
ALTER TABLE productores_cierres
    ADD KEY ix_cierres_estado (estado, productor_id, semana_inicio);

DELIMITER $$

CREATE TRIGGER trg_abonos_tipo_vigente BEFORE INSERT ON productores_abonos FOR EACH ROW
BEGIN
    IF NEW.tipo = 'pm_trans' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pm_trans es historico: registre Pago Movil o Transferencia';
    END IF;
END$$

DELIMITER ;
