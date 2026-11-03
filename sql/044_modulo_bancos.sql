-- =====================================================================
-- ADARA · Migración 044: Conciliación bancaria
--   El día no se digita movimiento por movimiento. Se sube el Excel del
--   cash manager y, si la caja cuadra, se inyecta en una sola transacción.
--
--   Por cuenta y fecha:
--     saldo_final = saldo_inicio + ingresos − egresos − comisiones
--   Solo entra un cierre con estado 'cuadrado'. La pareja cuenta+fecha
--   no se repite: un día ya cerrado no se vuelve a cargar.
--
--   saldo_actual_bs es el saldo_final del cierre más reciente de la cuenta.
--   Los movimientos quedan ligados a ese cierre.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE bancos_cuentas (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alias_cuenta     VARCHAR(80)  NOT NULL,
    saldo_actual_bs  DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    activa           TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bancos_alias (alias_cuenta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bancos_cierres_diarios (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id         INT UNSIGNED NOT NULL,
    fecha             DATE         NOT NULL,
    saldo_inicio      DECIMAL(18,2) NOT NULL,
    total_ingresos    DECIMAL(18,2) NOT NULL,
    total_egresos     DECIMAL(18,2) NOT NULL,
    total_comisiones  DECIMAL(18,2) NOT NULL,
    saldo_final       DECIMAL(18,2) NOT NULL,
    estado            ENUM('cuadrado') NOT NULL DEFAULT 'cuadrado',
    archivo           VARCHAR(180) NULL,
    registrado_por    INT UNSIGNED NOT NULL,
    creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_banco_cierre (cuenta_id, fecha),
    KEY ix_banco_cierre_fecha (fecha),
    CONSTRAINT fk_banco_cierre_cuenta FOREIGN KEY (cuenta_id) REFERENCES bancos_cuentas (id),
    CONSTRAINT fk_banco_cierre_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_banco_cierre_formula CHECK (
        saldo_final = saldo_inicio + total_ingresos - total_egresos - total_comisiones
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bancos_movimientos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id       INT UNSIGNED NOT NULL,
    cierre_id       INT UNSIGNED NOT NULL,
    fecha           DATE         NOT NULL,
    tipo            ENUM('ingreso','egreso') NOT NULL,
    categoria       VARCHAR(80)  NOT NULL,
    descripcion     VARCHAR(255) NOT NULL DEFAULT '',
    beneficiario    VARCHAR(120) NULL,
    referencia      VARCHAR(40)  NULL,
    monto_bs        DECIMAL(18,2) NOT NULL,
    comision_bs     DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    monto_usd       DECIMAL(18,4) NULL,
    tasa_usd_dia    DECIMAL(18,6) NOT NULL,
    hoja            VARCHAR(40)  NULL,
    fila            INT UNSIGNED NULL,
    registrado_por  INT UNSIGNED NOT NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_banco_mov_cuenta_fecha (cuenta_id, fecha),
    KEY ix_banco_mov_cierre (cierre_id),
    CONSTRAINT fk_banco_mov_cuenta FOREIGN KEY (cuenta_id) REFERENCES bancos_cuentas (id),
    CONSTRAINT fk_banco_mov_cierre FOREIGN KEY (cierre_id) REFERENCES bancos_cierres_diarios (id),
    CONSTRAINT fk_banco_mov_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_banco_mov_monto CHECK (monto_bs > 0),
    CONSTRAINT ck_banco_mov_comision CHECK (comision_bs >= 0),
    CONSTRAINT ck_banco_mov_tasa CHECK (tasa_usd_dia > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
