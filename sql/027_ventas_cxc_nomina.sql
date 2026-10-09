-- =====================================================================
-- ADARA · Migración 027: ventas → CxC / nómina / descuento a productor
--   tipo_cliente + entidad (productor | empleado | cxc) + método de pago.
--   Crédito y mercancía a descuento generan la deuda en el módulo destino.
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS adara_mig_027_ventas;
DELIMITER //
CREATE PROCEDURE adara_mig_027_ventas()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'tipo_cliente'
    ) THEN
        ALTER TABLE ventas
            ADD COLUMN tipo_cliente ENUM('generico', 'frecuente', 'exclusivo') NOT NULL DEFAULT 'generico'
                AFTER cliente_id,
            ADD COLUMN entidad_tipo ENUM('productor', 'empleado', 'cxc') NULL AFTER tipo_cliente,
            ADD COLUMN entidad_id INT UNSIGNED NULL AFTER entidad_tipo,
            ADD COLUMN metodo_pago ENUM(
                'efectivo', 'pago_movil', 'transferencia', 'punto', 'credito', 'cortesia', 'mercancia_descuento'
            ) NOT NULL DEFAULT 'efectivo' AFTER moneda_pago,
            ADD KEY ix_ventas_entidad (tipo_cliente, entidad_tipo, entidad_id),
            ADD CONSTRAINT ck_ventas_entidad CHECK (
                (tipo_cliente IN ('generico', 'frecuente') AND entidad_tipo IS NULL AND entidad_id IS NULL)
                OR (tipo_cliente = 'exclusivo' AND entidad_tipo IS NOT NULL AND entidad_id IS NOT NULL)
            );
    END IF;
END //
DELIMITER ;

CALL adara_mig_027_ventas();
DROP PROCEDURE IF EXISTS adara_mig_027_ventas;

INSERT INTO clientes (nombre, documento, activo)
SELECT 'CONSUMIDOR FINAL', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM clientes WHERE nombre = 'CONSUMIDOR FINAL');
