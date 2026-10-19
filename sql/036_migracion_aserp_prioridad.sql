-- =====================================================================
-- ADARA · Completar migración desde aserp_db
--
-- ADARA ya tenía los mismos id de clientes, productores y productos.
-- Este script no vuelve a insertar esas fichas. Solo agrega las filas
-- hijas que aún no están, conservando el id de origen para que la
-- factura, el detalle y el pago sigan apuntando a la misma ficha.
--
-- No copia usuarios, sesiones, auditoría ni contraseñas.
-- No crea tablas del esquema viejo dentro de adara.
-- productos_stock cuyo producto_id no existe en productos no se copia:
-- no hay cava válida para un queso que el maestro no tiene.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

SET @uid := (SELECT MIN(id) FROM adara.usuarios);

START TRANSACTION;

UPDATE adara.productores d
INNER JOIN aserp_db.productores o ON o.id = d.id
SET d.nombre = LEFT(TRIM(o.nombre), 120)
WHERE CHAR_LENGTH(TRIM(o.nombre)) BETWEEN 2 AND 120
  AND d.nombre <> LEFT(TRIM(o.nombre), 120) COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (
      SELECT 1 FROM adara.productores x
      WHERE x.id <> d.id
        AND x.nombre = LEFT(TRIM(o.nombre), 120) COLLATE utf8mb4_unicode_ci
  );

INSERT INTO adara.cxc_facturas (
    id, cliente_id, numero_factura, es_aleatorio, domicilio, observaciones,
    fecha_emision, fecha_vencimiento, monto_total, total_pagado, total_devuelto,
    saldo_pendiente, estado, registrado_por, creado_en
)
SELECT
    f.id,
    f.cliente_id,
    CASE
        WHEN LEFT(TRIM(f.numero), 20) REGEXP '^[A-Za-z0-9-]{1,20}$'
         AND LEFT(TRIM(f.numero), 20) <> ''
         AND NOT EXISTS (
            SELECT 1 FROM adara.cxc_facturas x
            WHERE x.numero_factura = LEFT(TRIM(f.numero), 20) COLLATE utf8mb4_unicode_ci
         )
        THEN LEFT(TRIM(f.numero), 20)
        ELSE CONCAT('AS', f.id)
    END,
    0,
    NULLIF(LEFT(TRIM(f.descripcion), 160), ''),
    NULLIF(LEFT(TRIM(COALESCE(f.observaciones, f.descripcion)), 240), ''),
    f.fecha_emision,
    IF(f.fecha_vencimiento >= f.fecha_emision, f.fecha_vencimiento, f.fecha_emision),
    CASE WHEN f.anulada <> 0 THEN f.monto_total ELSE base.monto END,
    base.pagado,
    base.devuelto,
    CASE WHEN f.anulada <> 0 THEN 0.00 ELSE base.monto - base.pagado - base.devuelto END,
    CASE
        WHEN f.anulada <> 0 THEN 'anulada'
        WHEN base.monto - base.pagado - base.devuelto <= 0 THEN 'pagada'
        WHEN CURDATE() > IF(f.fecha_vencimiento >= f.fecha_emision, f.fecha_vencimiento, f.fecha_emision) THEN 'vencida'
        WHEN DATEDIFF(IF(f.fecha_vencimiento >= f.fecha_emision, f.fecha_vencimiento, f.fecha_emision), CURDATE()) <= 3 THEN 'por_vencer'
        ELSE 'vigente'
    END,
    @uid,
    f.creado_en
