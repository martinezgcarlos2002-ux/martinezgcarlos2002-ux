-- =====================================================================
-- Panel de soporte en Hostinger.
-- Importar en phpMyAdmin con la base u980302731_master ya seleccionada.
-- No crea la base: en este hosting el nombre lo asigna hPanel.
-- El dueño queda con clave temporal y debe cambiarla en el primer acceso.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE super_admins (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nombre              VARCHAR(80)   NOT NULL,
    correo              VARCHAR(100)  NOT NULL,
    cedula              VARCHAR(20)   NULL,
    telefono            VARCHAR(30)   NULL,
    direccion           TEXT          NULL,
    password_hash       VARCHAR(255)  NOT NULL,
    activo              TINYINT(1)    NOT NULL DEFAULT 1,
    debe_cambiar_clave  TINYINT(1)    NOT NULL DEFAULT 0,
    revocado_en         DATETIME      NULL,
    creado_por          INT UNSIGNED  NULL,
    ultimo_acceso       DATETIME      NULL,
    creado_en           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_super_admins_correo (correo),
    CONSTRAINT fk_super_admins_creado_por FOREIGN KEY (creado_por) REFERENCES super_admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_super_admins_correo CHECK (correo = LOWER(correo) AND correo LIKE '_%@_%._%'),
    CONSTRAINT ck_super_admins_revocado CHECK (revocado_en IS NULL OR activo = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clientes_saas (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_empresa              VARCHAR(120) NOT NULL,
    dominio_o_slug              VARCHAR(100) NOT NULL,
    correo_admin_cliente        VARCHAR(100) NOT NULL,
    nombre_db_fisica            VARCHAR(64)  NOT NULL,
    estado_licencia             ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
    contrato_ruta               VARCHAR(255) NULL,
    fecha_aceptacion_contrato   DATETIME     NULL,
    contrato_aceptado           TINYINT(1)   NOT NULL DEFAULT 0,
    registrado_por              INT UNSIGNED NOT NULL,
    creado_en                   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en              DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_slug (dominio_o_slug),
    UNIQUE KEY uq_clientes_correo (correo_admin_cliente),
    UNIQUE KEY uq_clientes_db (nombre_db_fisica),
    KEY ix_clientes_estado (estado_licencia),
    CONSTRAINT fk_clientes_registrado_por FOREIGN KEY (registrado_por) REFERENCES super_admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_clientes_db CHECK (
        nombre_db_fisica REGEXP '^(adara|adara_[a-z0-9_]{1,58}|u[0-9]{6,12}_[a-z0-9_]{1,40})$'
        AND nombre_db_fisica NOT IN ('adara_master', 'u980302731_master')
    ),
    CONSTRAINT ck_clientes_slug CHECK (dominio_o_slug REGEXP '^[a-z0-9]([a-z0-9.-]{1,98}[a-z0-9])?$'),
    CONSTRAINT ck_clientes_correo CHECK (correo_admin_cliente = LOWER(correo_admin_cliente) AND correo_admin_cliente LIKE '_%@_%._%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_intentos (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(100)    NOT NULL,
    ip        VARCHAR(45)     NOT NULL,
    exito     TINYINT(1)      NOT NULL,
    creado_en DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_login_intentos_ip (ip, exito, creado_en),
    KEY ix_login_intentos_email (email, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE master_auditoria (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    super_admin_id INT UNSIGNED    NULL,
    accion         VARCHAR(40)     NOT NULL,
    cliente_id     INT UNSIGNED    NULL,
    detalle        JSON            NULL,
    ip             VARCHAR(45)     NOT NULL,
    creado_en      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY ix_master_auditoria_fecha (creado_en),
    KEY ix_master_auditoria_cliente (cliente_id, creado_en),
    CONSTRAINT fk_master_auditoria_admin FOREIGN KEY (super_admin_id) REFERENCES super_admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_master_auditoria_no_update BEFORE UPDATE ON master_auditoria FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'master_auditoria es de solo inserción.'$$
CREATE TRIGGER trg_master_auditoria_no_delete BEFORE DELETE ON master_auditoria FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'master_auditoria es de solo inserción.'$$
CREATE TRIGGER trg_super_admins_propietario_bu BEFORE UPDATE ON super_admins FOR EACH ROW
BEGIN
    IF OLD.id = 1 AND (NEW.id <> 1 OR NEW.activo <> 1 OR NEW.revocado_en IS NOT NULL OR NEW.correo <> OLD.correo) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El super admin propietario (id 1) no se puede suspender, revocar ni modificar.';
    END IF;
END$$
CREATE TRIGGER trg_super_admins_no_delete BEFORE DELETE ON super_admins FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los super admins no se borran: se revocan.'$$
DELIMITER ;

INSERT INTO super_admins (id, nombre, correo, password_hash, activo, debe_cambiar_clave)
VALUES (
    1,
    'Carlos Martinez',
    'martinezgcarlos2002@gmail.com',
    '$2y$12$/FsUCr0ow8IgilVrd8Uryusfgp6rVbKC2tU5nG7TNobxe9sawM8Ze',
    1,
    1
);

INSERT INTO clientes_saas (
    nombre_empresa, dominio_o_slug, correo_admin_cliente, nombre_db_fisica, estado_licencia, registrado_por
) VALUES (
    'El Castillo del Queso Artesanal F.P.',
    'adarasystem.online',
    'carlosmanuel@gmail.com',
    'u980302731_adarabd',
    'activa',
    1
);
