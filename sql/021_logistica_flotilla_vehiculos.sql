-- =====================================================================
-- ADARA · Migración 021: flotilla por vehículos, productores compartidos
-- =====================================================================
USE adara;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

SET @c0 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_transportistas' AND COLUMN_NAME = 'modalidad');
SET @s0 = IF(@c0 = 0,
    "ALTER TABLE log_transportistas ADD COLUMN modalidad ENUM('individual','cooperativa') NOT NULL DEFAULT 'individual' AFTER telefono",
    'SELECT 1');
PREPARE st0 FROM @s0; EXECUTE st0; DEALLOCATE PREPARE st0;

CREATE TABLE IF NOT EXISTS log_transportista_vehiculos (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    transportista_id INT UNSIGNED  NOT NULL,
    tipo_vehiculo    ENUM('moto','carro','camioneta','camion','otro') NOT NULL DEFAULT 'otro',
    marca            VARCHAR(60)   NULL,
    modelo           VARCHAR(60)   NULL,
    color            VARCHAR(40)   NULL,
    placa            VARCHAR(15)   NOT NULL,
    limite_carga_kg  DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_log_tv_placa (placa),
    KEY ix_log_tv_tr (transportista_id),
    CONSTRAINT fk_log_tv_tr FOREIGN KEY (transportista_id) REFERENCES log_transportistas (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_log_tv_placa  CHECK (CHAR_LENGTH(TRIM(placa)) >= 3),
    CONSTRAINT ck_log_tv_limite CHECK (limite_carga_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_old = (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_transportistas' AND COLUMN_NAME = 'tipo_vehiculo');
SET @ins = IF(@has_old = 0, 'SELECT 1',
    "INSERT INTO log_transportista_vehiculos (transportista_id, tipo_vehiculo, placa, limite_carga_kg)
     SELECT t.id, COALESCE(t.tipo_vehiculo, 'otro'),
            COALESCE(NULLIF(TRIM(t.placa), ''), CONCAT('PEND-', t.id)),
            COALESCE(NULLIF(t.limite_carga_kg, 0), 1)
     FROM log_transportistas t
     LEFT JOIN log_transportista_vehiculos v ON v.transportista_id = t.id
     WHERE v.id IS NULL");
PREPARE stins FROM @ins; EXECUTE stins; DEALLOCATE PREPARE stins;

UPDATE log_transportistas t
INNER JOIN (
    SELECT transportista_id, COUNT(*) AS n
    FROM log_transportista_vehiculos
    GROUP BY transportista_id
) x ON x.transportista_id = t.id
SET t.modalidad = IF(x.n > 1, 'cooperativa', 'individual');

-- Productores compartidos: UNIQUE solo (ruta_id, productor_id). Nunca UNIQUE(productor_id).
SET @drop_idx = (
    SELECT INDEX_NAME FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_ruta_productores'
      AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' AND INDEX_NAME <> 'uq_log_rp'
    GROUP BY INDEX_NAME
    HAVING SUM(COLUMN_NAME = 'productor_id') = 1 AND COUNT(*) = 1
    LIMIT 1
);
SET @sidx = IF(@drop_idx IS NULL, 'SELECT 1', CONCAT('ALTER TABLE log_ruta_productores DROP INDEX `', @drop_idx, '`'));
PREPARE stidx FROM @sidx; EXECUTE stidx; DEALLOCATE PREPARE stidx;

SET @ck1 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_transportistas' AND CONSTRAINT_NAME = 'ck_log_tr_limite');
SET @sk1 = IF(@ck1 = 0, 'SELECT 1', 'ALTER TABLE log_transportistas DROP CONSTRAINT ck_log_tr_limite');
PREPARE stk1 FROM @sk1; EXECUTE stk1; DEALLOCATE PREPARE stk1;

SET @ck2 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_transportistas' AND CONSTRAINT_NAME = 'ck_log_tr_vehiculos');
SET @sk2 = IF(@ck2 = 0, 'SELECT 1', 'ALTER TABLE log_transportistas DROP CONSTRAINT ck_log_tr_vehiculos');
PREPARE stk2 FROM @sk2; EXECUTE stk2; DEALLOCATE PREPARE stk2;

SET @d1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_transportistas' AND COLUMN_NAME = 'tipo_vehiculo');
SET @sd1 = IF(@d1 = 0, 'SELECT 1', 'ALTER TABLE log_transportistas DROP COLUMN tipo_vehiculo, DROP COLUMN placa, DROP COLUMN limite_carga_kg');
PREPARE std1 FROM @sd1; EXECUTE std1; DEALLOCATE PREPARE std1;

SET @d2 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_transportistas' AND COLUMN_NAME = 'cantidad_vehiculos');
SET @sd2 = IF(@d2 = 0, 'SELECT 1', 'ALTER TABLE log_transportistas DROP COLUMN cantidad_vehiculos');
PREPARE std2 FROM @sd2; EXECUTE std2; DEALLOCATE PREPARE std2;
