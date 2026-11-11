-- Pegar esto en phpMyAdmin falla si el cuadro Delimiter dice ;
-- Importar este archivo por la pestana Importar. Esa pestana si obedece DELIMITER.
-- La 041 de tablas ya esta aplicada. Esto crea triggers, bancos, marca e idempotencia.

DELIMITER $$
DROP PROCEDURE IF EXISTS gas_recalcular_despacho$$
DROP TRIGGER IF EXISTS trg_gas_despacho_bi$$
DROP TRIGGER IF EXISTS trg_gas_despacho_bu$$
DROP TRIGGER IF EXISTS trg_gas_despacho_au$$
DROP TRIGGER IF EXISTS trg_gas_dc_bi$$
DROP TRIGGER IF EXISTS trg_gas_rep_bi$$


CREATE PROCEDURE gas_recalcular_despacho(IN p_despacho_id INT UNSIGNED)
BEGIN
    DECLARE v_cant INT UNSIGNED;
    DECLARE v_costo DECIMAL(12,2);
    DECLARE v_pagado DECIMAL(12,2);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @gas_recalc = NULL;
        RESIGNAL;
    END;

    SELECT COUNT(*), COALESCE(SUM(subtotal_usd), 0)
      INTO v_cant, v_costo
      FROM gas_despacho_cilindros
     WHERE despacho_id = p_despacho_id;

    SELECT COALESCE(SUM(monto_usd), 0) INTO v_pagado
      FROM gas_pagos
     WHERE despacho_id = p_despacho_id
       AND anulado_en IS NULL;

    SET @gas_recalc = 1;
    UPDATE gas_despachos
       SET cantidad = v_cant,
           costo_total_usd = v_costo,
           pagado_usd = v_pagado,
           estado_deuda = gas_estado_deuda(v_costo, v_pagado)
     WHERE id = p_despacho_id;
    SET @gas_recalc = NULL;
END$$

CREATE TRIGGER trg_gas_despacho_bi BEFORE INSERT ON gas_despachos FOR EACH ROW
BEGIN
    DECLARE v_estado VARCHAR(20);

    SELECT estado INTO v_estado FROM gas_proveedores WHERE id = NEW.proveedor_id;
    IF v_estado IS NULL OR v_estado <> 'activo' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El proveedor de gas no esta activo';
    END IF;

    UPDATE gas_correlativos
       SET ultimo = LAST_INSERT_ID(ultimo + 1)
     WHERE clave = 'despacho';
    SET NEW.codigo_despacho = CONCAT('DESP-', LPAD(LAST_INSERT_ID(), 6, '0'));
    SET NEW.fecha_entrega = NULL;
    SET NEW.cantidad = 0;
    SET NEW.costo_total_usd = 0.00;
    SET NEW.pagado_usd = 0.00;
    SET NEW.estado_deuda = 'pendiente';
END$$

