-- =====================================================================
-- ADARA · Migración 017: Catálogo de producción + descripción
--   prod_catalogo es el maestro de lo que se produce. Al crear un ítem
--   el servicio inserta (o vincula) la fila equivalente en `productos`
--   (catálogo maestro de Inventario).
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS prod_catalogo (
    id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    producto_id        INT UNSIGNED      NULL,
    nombre             VARCHAR(100)      NOT NULL,
    descripcion        TEXT              NULL,
    orden              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    precio_produccion  DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
    activo             TINYINT(1)        NOT NULL DEFAULT 1,
    creado_en          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en     DATETIME          NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prod_cat_nombre (nombre),
    UNIQUE KEY uq_prod_cat_producto (producto_id),
    KEY ix_prod_cat_orden (activo, orden, id),
    CONSTRAINT fk_prod_cat_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_prod_cat_nombre CHECK (CHAR_LENGTH(TRIM(nombre)) >= 2),
    CONSTRAINT ck_prod_cat_orden  CHECK (orden <= 9999),
    CONSTRAINT ck_prod_cat_precio CHECK (precio_produccion >= 0),
    CONSTRAINT ck_prod_cat_activo CHECK (activo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Productos de inventario que aún no están en el catálogo de producción.
INSERT INTO prod_catalogo (producto_id, nombre, orden, precio_produccion, activo)
SELECT p.id, p.nombre, 0, p.precio_usd, p.activo
FROM productos p
LEFT JOIN prod_catalogo c ON c.producto_id = p.id OR c.nombre = p.nombre
WHERE c.id IS NULL;

-- Nombres rotos de «Guayanés» (latin1 leído como utf8). No pisa un nombre ya correcto.
UPDATE productos p
   SET p.nombre = 'Queso Guayanés'
 WHERE p.nombre LIKE 'Queso Guayan%'
   AND p.nombre <> 'Queso Guayanés'
   AND NOT EXISTS (
        SELECT 1 FROM (SELECT id FROM productos WHERE nombre = 'Queso Guayanés') x
   );

UPDATE prod_catalogo c
   SET c.nombre = 'Queso Guayanés'
 WHERE c.nombre LIKE 'Queso Guayan%'
   AND c.nombre <> 'Queso Guayanés'
   AND NOT EXISTS (
        SELECT 1 FROM (SELECT id FROM prod_catalogo WHERE nombre = 'Queso Guayanés') x
   );
