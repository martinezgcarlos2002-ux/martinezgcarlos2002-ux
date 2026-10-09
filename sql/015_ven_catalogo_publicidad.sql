-- =====================================================================
-- ADARA · Migración 015: Catálogo y publicidad (precios, fotos, TV)
--   No crea productos: extiende `productos` (inventario/producción).
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE ven_catalogo_publicidad (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    producto_id      INT UNSIGNED  NOT NULL,
    precio_usd       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    foto_ruta        VARCHAR(180)  NULL,
    mostrar_pantalla TINYINT(1)    NOT NULL DEFAULT 1,
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ven_cat_producto (producto_id),
    CONSTRAINT fk_ven_cat_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_ven_cat_precio CHECK (precio_usd >= 0),
    CONSTRAINT ck_ven_cat_tv     CHECK (mostrar_pantalla IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ven_catalogo_tasa (
    id               TINYINT UNSIGNED NOT NULL,
    tasa_bs          DECIMAL(12,4)    NOT NULL,
    actualizado_en   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por  INT UNSIGNED     NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT ck_ven_cat_tasa_id    CHECK (id = 1),
    CONSTRAINT ck_ven_cat_tasa_valor CHECK (tasa_bs > 0),
    CONSTRAINT fk_ven_cat_tasa_user  FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