CREATE TRIGGER trg_gas_despacho_bu BEFORE UPDATE ON gas_despachos FOR EACH ROW
BEGIN
    DECLARE v_cant INT UNSIGNED;
    DECLARE v_costo DECIMAL(12,2);
    DECLARE v_estado_prov VARCHAR(20);

    SET NEW.codigo_despacho = OLD.codigo_despacho;

    IF @gas_recalc = 1 THEN
        IF NOT (NEW.proveedor_id = OLD.proveedor_id
                AND NEW.fecha_salida = OLD.fecha_salida
                AND NEW.fecha_entrega <=> OLD.fecha_entrega
                AND NEW.precio_10_usd = OLD.precio_10_usd
                AND NEW.precio_15_usd = OLD.precio_15_usd
                AND NEW.precio_27_usd = OLD.precio_27_usd
                AND NEW.precio_43_usd = OLD.precio_43_usd
                AND NEW.observaciones <=> OLD.observaciones
                AND NEW.registrado_por = OLD.registrado_por) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El recalculo del despacho altero datos operativos';
        END IF;
    ELSE
        IF OLD.pagado_usd > 0 AND (
            NEW.precio_10_usd <> OLD.precio_10_usd
            OR NEW.precio_15_usd <> OLD.precio_15_usd
            OR NEW.precio_27_usd <> OLD.precio_27_usd
            OR NEW.precio_43_usd <> OLD.precio_43_usd
            OR NEW.proveedor_id <> OLD.proveedor_id
            OR NEW.fecha_salida <> OLD.fecha_salida
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho ya tiene abonos: anulelos antes de corregirlo';
        END IF;
        IF OLD.fecha_entrega IS NOT NULL AND NEW.fecha_entrega IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un despacho entregado no vuelve a transito';
        END IF;
        IF NEW.proveedor_id <> OLD.proveedor_id THEN
            SELECT estado INTO v_estado_prov FROM gas_proveedores WHERE id = NEW.proveedor_id;
            IF v_estado_prov <> 'activo' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El proveedor de gas no esta activo';
            END IF;
        END IF;

        IF NEW.precio_10_usd <> OLD.precio_10_usd
           OR NEW.precio_15_usd <> OLD.precio_15_usd
           OR NEW.precio_27_usd <> OLD.precio_27_usd
           OR NEW.precio_43_usd <> OLD.precio_43_usd THEN
            SELECT COUNT(*), COALESCE(SUM(
                       CASE capacidad_kg
                           WHEN 10 THEN NEW.precio_10_usd
                           WHEN 15 THEN NEW.precio_15_usd
                           WHEN 27 THEN NEW.precio_27_usd
                           WHEN 43 THEN NEW.precio_43_usd
                       END
                   ), 0)
              INTO v_cant, v_costo
              FROM gas_despacho_cilindros
             WHERE despacho_id = OLD.id;
            SET NEW.cantidad = v_cant;
            SET NEW.costo_total_usd = v_costo;
            SET NEW.estado_deuda = gas_estado_deuda(NEW.costo_total_usd, NEW.pagado_usd);
        ELSE
            SET NEW.cantidad = OLD.cantidad;
            SET NEW.costo_total_usd = OLD.costo_total_usd;
            SET NEW.pagado_usd = OLD.pagado_usd;
            SET NEW.estado_deuda = OLD.estado_deuda;
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_gas_despacho_au AFTER UPDATE ON gas_despachos FOR EACH ROW
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @gas_precio = NULL;
        SET @gas_ciclo = NULL;
        RESIGNAL;
    END;

    IF @gas_recalc IS NULL AND (
        NEW.precio_10_usd <> OLD.precio_10_usd
        OR NEW.precio_15_usd <> OLD.precio_15_usd
        OR NEW.precio_27_usd <> OLD.precio_27_usd
        OR NEW.precio_43_usd <> OLD.precio_43_usd
    ) THEN
        SET @gas_precio = 1;
        UPDATE gas_despacho_cilindros
           SET precio_unitario_usd = CASE capacidad_kg
                   WHEN 10 THEN NEW.precio_10_usd
                   WHEN 15 THEN NEW.precio_15_usd
                   WHEN 27 THEN NEW.precio_27_usd
                   ELSE NEW.precio_43_usd
               END,
               subtotal_usd = CASE capacidad_kg
                   WHEN 10 THEN NEW.precio_10_usd
                   WHEN 15 THEN NEW.precio_15_usd
                   WHEN 27 THEN NEW.precio_27_usd
                   ELSE NEW.precio_43_usd
               END
         WHERE despacho_id = NEW.id;
        SET @gas_precio = NULL;
    END IF;

    IF OLD.fecha_entrega IS NULL AND NEW.fecha_entrega IS NOT NULL THEN
        SET @gas_ciclo = 1;
        UPDATE gas_cilindros
           SET despacho_abierto_id = NULL
         WHERE despacho_abierto_id = NEW.id;
        SET @gas_ciclo = NULL;
    END IF;
END$$

CREATE TRIGGER trg_gas_dc_bi BEFORE INSERT ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    DECLARE v_pertenencia VARCHAR(10);
    DECLARE v_tipo VARCHAR(20);
    DECLARE v_empleado INT UNSIGNED;
    DECLARE v_transportista INT UNSIGNED;
    DECLARE v_productor INT UNSIGNED;
    DECLARE v_nombre VARCHAR(120);
    DECLARE v_fisico VARCHAR(20);
    DECLARE v_registro VARCHAR(20);
    DECLARE v_abierto INT UNSIGNED;
    DECLARE v_cap TINYINT UNSIGNED;
    DECLARE v_precio DECIMAL(12,2);
    DECLARE v_p10 DECIMAL(12,2);
    DECLARE v_p15 DECIMAL(12,2);
    DECLARE v_p27 DECIMAL(12,2);
    DECLARE v_p43 DECIMAL(12,2);
    DECLARE v_pagado DECIMAL(12,2);
    DECLARE v_entrega DATE;
    DECLARE v_cilindro_id INT UNSIGNED;
    DECLARE v_despacho_id INT UNSIGNED;
    DECLARE v_foto_nombre VARCHAR(120);
    DECLARE v_reparacion INT UNSIGNED;

    SET v_cilindro_id = NEW.cilindro_id;
    SET v_despacho_id = NEW.despacho_id;

    SELECT pertenencia, tipo_tercero, empleado_id, transportista_id, productor_id,
           propietario_nombre, estado_fisico, estado_registro, despacho_abierto_id, capacidad_kg
      INTO v_pertenencia, v_tipo, v_empleado, v_transportista, v_productor,
           v_nombre, v_fisico, v_registro, v_abierto, v_cap
      FROM gas_cilindros
     WHERE id = v_cilindro_id
       FOR UPDATE;

    IF v_pertenencia IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona no existe';
    END IF;
    IF v_registro <> 'activo' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona esta archivada';
    END IF;
    IF v_abierto IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona ya esta en un despacho sin entregar';
    END IF;

    SELECT COUNT(*) INTO v_reparacion
      FROM gas_reparaciones
     WHERE cilindro_id = v_cilindro_id AND estado = 'en_reparacion';
    IF v_reparacion > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona esta en reparacion';
    END IF;

    SELECT precio_10_usd, precio_15_usd, precio_27_usd, precio_43_usd, pagado_usd, fecha_entrega
      INTO v_p10, v_p15, v_p27, v_p43, v_pagado, v_entrega
      FROM gas_despachos
     WHERE id = v_despacho_id
       FOR UPDATE;

    IF v_p10 IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho no existe';
    END IF;
    IF v_entrega IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho ya fue entregado';
    END IF;
    IF v_pagado > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho ya tiene abonos';
    END IF;

    SET v_precio = CASE v_cap
        WHEN 10 THEN v_p10
        WHEN 15 THEN v_p15
        WHEN 27 THEN v_p27
        WHEN 43 THEN v_p43
        ELSE NULL
    END;
    IF v_precio IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona no tiene una capacidad de 10, 15, 27 o 43 kg';
    END IF;

    SET NEW.capacidad_kg = v_cap;
    SET NEW.pertenencia = v_pertenencia;
    SET NEW.tipo_tercero = v_tipo;
    SET NEW.empleado_id = v_empleado;
    SET NEW.transportista_id = v_transportista;
    SET NEW.productor_id = v_productor;
    SET NEW.estado_fisico = v_fisico;
    SET NEW.precio_unitario_usd = v_precio;
    SET NEW.subtotal_usd = v_precio;
    SET NEW.cargo_destino = NULL;
    SET NEW.cargo_ref_id = NULL;
    SET NEW.cargo_en = NULL;

    IF v_pertenencia = 'propio' THEN
        SET NEW.propietario_nombre = NULL;
        SET NEW.cargo_estado = 'no_aplica';
    ELSE
        IF v_tipo = 'empleado' THEN
            SELECT CONCAT(nombres, ' ', apellidos) INTO v_foto_nombre
              FROM empleados WHERE id = v_empleado;
        ELSEIF v_tipo = 'transportista' THEN
            SELECT nombre INTO v_foto_nombre
              FROM log_transportistas WHERE id = v_transportista;
        ELSEIF v_tipo = 'productor' THEN
            SELECT nombre INTO v_foto_nombre
              FROM productores WHERE id = v_productor;
        ELSE
            SET v_foto_nombre = v_nombre;
        END IF;
        SET NEW.propietario_nombre = v_foto_nombre;
        SET NEW.cargo_estado = 'pendiente';
    END IF;
END$$

CREATE TRIGGER trg_gas_rep_bi BEFORE INSERT ON gas_reparaciones FOR EACH ROW
BEGIN
    DECLARE v_fisico VARCHAR(20);
    DECLARE v_registro VARCHAR(20);
    DECLARE v_abierto INT UNSIGNED;
    DECLARE v_cilindro_id INT UNSIGNED;

    SET v_cilindro_id = NEW.cilindro_id;

    IF NEW.estado <> 'en_reparacion' OR NEW.costo_usd IS NOT NULL OR NEW.fecha_retorno IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La reparacion nace en curso, sin costo ni retorno';
    END IF;

    SELECT estado_fisico, estado_registro, despacho_abierto_id
      INTO v_fisico, v_registro, v_abierto
      FROM gas_cilindros
     WHERE id = v_cilindro_id
       FOR UPDATE;

    IF v_fisico IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona no existe';
    END IF;
    IF v_registro <> 'activo' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona esta archivada';
    END IF;
    IF v_fisico NOT IN ('regular', 'mal_estado') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se repara una bombona en estado regular o mal estado';
    END IF;
    IF v_abierto IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bombona esta en un despacho sin entregar';
    END IF;
END$$

DELIMITER ;

-- ##################### 042_gas_serial_cilindro.sql #####################
-- =====================================================================
-- ADARA · Migración 042: serial automático de bombonas (CIL-000001)
--
-- MariaDB 10.4 rechaza GENERATED ALWAYS AS sobre una columna
-- AUTO_INCREMENT (ERROR 1901). El número se arma en el BEFORE INSERT
-- con el mismo correlativo de los despachos, y coincide con el id:
-- la bombona 101 queda CIL-000101 y la siguiente será CIL-000102.
--
-- No se borra la fila de prueba: está en un despacho. Solo se reemplaza
-- el serial escrito a mano (042462852) por el código del sistema.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

DROP TRIGGER IF EXISTS trg_gas_cil_bu;
DROP TRIGGER IF EXISTS trg_gas_cil_bi;

UPDATE gas_cilindros
   SET serial = CONCAT('CIL-', LPAD(id, 6, '0'));

INSERT INTO gas_correlativos (clave, ultimo)
SELECT 'cilindro', COALESCE(MAX(id), 0) FROM gas_cilindros
ON DUPLICATE KEY UPDATE ultimo = VALUES(ultimo);

DELIMITER $$

-- MariaDB 10.4 rechaza GENERATED ALWAYS AS sobre AUTO_INCREMENT (ERROR 1901).
-- El BEFORE INSERT toma el siguiente número del correlativo, lo escribe en id
-- y arma el serial CIL-000102. Así el código sigue al id aunque un INSERT
-- revertido haya consumido el contador interno de la tabla.
CREATE TRIGGER trg_gas_cil_bi BEFORE INSERT ON gas_cilindros FOR EACH ROW
BEGIN
    UPDATE gas_correlativos
       SET ultimo = LAST_INSERT_ID(ultimo + 1)
     WHERE clave = 'cilindro';
    SET NEW.id = LAST_INSERT_ID();
    SET NEW.serial = CONCAT('CIL-', LPAD(NEW.id, 6, '0'));
END$$

CREATE TRIGGER trg_gas_cil_bu BEFORE UPDATE ON gas_cilindros FOR EACH ROW
BEGIN
    IF NEW.serial <> OLD.serial THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El serial de la bombona no se modifica';
    END IF;
    SET NEW.serial = OLD.serial;
    IF @gas_ciclo IS NULL AND NOT (NEW.despacho_abierto_id <=> OLD.despacho_abierto_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El viaje abierto de la bombona lo asigna el despacho';
    END IF;
    IF NEW.estado_registro = 'archivado' AND NEW.despacho_abierto_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se archiva una bombona que esta en un viaje abierto';
    END IF;
END$$

DELIMITER ;

-- ##################### 043_gas_quitar_viaje_vacio.sql #####################
-- =====================================================================
-- ADARA · Migración 043: quitar un viaje vacío aunque tenga fecha de entrega
-- Un despacho sin bombonas y sin abonos no conserva historial.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_gas_despacho_bd;

DELIMITER $$

CREATE TRIGGER trg_gas_despacho_bd BEFORE DELETE ON gas_despachos FOR EACH ROW
BEGIN
    IF OLD.cantidad > 0 OR OLD.pagado_usd > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se borra un despacho vacio, sin abonos';
    END IF;
END$$

DELIMITER ;

-- ##################### 044_modulo_bancos.sql #####################
-- =====================================================================
-- ADARA · Migración 044: Conciliación bancaria
--   El día no se digita movimiento por movimiento. Se sube el Excel del
--   cash manager y, si la caja cuadra, se inyecta en una sola transacción.
--
--   Por cuenta y fecha:
--     saldo_final = saldo_inicio + ingresos − egresos − comisiones
--   Solo entra un cierre con estado 'cuadrado'. La pareja cuenta+fecha
--   no se repite: un día ya cerrado no se vuelve a cargar.
--
--   saldo_actual_bs es el saldo_final del cierre más reciente de la cuenta.
--   Los movimientos quedan ligados a ese cierre.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE bancos_cuentas (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alias_cuenta     VARCHAR(80)  NOT NULL,
    saldo_actual_bs  DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    activa           TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bancos_alias (alias_cuenta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bancos_cierres_diarios (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id         INT UNSIGNED NOT NULL,
    fecha             DATE         NOT NULL,
    saldo_inicio      DECIMAL(18,2) NOT NULL,
    total_ingresos    DECIMAL(18,2) NOT NULL,
    total_egresos     DECIMAL(18,2) NOT NULL,
    total_comisiones  DECIMAL(18,2) NOT NULL,
    saldo_final       DECIMAL(18,2) NOT NULL,
    estado            ENUM('cuadrado') NOT NULL DEFAULT 'cuadrado',
    archivo           VARCHAR(180) NULL,
    registrado_por    INT UNSIGNED NOT NULL,
    creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_banco_cierre (cuenta_id, fecha),
    KEY ix_banco_cierre_fecha (fecha),
    CONSTRAINT fk_banco_cierre_cuenta FOREIGN KEY (cuenta_id) REFERENCES bancos_cuentas (id),
    CONSTRAINT fk_banco_cierre_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_banco_cierre_formula CHECK (
        saldo_final = saldo_inicio + total_ingresos - total_egresos - total_comisiones
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bancos_movimientos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id       INT UNSIGNED NOT NULL,
    cierre_id       INT UNSIGNED NOT NULL,
    fecha           DATE         NOT NULL,
    tipo            ENUM('ingreso','egreso') NOT NULL,
    categoria       VARCHAR(80)  NOT NULL,
    descripcion     VARCHAR(255) NOT NULL DEFAULT '',
    beneficiario    VARCHAR(120) NULL,
    referencia      VARCHAR(40)  NULL,
    monto_bs        DECIMAL(18,2) NOT NULL,
    comision_bs     DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    monto_usd       DECIMAL(18,4) NULL,
    tasa_usd_dia    DECIMAL(18,6) NOT NULL,
    hoja            VARCHAR(40)  NULL,
    fila            INT UNSIGNED NULL,
    registrado_por  INT UNSIGNED NOT NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_banco_mov_cuenta_fecha (cuenta_id, fecha),
    KEY ix_banco_mov_cierre (cierre_id),
    CONSTRAINT fk_banco_mov_cuenta FOREIGN KEY (cuenta_id) REFERENCES bancos_cuentas (id),
    CONSTRAINT fk_banco_mov_cierre FOREIGN KEY (cierre_id) REFERENCES bancos_cierres_diarios (id),
    CONSTRAINT fk_banco_mov_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_banco_mov_monto CHECK (monto_bs > 0),
    CONSTRAINT ck_banco_mov_comision CHECK (comision_bs >= 0),
    CONSTRAINT ck_banco_mov_tasa CHECK (tasa_usd_dia > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ##################### 045_bancos_catalogo.sql #####################
-- =====================================================================
-- ADARA · Migración 045: tarifario y catálogo de bancos
--   La comisión no se digita: Excel la calcula (Pago Móvil 1,5 %,
--   transferencia a otros bancos 0,30 %, mismo banco 0) y ADARA guarda
--   el valor ya resuelto junto con el método y el banco de la operación.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE bancos_catalogo (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_banco  VARCHAR(80)  NOT NULL,
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bancos_catalogo_nombre (nombre_banco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bancos_catalogo (nombre_banco) VALUES
    ('Banco de Venezuela'),
    ('Banesco'),
    ('Banco Provincial'),
    ('BNC'),
    ('Bancamiga'),
    ('Banco del Tesoro'),
    ('Mercantil'),
    ('Bancaribe'),
    ('Banco Exterior'),
    ('Banco Activo'),
    ('Banco Plaza'),
    ('Banco Caroní'),
    ('Banplus'),
    ('100% Banco'),
    ('Banco Venezolano de Crédito')
ON DUPLICATE KEY UPDATE nombre_banco = VALUES(nombre_banco);

ALTER TABLE bancos_movimientos
    ADD COLUMN metodo_pago VARCHAR(40) NULL AFTER categoria,
    ADD COLUMN banco_origen_destino VARCHAR(80) NULL AFTER metodo_pago;

-- ##################### 046_bancos_multimoneda_auditoria.sql #####################
-- =====================================================================
-- ADARA · Migración 046: moneda nativa y auditoría cruzada de bancos
--
-- bancos_cuentas.moneda es la unidad del cuadre (BS o USD nominal).
-- La tasa no convierte el saldo: un cierre en USD se compara en USD.
--
-- bancos_movimientos.monto_usd ya existía como DECIMAL(18,4). Se deja
-- así: alcanza montos grandes y conserva los 4 decimales del libro.
-- DECIMAL(10,2) se quedaría corto y recortaría esa escala.
-- id_origen es BIGINT porque productores_abonos y gas_pagos usan BIGINT.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE bancos_cuentas
    ADD COLUMN moneda ENUM('BS','USD') NOT NULL DEFAULT 'BS' AFTER alias_cuenta;

ALTER TABLE bancos_movimientos
    ADD COLUMN entidad_relacionada VARCHAR(100) NULL AFTER beneficiario;

ALTER TABLE bancos_movimientos
    ADD COLUMN estado_auditoria ENUM('conciliado','huerfano','no_aplica') NOT NULL DEFAULT 'no_aplica' AFTER fila;

ALTER TABLE bancos_movimientos
    ADD COLUMN modulo_origen VARCHAR(50) NULL AFTER estado_auditoria;

ALTER TABLE bancos_movimientos
    ADD COLUMN id_origen BIGINT UNSIGNED NULL AFTER modulo_origen,
    ADD KEY ix_banco_mov_auditoria (estado_auditoria, fecha),
    ADD KEY ix_banco_mov_origen (modulo_origen, id_origen);

-- ##################### 047_bancos_cuentas_gestor.sql #####################
-- =====================================================================
-- ADARA · Migración 047: gestor de cuentas bancarias
--   Corre después de 046 (moneda ya existe).
--   alias_cuenta sigue siendo el nombre que viaja al Excel.
--   nombre_banco, numero_cuenta, cedula y telefono son los datos
--   que el usuario copia para enviarlos por WhatsApp.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE bancos_cuentas
    ADD COLUMN nombre_banco VARCHAR(80) NULL AFTER alias_cuenta;

ALTER TABLE bancos_cuentas
    ADD COLUMN numero_cuenta VARCHAR(30) NULL AFTER nombre_banco;

ALTER TABLE bancos_cuentas
    ADD COLUMN cedula VARCHAR(24) NULL AFTER numero_cuenta;

ALTER TABLE bancos_cuentas
    ADD COLUMN telefono VARCHAR(24) NULL AFTER cedula;

-- ##################### 048_empresa_configuracion.sql #####################
-- =====================================================================
-- ADARA · Migración 048: marca blanca
--   Un solo registro (id = 1). El layout lee esta fila en cada página.
--   Los colores actuales del ERP: naranja #F97316 y azul #1A2E5A.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS empresa_configuracion (
    id                TINYINT UNSIGNED NOT NULL,
    nombre_empresa    VARCHAR(120) NOT NULL,
    rif_documento     VARCHAR(20)  NOT NULL,
    telefono          VARCHAR(30)  NOT NULL DEFAULT '',
    direccion         VARCHAR(180) NOT NULL DEFAULT '',
    color_primario    VARCHAR(7)   NOT NULL,
    color_secundario  VARCHAR(7)   NOT NULL,
    ruta_logo         VARCHAR(180) NULL,
    ruta_sello        VARCHAR(180) NULL,
    actualizado_en    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT ck_empresa_config_unica CHECK (id = 1),
    CONSTRAINT ck_empresa_color_primario CHECK (color_primario REGEXP '^#[0-9A-Fa-f]{6}$'),
    CONSTRAINT ck_empresa_color_secundario CHECK (color_secundario REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa_configuracion
    (id, nombre_empresa, rif_documento, telefono, direccion, color_primario, color_secundario, ruta_logo, ruta_sello)
VALUES
    (1, 'El Castillo del Queso Artesanal F.P.', 'V-286013356', '', '', '#F97316', '#1A2E5A', 'img/logo.jpg', NULL)
ON DUPLICATE KEY UPDATE id = id;

-- ##################### 049_api_idempotencia.sql #####################
-- =====================================================================
-- ADARA · Migración 049: idempotencia de la API (modo offline)
--   Una fila por X-Idempotency-Key procesada con éxito (2xx).
--   La fila se inserta dentro de la MISMA transacción que la operación de
--   negocio (security/idempotencia.php): o quedan ambas o ninguna. Por eso
--   no hay estado "procesando": una petición duplicada concurrente espera el
--   bloqueo de la PK y, al liberarse, lee la respuesta ya confirmada.
--   Retención: 30 días (purga en Idempotencia::purgar()).
-- =====================================================================
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
