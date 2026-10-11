-- =====================================================================
-- ADARA · Migración 028: clientes POS, semana de descuento y cruce financiero
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS ventas_clientes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(120) NOT NULL,
    documento      VARCHAR(14)  NULL,
    tipo           ENUM('frecuente','exclusivo') NOT NULL DEFAULT 'frecuente',
    cxc_cliente_id INT UNSIGNED NULL,
    creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_vcli_tipo (tipo, nombre),
    UNIQUE KEY uq_vcli_documento (documento),
    CONSTRAINT fk_vcli_cxc FOREIGN KEY (cxc_cliente_id) REFERENCES cxc_clientes (id)
        ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT ck_vcli_nombre CHECK (CHAR_LENGTH(TRIM(nombre)) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
