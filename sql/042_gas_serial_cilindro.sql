-- =====================================================================
-- ADARA · Migración 042: serial automático de bombonas (CIL-000001)
--
-- MariaDB 10.4 rechaza GENERATED ALWAYS AS sobre una columna
-- AUTO_INCREMENT (ERROR 1901). El número se arma en el BEFORE INSERT
-- con el mismo correlativo de los despachos, y coincide con el id:
-- la bombona 101 queda CIL-000101 y la siguiente será CIL-000102.
--
-- No se borra la fila de prueba: está en un despacho. Solo se reemplaza
-- el serial escrito a mano (042462852) por el código del sistema.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP TRIGGER IF EXISTS trg_gas_cil_bu;
DROP TRIGGER IF EXISTS trg_gas_cil_bi;

UPDATE gas_cilindros
   SET serial = CONCAT('CIL-', LPAD(id, 6, '0'));

INSERT INTO gas_correlativos (clave, ultimo)
SELECT 'cilindro', COALESCE(MAX(id), 0) FROM gas_cilindros
ON DUPLICATE KEY UPDATE ultimo = VALUES(ultimo);

DELIMITER $$

-- MariaDB 10.4 rechaza GENERATED ALWAYS AS sobre AUTO_INCREMENT (ERROR 1901).
-- El BEFORE INSERT toma el siguiente número del correlativo, lo escribe en id
-- y arma el serial CIL-000102. Así el código sigue al id aunque un INSERT
-- revertido haya consumido el contador interno de la tabla.
CREATE TRIGGER trg_gas_cil_bi BEFORE INSERT ON gas_cilindros FOR EACH ROW
BEGIN
    UPDATE gas_correlativos
       SET ultimo = LAST_INSERT_ID(ultimo + 1)
     WHERE clave = 'cilindro';
    SET NEW.id = LAST_INSERT_ID();
    SET NEW.serial = CONCAT('CIL-', LPAD(NEW.id, 6, '0'));
END$$

CREATE TRIGGER trg_gas_cil_bu BEFORE UPDATE ON gas_cilindros FOR EACH ROW
BEGIN
    IF NEW.serial <> OLD.serial THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El serial de la bombona no se modifica';
    END IF;
    SET NEW.serial = OLD.serial;
    IF @gas_ciclo IS NULL AND NOT (NEW.despacho_abierto_id <=> OLD.despacho_abierto_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El viaje abierto de la bombona lo asigna el despacho';
    END IF;
    IF NEW.estado_registro = 'archivado' AND NEW.despacho_abierto_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se archiva una bombona que esta en un viaje abierto';
    END IF;
END$$

DELIMITER ;
