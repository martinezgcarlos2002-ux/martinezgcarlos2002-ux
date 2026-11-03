-- =====================================================================
-- ADARA · Migración 045: tarifario y catálogo de bancos
--   La comisión no se digita: Excel la calcula (Pago Móvil 1,5 %,
--   transferencia a otros bancos 0,30 %, mismo banco 0) y ADARA guarda
--   el valor ya resuelto junto con el método y el banco de la operación.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE bancos_catalogo (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_banco  VARCHAR(80)  NOT NULL,
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bancos_catalogo_nombre (nombre_banco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bancos_catalogo (nombre_banco) VALUES
    ('Banco de Venezuela'),
    ('Banesco'),
    ('Banco Provincial'),
    ('BNC'),
    ('Bancamiga'),
    ('Banco del Tesoro'),
    ('Mercantil'),
    ('Bancaribe'),
    ('Banco Exterior'),
    ('Banco Activo'),
    ('Banco Plaza'),
    ('Banco Caroní'),
    ('Banplus'),
    ('100% Banco'),
    ('Banco Venezolano de Crédito')
ON DUPLICATE KEY UPDATE nombre_banco = VALUES(nombre_banco);

ALTER TABLE bancos_movimientos
    ADD COLUMN metodo_pago VARCHAR(40) NULL AFTER categoria,
    ADD COLUMN banco_origen_destino VARCHAR(80) NULL AFTER metodo_pago;
