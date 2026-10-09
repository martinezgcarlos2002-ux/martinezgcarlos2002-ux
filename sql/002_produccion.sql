-- =====================================================================
-- ADARA · Migración 002: fórmulas de rendimiento y costo de producción
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

-- base 'leche':   kg esperados = litros / factor
-- base 'cuajada': además, la cuajada absorbe suero: cuajada_ref_kg rinde entre masa_min_kg y masa_max_kg de masa
CREATE TABLE formulas_produccion (
    producto_id     INT UNSIGNED  NOT NULL,
    base            ENUM('leche','cuajada') NOT NULL DEFAULT 'leche',
    factor_l_kg_ref DECIMAL(8,4)  NULL,
    cuajada_ref_kg  DECIMAL(8,3)  NULL,
    masa_min_kg     DECIMAL(8,3)  NULL,
    masa_max_kg     DECIMAL(8,3)  NULL,
    notas           VARCHAR(255)  NULL,
    actualizado_en  DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (producto_id),
    CONSTRAINT fk_formulas_producto FOREIGN KEY (producto_id) REFERENCES productos (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_formulas_factor   CHECK (factor_l_kg_ref IS NULL OR (factor_l_kg_ref > 0 AND factor_l_kg_ref <= 50)),
    CONSTRAINT ck_formulas_cuajada  CHECK (
        (base = 'leche' AND cuajada_ref_kg IS NULL AND masa_min_kg IS NULL AND masa_max_kg IS NULL)
        OR (base = 'cuajada' AND cuajada_ref_kg > 0 AND masa_min_kg > 0 AND masa_max_kg >= masa_min_kg)
    )
) ENGINE=InnoDB;

-- Snapshot de la fórmula usada: si la fórmula cambia después, el histórico conserva su cálculo.
ALTER TABLE producciones
    ADD COLUMN cuajada_kg            DECIMAL(12,3) NULL AFTER kg_esperados,
    ADD COLUMN rend_cuajada_ref_kg   DECIMAL(8,3)  NULL AFTER cuajada_kg,
    ADD COLUMN rend_masa_min_kg      DECIMAL(8,3)  NULL AFTER rend_cuajada_ref_kg,
    ADD COLUMN rend_masa_max_kg      DECIMAL(8,3)  NULL AFTER rend_masa_min_kg,
    ADD COLUMN masa_min_kg           DECIMAL(12,3) AS (ROUND(cuajada_kg * rend_masa_min_kg / rend_cuajada_ref_kg, 3)) STORED AFTER rend_masa_max_kg,
    ADD COLUMN masa_max_kg           DECIMAL(12,3) AS (ROUND(cuajada_kg * rend_masa_max_kg / rend_cuajada_ref_kg, 3)) STORED AFTER masa_min_kg,
    ADD COLUMN precio_litro_prom_usd DECIMAL(12,4) NULL AFTER kg_reales,
    ADD COLUMN costo_leche_usd       DECIMAL(12,2) AS (ROUND(litros_leche * precio_litro_prom_usd, 2)) STORED AFTER precio_litro_prom_usd,
    ADD CONSTRAINT ck_producciones_cuajada CHECK (
        (cuajada_kg IS NULL AND rend_cuajada_ref_kg IS NULL AND rend_masa_min_kg IS NULL AND rend_masa_max_kg IS NULL)
        OR (cuajada_kg > 0 AND rend_cuajada_ref_kg > 0 AND rend_masa_min_kg > 0 AND rend_masa_max_kg >= rend_masa_min_kg)
    ),
    ADD CONSTRAINT ck_producciones_precio CHECK (precio_litro_prom_usd IS NULL OR precio_litro_prom_usd > 0);

-- Presentaciones de queso. El precio de venta se define en el módulo de Inventario.
INSERT INTO productos (codigo, nombre, unidad, precio_usd) VALUES
    ('QS-AREPERO',  'Queso Arepero',  'kg', 0.00),
    ('QS-BLANCO',   'Queso Blanco',   'kg', 0.00),
    ('QS-GUAYANES', 'Queso Guayanés', 'kg', 0.00),
    ('QS-MANO',     'Queso de Mano',  'kg', 0.00),
    ('QS-TELITA',   'Queso Telita',   'kg', 0.00);

INSERT INTO formulas_produccion (producto_id, base, cuajada_ref_kg, masa_min_kg, masa_max_kg, notas)
SELECT id, 'cuajada', 2.500, 6.500, 7.000, '2,5 kg de cuajada absorben suero y rinden 6,5 a 7 kg de masa'
FROM productos WHERE codigo = 'QS-AREPERO';

INSERT INTO formulas_produccion (producto_id, base)
SELECT id, 'leche' FROM productos WHERE codigo IN ('QS-BLANCO', 'QS-GUAYANES', 'QS-MANO', 'QS-TELITA');