FROM aserp_db.cf_facturas f
INNER JOIN adara.cxc_clientes c ON c.id = f.cliente_id
INNER JOIN (
    SELECT
        f0.id,
        ROUND(COALESCE((
            SELECT SUM(p.monto) FROM aserp_db.cf_pagos p
            WHERE p.factura_id = f0.id AND p.monto > 0
        ), 0) + COALESCE((
            SELECT SUM(s.monto) FROM aserp_db.cf_saldos_aplicados s
            WHERE s.factura_destino_id = f0.id AND s.monto > 0
        ), 0), 2) AS pagado,
        ROUND(COALESCE((
            SELECT SUM(d.monto) FROM aserp_db.cf_devoluciones d
            WHERE d.aplicada_factura_id = f0.id AND d.monto > 0
        ), 0), 2) AS devuelto,
        GREATEST(
            f0.monto_total,
            ROUND(COALESCE((
                SELECT SUM(p.monto) FROM aserp_db.cf_pagos p
                WHERE p.factura_id = f0.id AND p.monto > 0
            ), 0) + COALESCE((
                SELECT SUM(s.monto) FROM aserp_db.cf_saldos_aplicados s
                WHERE s.factura_destino_id = f0.id AND s.monto > 0
            ), 0) + COALESCE((
                SELECT SUM(d.monto) FROM aserp_db.cf_devoluciones d
                WHERE d.aplicada_factura_id = f0.id AND d.monto > 0
            ), 0), 2)
        ) AS monto
    FROM aserp_db.cf_facturas f0
) base ON base.id = f.id
WHERE f.monto_total > 0
  AND @uid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM adara.cxc_facturas d WHERE d.id = f.id);

INSERT INTO adara.cxc_factura_detalles (
    id, factura_id, renglon, cantidad, precio_unitario, subtotal, descripcion
)
SELECT
    i.id,
    i.factura_id,
    ROW_NUMBER() OVER (PARTITION BY i.factura_id ORDER BY i.id),
    ROUND(i.cantidad, 2),
    ROUND(i.precio, 2),
    ROUND(ROUND(i.cantidad, 2) * ROUND(i.precio, 2), 2),
    NULLIF(LEFT(TRIM(i.descripcion), 120), '')
FROM aserp_db.cf_factura_items i
INNER JOIN adara.cxc_facturas f ON f.id = i.factura_id
WHERE ROUND(i.cantidad, 2) > 0
  AND ROUND(i.precio, 2) > 0
  AND ROUND(ROUND(i.cantidad, 2) * ROUND(i.precio, 2), 2) > 0
  AND NOT EXISTS (SELECT 1 FROM adara.cxc_factura_detalles d WHERE d.id = i.id)
  AND NOT EXISTS (
      SELECT 1 FROM adara.cxc_factura_detalles d WHERE d.factura_id = i.factura_id
  );

INSERT INTO adara.cxc_pagos (
    id, factura_id, cliente_id, monto, fecha, metodo, referencia, notas, registrado_por, creado_en
)
SELECT
    p.id,
    p.factura_id,
    f.cliente_id,
    ROUND(p.monto, 2),
    p.fecha,
    CASE LOWER(TRIM(COALESCE(p.metodo, '')))
        WHEN 'efectivo' THEN 'efectivo'
        WHEN 'punto' THEN 'punto'
        WHEN 'transferencia' THEN 'transferencia'
        WHEN 'pago móvil' THEN 'pago_movil'
        WHEN 'pago movil' THEN 'pago_movil'
        WHEN 'pago_movil' THEN 'pago_movil'
        ELSE 'otro'
    END,
    NULLIF(LEFT(TRIM(p.referencia), 60), ''),
    NULLIF(LEFT(TRIM(p.notas), 160), ''),
    @uid,
    p.creado_en
FROM aserp_db.cf_pagos p
INNER JOIN adara.cxc_facturas f ON f.id = p.factura_id
WHERE p.monto > 0
  AND @uid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM adara.cxc_pagos d WHERE d.id = p.id);

INSERT INTO adara.cxc_devoluciones (
    id, cliente_id, factura_origen_id, factura_aplicada_id, monto, fecha, motivo,
    destino, estado, registrado_por, creado_en
)
SELECT
    o.id,
    o.cliente_id,
    fo.id,
    CASE
        WHEN o.aplicada_factura_id = o.factura_origen_id THEN fo.id
        ELSE fa.id
    END,
    ROUND(o.monto, 2),
    o.fecha,
    NULLIF(LEFT(TRIM(o.motivo), 160), ''),
    CASE
        WHEN fo.id IS NOT NULL AND o.aplicada_factura_id = o.factura_origen_id THEN 'origen'
        WHEN fa.id IS NOT NULL THEN 'otra_factura'
        ELSE 'credito_pendiente'
    END,
    CASE
        WHEN fo.id IS NOT NULL AND o.aplicada_factura_id = o.factura_origen_id THEN 'aplicada'
        WHEN fa.id IS NOT NULL THEN 'aplicada'
        ELSE 'pendiente'
    END,
    @uid,
    o.creado_en
