-- =====================================================================
-- ADARA · Migración 016: Logística y transporte
--   Transportistas, rutas con productores, fletes diarios y cierres semanales.
--   log_fletes_diarios.pago_id marca el diario como procesado en un cierre.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE log_transportistas (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    documento       VARCHAR(20)   NULL,
    nombre          VARCHAR(120)  NOT NULL,
    telefono        VARCHAR(20)   NULL,
    tipo_vehiculo   ENUM('moto','carro','camioneta','camion','otro') NOT NULL DEFAULT 'otro',
    placa           VARCHAR(15)   NULL,
    limite_carga_kg DECIMAL(10,2) NOT NULL,
    estado          ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    registrado_por  INT UNSIGNED  NOT NULL,
    creado_en       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_log_tr_documento (documento),
    KEY ix_log_tr_nombre (nombre),
    CONSTRAINT fk_log_tr_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_log_tr_nombre  CHECK (CHAR_LENGTH(nombre) >= 2),
    CONSTRAINT ck_log_tr_limite  CHECK (limite_carga_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE log_rutas (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    transportista_id INT UNSIGNED  NOT NULL,
    nombre_zona      VARCHAR(80)   NOT NULL,
    precio_viaje     DECIMAL(10,2) NOT NULL,
    estado           ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    registrado_por   INT UNSIGNED  NOT NULL,
    creado_en        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_log_ruta_tr (transportista_id, estado),
    CONSTRAINT fk_log_ruta_tr      FOREIGN KEY (transportista_id) REFERENCES log_transportistas (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_log_ruta_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_log_ruta_nombre  CHECK (CHAR_LENGTH(nombre_zona) >= 2),
    CONSTRAINT ck_log_ruta_precio  CHECK (precio_viaje >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE log_ruta_productores (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ruta_id      INT UNSIGNED NOT NULL,
    productor_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_log_rp (ruta_id, productor_id),
    KEY ix_log_rp_productor (productor_id),
    CONSTRAINT fk_log_rp_ruta      FOREIGN KEY (ruta_id)      REFERENCES log_rutas (id)
        ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_log_rp_productor FOREIGN KEY (productor_id) REFERENCES productores (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE log_pagos_semanales (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    transportista_id INT UNSIGNED  NOT NULL,
    fecha_inicio     DATE          NOT NULL,
    fecha_fin        DATE          NOT NULL,
    total_litros     DECIMAL(12,2) NOT NULL,
    total_pagar      DECIMAL(12,2) NOT NULL,
    estado           ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
    registrado_por   INT UNSIGNED  NOT NULL,
    creado_en        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_log_pago_tr (transportista_id, fecha_inicio),
    CONSTRAINT fk_log_pago_tr      FOREIGN KEY (transportista_id) REFERENCES log_transportistas (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_log_pago_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_log_pago_rango   CHECK (fecha_fin >= fecha_inicio),
    CONSTRAINT ck_log_pago_montos  CHECK (total_litros >= 0 AND total_pagar >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE log_fletes_diarios (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    transportista_id    INT UNSIGNED  NOT NULL,
    ruta_id             INT UNSIGNED  NOT NULL,
    fecha               DATE          NOT NULL,
    litros_recolectados DECIMAL(10,2) NOT NULL,
    costo_flete         DECIMAL(10,2) NOT NULL,
    pago_id             INT UNSIGNED  NULL,
    registrado_por      INT UNSIGNED  NOT NULL,
    creado_en           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_log_flete_dia (transportista_id, ruta_id, fecha),
    KEY ix_log_flete_fecha (fecha),
    KEY ix_log_flete_pago (pago_id),
    CONSTRAINT fk_log_flete_tr   FOREIGN KEY (transportista_id) REFERENCES log_transportistas (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_log_flete_ruta FOREIGN KEY (ruta_id)          REFERENCES log_rutas (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_log_flete_pago FOREIGN KEY (pago_id)          REFERENCES log_pagos_semanales (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_log_flete_user FOREIGN KEY (registrado_por)   REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_log_flete_litros CHECK (litros_recolectados > 0),
    CONSTRAINT ck_log_flete_costo  CHECK (costo_flete >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
