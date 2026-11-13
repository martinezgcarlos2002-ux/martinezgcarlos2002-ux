-- Suscripción, términos del cliente y tickets de soporte.
-- Se ejecuta en la base maestra (adara_master), no en la base de un cliente.
-- La fecha de aceptación ya existe como fecha_aceptacion_contrato.
-- Idempotente.

SET @existe := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clientes_saas'
      AND COLUMN_NAME = 'contrato_aceptado'
);

SET @sql := IF(
    @existe = 0,
    'ALTER TABLE clientes_saas ADD COLUMN contrato_aceptado TINYINT(1) NOT NULL DEFAULT 0 AFTER fecha_aceptacion_contrato',
    'SELECT 1'
);

PREPARE adara_contrato FROM @sql;
EXECUTE adara_contrato;
DEALLOCATE PREPARE adara_contrato;

UPDATE clientes_saas
SET contrato_aceptado = 1
WHERE contrato_aceptado = 0
  AND fecha_aceptacion_contrato IS NOT NULL;

CREATE TABLE IF NOT EXISTS saas_suscripciones (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente      INT UNSIGNED    NOT NULL,
    tarifa_usd      DECIMAL(12,2)   NOT NULL,
    dia_inicio      DATE            NOT NULL,
    dia_corte       DATE            NOT NULL,
    modo_pago       ENUM('efectivo','transferencia','pago_movil','zelle') NOT NULL,
    referencia      VARCHAR(80)     NULL,
    fecha_pago      DATE            NOT NULL,
    tasa_bcv_dia    DECIMAL(12,4)   NULL,
    registrado_por  INT UNSIGNED    NOT NULL,
    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_saas_suscripciones_cliente (id_cliente, id),
    CONSTRAINT fk_saas_suscripciones_cliente
        FOREIGN KEY (id_cliente) REFERENCES clientes_saas (id),
    CONSTRAINT fk_saas_suscripciones_maestro
        FOREIGN KEY (registrado_por) REFERENCES super_admins (id),
    CONSTRAINT ck_saas_suscripciones_tarifa CHECK (tarifa_usd > 0),
    CONSTRAINT ck_saas_suscripciones_periodo CHECK (dia_corte >= dia_inicio),
    CONSTRAINT ck_saas_suscripciones_tasa CHECK (tasa_bcv_dia IS NULL OR tasa_bcv_dia > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_tickets_soporte (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente          INT UNSIGNED    NOT NULL,
    id_usuario_cliente  INT UNSIGNED    NOT NULL,
    asunto              VARCHAR(140)    NOT NULL,
    tipo_solicitud      ENUM('falla_critica','asistencia','sugerencia') NOT NULL,
    descripcion         VARCHAR(2000)   NOT NULL,
    estado              ENUM('nuevo','pendiente','en_atencion','cerrado') NOT NULL DEFAULT 'nuevo',
    fecha_creacion      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_saas_tickets_estado (estado, fecha_creacion),
    KEY ix_saas_tickets_cliente (id_cliente, fecha_creacion),
    CONSTRAINT fk_saas_tickets_cliente
        FOREIGN KEY (id_cliente) REFERENCES clientes_saas (id),
    CONSTRAINT ck_saas_tickets_asunto CHECK (CHAR_LENGTH(asunto) BETWEEN 4 AND 140),
    CONSTRAINT ck_saas_tickets_texto CHECK (CHAR_LENGTH(descripcion) BETWEEN 10 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
