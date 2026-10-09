-- =====================================================================
-- ADARA · Migración 003: inventario multi-almacén y planilla de producción diaria
-- Invariante: productos.stock = SUM(inventario_almacenes.stock) del producto.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

-- Un almacén dado de baja queda inactivo (no se borra): conserva el histórico de distribuciones y kardex.
CREATE TABLE almacenes (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(80)       NOT NULL,
    orden          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo         TINYINT(1)        NOT NULL DEFAULT 1,
    creado_en      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME          NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_almacenes_nombre (nombre),
    KEY ix_almacenes_orden (activo, orden, id),
    CONSTRAINT ck_almacenes_nombre CHECK (CHAR_LENGTH(TRIM(nombre)) >= 2),
    CONSTRAINT ck_almacenes_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE inventario_almacenes (
    producto_id    INT UNSIGNED  NOT NULL,
    almacen_id     INT UNSIGNED  NOT NULL,
    stock          DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    actualizado_en DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (producto_id, almacen_id),
    KEY ix_inv_alm_almacen (almacen_id),
    CONSTRAINT fk_inv_alm_producto FOREIGN KEY (producto_id) REFERENCES productos (id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_inv_alm_almacen  FOREIGN KEY (almacen_id)  REFERENCES almacenes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_inv_alm_stock    CHECK (stock >= 0)
) ENGINE=InnoDB;

-- Paso 1 registra la cantidad elaborada; paso 2 la distribuye. Pendiente = cantidad - distribuido, nunca negativo.
CREATE TABLE produccion_diaria (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    fecha           DATE          NOT NULL,
    producto_id     INT UNSIGNED  NOT NULL,
    cantidad        DECIMAL(12,3) NOT NULL,
    precio_unit_usd DECIMAL(12,2) NOT NULL,
    total_usd       DECIMAL(12,2) AS (ROUND(cantidad * precio_unit_usd, 2)) STORED,
    distribuido     DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    pendiente       DECIMAL(12,3) AS (cantidad - distribuido) STORED,
    registrado_por  INT UNSIGNED  NOT NULL,
    creado_en       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prod_diaria_fecha_producto (fecha, producto_id),
    KEY ix_prod_diaria_producto (producto_id),
    CONSTRAINT fk_prod_diaria_producto FOREIGN KEY (producto_id)    REFERENCES productos (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_prod_diaria_usuario  FOREIGN KEY (registrado_por) REFERENCES usuarios (id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_prod_diaria_cantidad CHECK (cantidad > 0 AND cantidad <= 100000),
    CONSTRAINT ck_prod_diaria_precio   CHECK (precio_unit_usd >= 0),
    CONSTRAINT ck_prod_diaria_dist     CHECK (distribuido >= 0 AND distribuido <= cantidad)
) ENGINE=InnoDB;

CREATE TABLE produccion_distribuciones (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    produccion_diaria_id INT UNSIGNED    NOT NULL,
    almacen_id           INT UNSIGNED    NOT NULL,
    cantidad             DECIMAL(12,3)   NOT NULL,
    usuario_id           INT UNSIGNED    NOT NULL,
    creado_en            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_dist_produccion (produccion_diaria_id),
    KEY ix_dist_almacen (almacen_id, creado_en),
    CONSTRAINT fk_dist_produccion FOREIGN KEY (produccion_diaria_id) REFERENCES produccion_diaria (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_dist_almacen    FOREIGN KEY (almacen_id)           REFERENCES almacenes (id)         ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_dist_usuario    FOREIGN KEY (usuario_id)           REFERENCES usuarios (id)          ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_dist_cantidad   CHECK (cantidad > 0)
) ENGINE=InnoDB;

ALTER TABLE inventario_movimientos
    ADD COLUMN almacen_id           INT UNSIGNED NULL AFTER producto_id,
    ADD COLUMN produccion_diaria_id INT UNSIGNED NULL AFTER produccion_id,
    ADD KEY ix_kardex_almacen (almacen_id, creado_en),
    ADD CONSTRAINT fk_kardex_almacen        FOREIGN KEY (almacen_id)           REFERENCES almacenes (id)         ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_kardex_prod_diaria    FOREIGN KEY (produccion_diaria_id) REFERENCES produccion_diaria (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

DELIMITER $$

CREATE TRIGGER trg_distribuciones_no_update BEFORE UPDATE ON produccion_distribuciones FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las distribuciones son de solo insercion';
END$$

CREATE TRIGGER trg_distribuciones_no_delete BEFORE DELETE ON produccion_distribuciones FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las distribuciones son de solo insercion';
END$$

DELIMITER ;

INSERT INTO almacenes (nombre, orden) VALUES
    ('Cava 1', 10),
    ('Cava 2', 20),
    ('Cava 3', 30),
    ('Cava 4', 40);

-- El stock existente, que aún no tiene almacén, entra a la primera cava para respetar el invariante.
INSERT INTO inventario_almacenes (producto_id, almacen_id, stock)
SELECT p.id, (SELECT id FROM almacenes ORDER BY orden, id LIMIT 1), p.stock
FROM productos p
WHERE p.stock > 0;
