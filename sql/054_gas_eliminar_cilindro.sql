-- =====================================================================
-- ADARA · Migración 054: eliminar bombonas sin historial
--   El trigger anterior rechazaba cualquier DELETE. Ahora solo protege
--   la bombona que ya salió en un viaje, está en un despacho abierto
--   o fue a reparación. Esas se archivan. Las cargadas por error se borran.
-- Ejecutar en la base física de CADA empresa.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_gas_cil_no_delete;

DELIMITER $$
CREATE TRIGGER trg_gas_cil_no_delete BEFORE DELETE ON gas_cilindros FOR EACH ROW
BEGIN
    IF OLD.despacho_abierto_id IS NOT NULL
       OR EXISTS (SELECT 1 FROM gas_despacho_cilindros WHERE cilindro_id = OLD.id)
       OR EXISTS (SELECT 1 FROM gas_reparaciones WHERE cilindro_id = OLD.id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La bombona ya tiene historial: archivela, no se elimina';
    END IF;
END$$
DELIMITER ;
