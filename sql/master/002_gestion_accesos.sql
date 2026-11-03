-- =====================================================================
-- ADARA SaaS · Master 002: gestión de accesos de super admins
--   - debe_cambiar_clave: claves iniciales/temporales se cambian en el primer acceso.
--   - revocado_en: revocación definitiva (no se borra la fila: la bitácora y las empresas la referencian).
--   - creado_por: quién otorgó el acceso.
--   - El super admin id 1 (propietario) es intocable también a nivel de base de datos.
-- =====================================================================
USE adara_master;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE super_admins
    ADD COLUMN debe_cambiar_clave TINYINT(1)   NOT NULL DEFAULT 0 AFTER activo,
    ADD COLUMN revocado_en        DATETIME     NULL AFTER debe_cambiar_clave,
    ADD COLUMN creado_por         INT UNSIGNED NULL AFTER revocado_en,
    ADD CONSTRAINT fk_super_admins_creado_por FOREIGN KEY (creado_por) REFERENCES super_admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT ck_super_admins_revocado CHECK (revocado_en IS NULL OR activo = 0);

DELIMITER $$
CREATE TRIGGER trg_super_admins_propietario_bu BEFORE UPDATE ON super_admins FOR EACH ROW
BEGIN
    IF OLD.id = 1 AND (NEW.id <> 1 OR NEW.activo <> 1 OR NEW.revocado_en IS NOT NULL OR NEW.correo <> OLD.correo) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El super admin propietario (id 1) no se puede suspender, revocar ni modificar.';
    END IF;
    IF OLD.revocado_en IS NOT NULL AND (NEW.revocado_en IS NULL OR NEW.activo <> 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un acceso revocado no se puede reactivar.';
    END IF;
END$$
CREATE TRIGGER trg_super_admins_no_delete BEFORE DELETE ON super_admins FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los super admins no se borran: se revocan.'$$
DELIMITER ;
