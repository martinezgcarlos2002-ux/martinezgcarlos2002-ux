-- =====================================================================
-- ADARA · Migración 058: abono global (monto ingresado y tasa BCV)
--
-- Un abono a varias facturas crea una fila en cxc_pagos por cada fracción.
-- Esas filas ya comparten `lote` / `lote_hash` y, desde la 052, copian
-- monto_global_origen. La tasa del día no se pedía en el formulario, así que
-- tasa_cambio_dia quedó en NULL.
--
-- pagos_globales guarda UNA vez el monto que digitó el usuario y la tasa BCV
-- de ese momento. Las fracciones siguen en cxc_pagos y se reconocen por `lote`.
-- No se hace UPDATE sobre cxc_pagos: el trigger trg_cxc_pagos_no_update solo
-- permite anular.
--
-- Ejecutar en la base física de CADA empresa.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS pagos_globales (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cliente_id             INT UNSIGNED    NOT NULL,
    lote                   CHAR(16)        NOT NULL,
    lote_hash              VARCHAR(20)     NOT NULL,
    monto_total_ingresado  DECIMAL(12,2)   NOT NULL,
    tasa_bcv_aplicada      DECIMAL(12,4)   NULL COMMENT 'Bs por 1 USD en el momento del abono',
    monto_bs               DECIMAL(14,2)   NULL COMMENT 'Bolívares del monto total, si se indicó tasa',
    fecha                  DATE            NOT NULL,
    metodo                 ENUM('efectivo','punto','transferencia','pago_movil','saldo_favor','otro') NOT NULL,
    referencia             VARCHAR(60)     NULL,
    notas                  VARCHAR(160)    NULL,
    registrado_por         INT UNSIGNED    NOT NULL,
    creado_en              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pagos_globales_lote (lote),
    UNIQUE KEY uq_pagos_globales_hash (lote_hash),
    KEY ix_pagos_globales_cliente (cliente_id, fecha),
    CONSTRAINT fk_pagos_globales_cliente FOREIGN KEY (cliente_id) REFERENCES cxc_clientes (id),
    CONSTRAINT fk_pagos_globales_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_pagos_globales_monto CHECK (monto_total_ingresado > 0),
    CONSTRAINT ck_pagos_globales_tasa CHECK (tasa_bcv_aplicada IS NULL OR tasa_bcv_aplicada > 0),
    CONSTRAINT ck_pagos_globales_bs CHECK (monto_bs IS NULL OR monto_bs > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La columna de la fracción admite la misma precisión que tasa_cambio (4 decimales).
ALTER TABLE cxc_pagos
    MODIFY COLUMN tasa_cambio_dia DECIMAL(12,4) NULL COMMENT 'Tasa BCV (Bs por 1 USD) del abono que originó esta fracción';

-- Historial ya distribuido: una fila global por lote.
-- Si la fracción ya trae monto_global_origen, se usa ese (incluye el excedente
-- que pasó a saldo a favor). Si no, se reconstruye con la suma de fracciones
-- más el abono_excedente del mismo lote. La tasa sale de tasa_cambio_dia o,
-- en datos viejos, de la tasa guardada en la fracción.
INSERT IGNORE INTO pagos_globales (
    cliente_id, lote, lote_hash, monto_total_ingresado, tasa_bcv_aplicada,
    fecha, metodo, referencia, notas, registrado_por, creado_en
)
SELECT
    MIN(p.cliente_id),
    p.lote,
    COALESCE(MIN(p.lote_hash), CONCAT('LT-', MIN(p.lote))),
    COALESCE(MAX(p.monto_global_origen), ROUND(SUM(p.monto) + COALESCE(MAX(e.excedente), 0), 2)),
    COALESCE(MAX(p.tasa_cambio_dia), MAX(p.tasa_cambio)),
    MIN(p.fecha),
    MIN(p.metodo),
    MAX(p.referencia),
    MAX(p.notas),
    MIN(p.registrado_por),
    MIN(p.creado_en)
FROM cxc_pagos p
LEFT JOIN (
    SELECT lote, SUM(monto) AS excedente
    FROM cxc_saldo_movimientos
    WHERE tipo = 'abono_excedente' AND lote IS NOT NULL
    GROUP BY lote
) e ON e.lote = p.lote
WHERE p.lote IS NOT NULL AND p.lote <> ''
GROUP BY p.lote
HAVING COALESCE(MAX(p.monto_global_origen), ROUND(SUM(p.monto) + COALESCE(MAX(e.excedente), 0), 2)) > 0;
