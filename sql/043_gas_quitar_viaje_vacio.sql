-- =====================================================================
-- ADARA · Migración 043: quitar un viaje vacío aunque tenga fecha de entrega
-- Un despacho sin bombonas y sin abonos no conserva historial.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_gas_despacho_bd;

DELIMITER $$

CREATE TRIGGER trg_gas_despacho_bd BEFORE DELETE ON gas_despachos FOR EACH ROW
BEGIN
    IF OLD.cantidad > 0 OR OLD.pagado_usd > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se borra un despacho vacio, sin abonos';
    END IF;
END$$

DELIMITER ;
