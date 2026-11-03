-- =====================================================================
-- ADARA SaaS · Base maestra de control (Database-per-Tenant)
--   super_admins      → dueño del software (Panel Maestro SaaS). Sin acceso a datos de empresas.
--   clientes_saas     → una fila por empresa; nombre_db_fisica es su base física (adara_castillo, …).
--   login_intentos    → límite de intentos del login unificado (antes de saber a qué empresa va).
--   master_auditoria  → bitácora inmutable de acciones del Panel Maestro.
-- El primer super admin se crea con: php bin/crear_super_admin.php
-- =====================================================================
CREATE DATABASE IF NOT EXISTS adara_master CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE adara_master;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE super_admins (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(80)   NOT NULL,
    correo         VARCHAR(100)  NOT NULL,
    password_hash  VARCHAR(255)  NOT NULL,
    activo         TINYINT(1)    NOT NULL DEFAULT 1,
    ultimo_acceso  DATETIME      NULL,
    creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_super_admins_correo (correo),
    CONSTRAINT ck_super_admins_correo CHECK (correo = LOWER(correo) AND correo LIKE '_%@_%._%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clientes_saas (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_empresa       VARCHAR(120) NOT NULL,
    dominio_o_slug       VARCHAR(100) NOT NULL,
    correo_admin_cliente VARCHAR(100) NOT NULL,
    nombre_db_fisica     VARCHAR(64)  NOT NULL,
    estado_licencia      ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
    contrato_ruta              VARCHAR(255) NULL,
    fecha_aceptacion_contrato  DATETIME     NULL,
    registrado_por       INT UNSIGNED NOT NULL,
    creado_en            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_slug (dominio_o_slug),
    UNIQUE KEY uq_clientes_correo (correo_admin_cliente),
    UNIQUE KEY uq_clientes_db (nombre_db_fisica),
    KEY ix_clientes_estado (estado_licencia),
    CONSTRAINT fk_clientes_registrado_por FOREIGN KEY (registrado_por) REFERENCES super_admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    -- El nombre de la base entra al DSN de PDO: solo minúsculas, dígitos y guion bajo, con prefijo fijo.
    -- `adara` es la base del primer cliente (la que ya existía). El resto lleva el prefijo adara_.
    CONSTRAINT ck_clientes_db CHECK (nombre_db_fisica REGEXP '^(adara|adara_[a-z0-9_]{1,58})$' AND nombre_db_fisica <> 'adara_master'),
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
DELIMITER ;
