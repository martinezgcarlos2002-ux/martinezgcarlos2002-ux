-- =====================================================================
-- ADARA · Migración 051: el cierre de productor es un corte de cuenta
--   Antes, cualquier fecha dentro de semana_inicio..semana_fin quedaba
--   bloqueada. Un corte a mitad de semana (miércoles) impedía cargar
--   el jueves y el viernes.
--   Ahora solo se rechaza una entrega cuya fecha sea menor o igual al
--   semana_fin de un cierre vigente. Lo posterior sigue abierto y entra
--   en el siguiente período.
-- Ejecutar en la base física de CADA empresa.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_entregas_semana_abierta_ins;

DELIMITER $$
CREATE TRIGGER trg_entregas_semana_abierta_ins BEFORE INSERT ON productores_entregas FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM productores_cierres c
        WHERE c.productor_id = NEW.productor_id
          AND NEW.fecha <= c.semana_fin
          AND c.estado <> 'anulada'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La recoleccion hasta el ultimo corte ya esta cerrada';
    END IF;
END$$
DELIMITER ;
