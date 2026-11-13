-- Auditoría inmutable de Usuarios Maestros y bitácora diaria de soporte.
-- Se ejecuta en la base maestra (adara_master), no en la base de un cliente.
-- Idempotente en las tablas. Los triggers se recrean.

CREATE TABLE IF NOT EXISTS soporte_auditoria (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_maestro        INT UNSIGNED    NOT NULL,
    id_cliente        INT UNSIGNED    NULL,
    accion_realizada  VARCHAR(500)    NOT NULL,
    fecha_hora        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    direccion_ip      VARCHAR(45)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_soporte_auditoria_fecha (fecha_hora),
    KEY ix_soporte_auditoria_maestro (id_maestro, fecha_hora),
    CONSTRAINT fk_soporte_auditoria_maestro
        FOREIGN KEY (id_maestro) REFERENCES super_admins (id),
    CONSTRAINT fk_soporte_auditoria_cliente
        FOREIGN KEY (id_cliente) REFERENCES clientes_saas (id),
    CONSTRAINT ck_soporte_auditoria_accion CHECK (CHAR_LENGTH(accion_realizada) BETWEEN 1 AND 500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soporte_bitacora (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_maestro         INT UNSIGNED    NOT NULL,
    id_cliente         INT UNSIGNED    NOT NULL,
    descripcion_tarea  VARCHAR(500)    NOT NULL,
    estado_tarea       ENUM('en_curso', 'resuelta', 'en_espera') NOT NULL,
    fecha              DATE            NOT NULL,
    PRIMARY KEY (id),
    KEY ix_soporte_bitacora_fecha (fecha, id_maestro),
    CONSTRAINT fk_soporte_bitacora_maestro
        FOREIGN KEY (id_maestro) REFERENCES super_admins (id),
    CONSTRAINT fk_soporte_bitacora_cliente
        FOREIGN KEY (id_cliente) REFERENCES clientes_saas (id),
    CONSTRAINT ck_soporte_bitacora_texto CHECK (CHAR_LENGTH(descripcion_tarea) BETWEEN 8 AND 500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_soporte_auditoria_no_update;
DROP TRIGGER IF EXISTS trg_soporte_auditoria_no_delete;
DROP TRIGGER IF EXISTS trg_soporte_bitacora_no_update;
DROP TRIGGER IF EXISTS trg_soporte_bitacora_no_delete;

DELIMITER $$
CREATE TRIGGER trg_soporte_auditoria_no_update BEFORE UPDATE ON soporte_auditoria FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'soporte_auditoria es de solo inserción.'$$
CREATE TRIGGER trg_soporte_auditoria_no_delete BEFORE DELETE ON soporte_auditoria FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'soporte_auditoria es de solo inserción.'$$
CREATE TRIGGER trg_soporte_bitacora_no_update BEFORE UPDATE ON soporte_bitacora FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'soporte_bitacora es de solo inserción: no se edita ni se borra.'$$
CREATE TRIGGER trg_soporte_bitacora_no_delete BEFORE DELETE ON soporte_bitacora FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'soporte_bitacora es de solo inserción: no se edita ni se borra.'$$
DELIMITER ;
