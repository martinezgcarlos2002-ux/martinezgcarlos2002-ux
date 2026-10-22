-- Inyección del delta ASERP → ADARA (respaldo 2026-10-15).
-- Solo INSERT. No hay UPDATE, REPLACE, DELETE ni TRUNCATE.
-- Las facturas ya guardadas, incluidos los id 15 y 96, no se seleccionan.

START TRANSACTION;

INSERT INTO adara.cxc_facturas (
    id, cliente_id, numero_factura, es_aleatorio, domicilio, observaciones,
    fecha_emision, fecha_vencimiento, monto_total, total_pagado, total_devuelto,
    saldo_pendiente, estado, registrado_por, creado_en
)
SELECT
    f.id,
    f.cliente_id,
    LEFT(TRIM(f.numero), 20),
    0,
    NULLIF(LEFT(TRIM(f.descripcion), 160), ''),
    NULLIF(LEFT(TRIM(f.observaciones), 240), ''),
    f.fecha_emision,
    f.fecha_vencimiento,
    base.monto,
    base.pagado,
    base.devuelto,
    base.monto - base.pagado - base.devuelto,
    CASE
        WHEN base.monto - base.pagado - base.devuelto <= 0 THEN 'pagada'
        WHEN CURDATE() > f.fecha_vencimiento THEN 'vencida'
        WHEN DATEDIFF(f.fecha_vencimiento, CURDATE()) <= 3 THEN 'por_vencer'
        ELSE 'vigente'
    END,
    (SELECT MIN(id) FROM adara.usuarios),
    f.creado_en
FROM aserp_db.cf_facturas f
INNER JOIN adara.cxc_clientes c ON c.id = f.cliente_id
INNER JOIN (
    SELECT
        f0.id,
        ROUND(COALESCE((
            SELECT SUM(p.monto)
            FROM aserp_db.cf_pagos p
            WHERE p.factura_id = f0.id AND p.monto > 0
        ), 0), 2) AS pagado,
        ROUND(COALESCE((
            SELECT SUM(d.monto)
            FROM aserp_db.cf_devoluciones d
            WHERE d.aplicada_factura_id = f0.id AND d.monto > 0
        ), 0), 2) AS devuelto,
        GREATEST(
            f0.monto_total,
            ROUND(COALESCE((
                SELECT SUM(p.monto)
                FROM aserp_db.cf_pagos p
                WHERE p.factura_id = f0.id AND p.monto > 0
            ), 0), 2) + ROUND(COALESCE((
                SELECT SUM(d.monto)
                FROM aserp_db.cf_devoluciones d
                WHERE d.aplicada_factura_id = f0.id AND d.monto > 0
            ), 0), 2)
        ) AS monto
    FROM aserp_db.cf_facturas f0
    WHERE f0.id >= 315
      AND f0.monto_total > 0
      AND f0.fecha_vencimiento >= f0.fecha_emision
      AND LEFT(TRIM(f0.numero), 20) REGEXP '^[A-Za-z0-9-]{1,20}$'
) base ON base.id = f.id
WHERE f.id >= 315
  AND NOT EXISTS (SELECT 1 FROM adara.cxc_facturas ya WHERE ya.id = f.id);

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
INNER JOIN adara.cxc_facturas f ON f.id = i.factura_id AND f.id >= 315
WHERE i.factura_id >= 315
  AND ROUND(i.cantidad, 2) > 0
  AND ROUND(i.precio, 2) > 0
  AND ROUND(ROUND(i.cantidad, 2) * ROUND(i.precio, 2), 2) > 0
  AND NOT EXISTS (SELECT 1 FROM adara.cxc_factura_detalles ya WHERE ya.id = i.id);

INSERT INTO adara.cxc_pagos (
    id, factura_id, cliente_id, monto, fecha, metodo, referencia, notas, estado, registrado_por, creado_en
)
SELECT
    p.id,
    p.factura_id,
    f.cliente_id,
    ROUND(p.monto, 2),
    p.fecha,
    CASE LOWER(TRIM(p.metodo))
        WHEN 'efectivo' THEN 'efectivo'
        WHEN 'punto' THEN 'punto'
        WHEN 'transferencia' THEN 'transferencia'
        WHEN 'pago móvil' THEN 'pago_movil'
        WHEN 'pago movil' THEN 'pago_movil'
        WHEN 'pago_movil' THEN 'pago_movil'
        WHEN 'saldo_favor' THEN 'saldo_favor'
        WHEN 'saldo a favor' THEN 'saldo_favor'
        ELSE 'otro'
    END,
    NULLIF(LEFT(TRIM(p.referencia), 60), ''),
    NULLIF(LEFT(TRIM(p.notas), 160), ''),
    'aplicado',
    (SELECT MIN(id) FROM adara.usuarios),
    p.creado_en
FROM aserp_db.cf_pagos p
LEFT JOIN adara.cxc_pagos ya ON ya.id = p.id
INNER JOIN adara.cxc_facturas f ON f.id = p.factura_id
WHERE ya.id IS NULL
  AND p.monto > 0;

INSERT INTO adara.cxc_devoluciones (
    id, cliente_id, factura_origen_id, factura_aplicada_id, monto, fecha, motivo,
    destino, estado, registrado_por, creado_en
)
SELECT
    o.id,
    o.cliente_id,
    o.factura_origen_id,
    o.factura_origen_id,
    ROUND(o.monto, 2),
    o.fecha,
    NULLIF(LEFT(TRIM(o.motivo), 160), ''),
    'origen',
    'aplicada',
    (SELECT MIN(id) FROM adara.usuarios),
    o.creado_en
FROM aserp_db.cf_devoluciones o
INNER JOIN adara.cxc_clientes c ON c.id = o.cliente_id
INNER JOIN adara.cxc_facturas fo
        ON fo.id = o.factura_origen_id
       AND fo.id = o.aplicada_factura_id
WHERE o.id IN (76, 77, 78, 79)
  AND o.monto > 0
  AND NOT EXISTS (SELECT 1 FROM adara.cxc_devoluciones ya WHERE ya.id = o.id);

INSERT INTO adara.productores_entregas (
    productor_id, fecha, litros, precio_litro_usd, traslado_usd, cuajada_kg,
    observaciones, registrado_por, creado_en
)
SELECT
    e.productor_id,
    e.fecha,
    ROUND(SUM(e.litros), 3),
    ROUND(SUM(e.litros * e.precio_litro_usd) / SUM(e.litros), 4),
    ROUND(GREATEST(SUM(e.gasto_traslado_usd), 0), 2),
    ROUND(GREATEST(SUM(e.cuajada_kg), 0), 3),
    NULLIF(LEFT(GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.observaciones), '') SEPARATOR ' | '), 255), ''),
    (SELECT MIN(id) FROM adara.usuarios),
    MIN(e.creado_en)
FROM aserp_db.productores_entregas e
INNER JOIN adara.productores pr ON pr.id = e.productor_id
WHERE NOT EXISTS (
    SELECT 1
    FROM adara.productores_entregas ya
    WHERE ya.productor_id = e.productor_id
      AND ya.fecha = e.fecha
)
GROUP BY e.productor_id, e.fecha
HAVING SUM(e.litros) > 0
   AND SUM(e.litros) <= 100000;

COMMIT;