FROM aserp_db.cf_devoluciones o
INNER JOIN adara.cxc_clientes c ON c.id = o.cliente_id
LEFT JOIN adara.cxc_facturas fo ON fo.id = o.factura_origen_id
LEFT JOIN adara.cxc_facturas fa ON fa.id = o.aplicada_factura_id
WHERE o.monto > 0
  AND @uid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM adara.cxc_devoluciones d WHERE d.id = o.id)
  AND (
      (fo.id IS NOT NULL AND o.aplicada_factura_id = o.factura_origen_id)
      OR fa.id IS NOT NULL
      OR o.aplicada_factura_id IS NULL
  );

INSERT INTO adara.cxc_devolucion_detalles (
    id, devolucion_id, renglon, cantidad, precio_unitario, subtotal, descripcion
)
SELECT
    i.id,
    i.devolucion_id,
    ROW_NUMBER() OVER (PARTITION BY i.devolucion_id ORDER BY i.id),
    ROUND(i.cantidad, 2),
    ROUND(i.precio, 2),
    ROUND(ROUND(i.cantidad, 2) * ROUND(i.precio, 2), 2),
    NULLIF(LEFT(TRIM(i.descripcion), 120), '')
FROM aserp_db.cf_devolucion_items i
INNER JOIN adara.cxc_devoluciones d ON d.id = i.devolucion_id
WHERE ROUND(i.cantidad, 2) > 0
  AND ROUND(i.precio, 2) > 0
  AND ROUND(ROUND(i.cantidad, 2) * ROUND(i.precio, 2), 2) > 0
  AND NOT EXISTS (SELECT 1 FROM adara.cxc_devolucion_detalles x WHERE x.id = i.id);

INSERT INTO adara.productores_entregas (
    productor_id, fecha, litros, precio_litro_usd, traslado_usd, cuajada_kg, observaciones, registrado_por, creado_en
)
SELECT
    e.productor_id,
    e.fecha,
    ROUND(SUM(e.litros), 3),
    CASE
        WHEN SUM(e.litros * e.precio_litro_usd) / SUM(e.litros) > 0
        THEN ROUND(SUM(e.litros * e.precio_litro_usd) / SUM(e.litros), 4)
        ELSE 0.0001
    END,
    ROUND(GREATEST(SUM(e.gasto_traslado_usd), 0), 2),
    ROUND(GREATEST(SUM(e.cuajada_kg), 0), 3),
    NULLIF(LEFT(GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.observaciones), '') SEPARATOR ' | '), 255), ''),
    @uid,
    MIN(e.creado_en)
FROM aserp_db.productores_entregas e
INNER JOIN adara.productores p ON p.id = e.productor_id
WHERE @uid IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM adara.productores_entregas d
      WHERE d.productor_id = e.productor_id AND d.fecha = e.fecha
  )
GROUP BY e.productor_id, e.fecha
HAVING SUM(e.litros) > 0 AND SUM(e.litros) <= 100000;

INSERT INTO adara.inventario_almacenes (producto_id, almacen_id, stock)
SELECT s.producto_id, a.id, ROUND(SUM(s.stock), 3)
FROM aserp_db.productos_stock s
INNER JOIN adara.productos p ON p.id = s.producto_id
INNER JOIN adara.almacenes a ON a.orden = s.almacen_id * 10 AND a.orden IN (10, 20, 30, 40)
WHERE s.stock > 0
  AND NOT EXISTS (
      SELECT 1 FROM adara.inventario_almacenes ia
      WHERE ia.producto_id = s.producto_id AND ia.almacen_id = a.id
  )
GROUP BY s.producto_id, a.id;

UPDATE adara.productos p
INNER JOIN (
    SELECT producto_id, ROUND(SUM(stock), 3) AS stock
    FROM adara.inventario_almacenes
    GROUP BY producto_id
) s ON s.producto_id = p.id
SET p.stock = s.stock
WHERE p.stock <> s.stock;

COMMIT;
