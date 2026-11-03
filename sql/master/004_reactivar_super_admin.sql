-- =====================================================================
-- ADARA SaaS · Master 004: el propietario puede reactivar un acceso revocado
--   Se retira el cerrojo "un revocado no se reactiva". El propietario (id 1)
--   sigue intocable y las filas siguen sin poder borrarse.
--   La reactivación la hace solo el propietario desde el Panel Maestro:
--   activo = 1, revocado_en = NULL y una clave temporal nueva.
-- =====================================================================
USE adara_master;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP TRIGGER IF EXISTS trg_super_admins_propietario_bu;

DELIMITER $$
CREATE TRIGGER trg_super_admins_propietario_bu BEFORE UPDATE ON super_admins FOR EACH ROW
BEGIN
    IF OLD.id = 1 AND (NEW.id <> 1 OR NEW.activo <> 1 OR NEW.revocado_en IS NOT NULL OR NEW.correo <> OLD.correo) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El super admin propietario (id 1) no se puede suspender, revocar ni modificar.';
    END IF;
END$$
DELIMITER ;
