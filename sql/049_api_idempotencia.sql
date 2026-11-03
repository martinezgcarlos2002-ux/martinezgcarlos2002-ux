-- =====================================================================
-- ADARA · Migración 049: idempotencia de la API (modo offline)
--   Una fila por X-Idempotency-Key procesada con éxito (2xx).
--   La fila se inserta dentro de la MISMA transacción que la operación de
--   negocio (security/idempotencia.php): o quedan ambas o ninguna. Por eso
--   no hay estado "procesando": una petición duplicada concurrente espera el
--   bloqueo de la PK y, al liberarse, lee la respuesta ya confirmada.
--   Retención: 30 días (purga en Idempotencia::purgar()).
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS api_idempotencia (
    clave           CHAR(36)          NOT NULL,
    usuario_id      INT UNSIGNED      NOT NULL,
    endpoint        VARCHAR(60)       NOT NULL,
    huella_peticion CHAR(64)          NOT NULL,
    codigo_http     SMALLINT UNSIGNED NOT NULL,
    respuesta_json  MEDIUMTEXT        NOT NULL,
    creado_en       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (clave),
    KEY ix_idempotencia_creado (creado_en),
    KEY ix_idempotencia_usuario (usuario_id, creado_en),
    CONSTRAINT fk_idempotencia_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_idempotencia_clave CHECK (clave REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'),
    CONSTRAINT ck_idempotencia_http CHECK (codigo_http BETWEEN 200 AND 299),
    CONSTRAINT ck_idempotencia_json CHECK (JSON_VALID(respuesta_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
