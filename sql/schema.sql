-- =====================================================================
-- ADARA · El Castillo del Queso Artesanal F.P.
-- Esquema inicial. Compatible con MySQL 8.0+ y MariaDB 10.4+ (XAMPP).
--
-- Convenciones de tipos:
--   Montos de dinero ............ DECIMAL(12,2)   (USD o Bs, siempre 2 decimales)
--   Precio unitario por litro ... DECIMAL(12,4)   (tarifa, no monto: se redondea al multiplicar)
--   Tasa Bs/USD ................. DECIMAL(14,4)   (el BCV publica 4+ decimales)
--   Litros y kilogramos ......... DECIMAL(12,3)
--   Semana operativa ............ sábado a viernes
-- =====================================================================

CREATE DATABASE IF NOT EXISTS adara CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- Seguridad
-- ---------------------------------------------------------------------

CREATE TABLE empleados (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    cedula              VARCHAR(12)   NULL,
    nombres             VARCHAR(60)   NOT NULL,
    apellidos           VARCHAR(60)   NOT NULL,
    cargo               VARCHAR(60)   NULL,
    telefono            VARCHAR(20)   NULL,
    direccion           VARCHAR(160)  NULL,
    fecha_nacimiento    DATE          NULL,
    fecha_ingreso       DATE          NULL,
    fecha_salida        DATE          NULL,
    salario_semanal_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cuenta_propia       TINYINT(1)    NOT NULL DEFAULT 0,
    activo              TINYINT(1)    NOT NULL DEFAULT 1,
    creado_en           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empleados_cedula (cedula),
    CONSTRAINT ck_empleados_cedula   CHECK (cedula IS NULL OR cedula REGEXP '^[VE]-[0-9]{6,9}$'),
    CONSTRAINT ck_empleados_salario  CHECK (salario_semanal_usd >= 0),
    CONSTRAINT ck_empleados_fechas   CHECK (fecha_salida IS NULL OR fecha_ingreso IS NULL OR fecha_salida >= fecha_ingreso),
    CONSTRAINT ck_empleados_flags    CHECK (cuenta_propia IN (0,1) AND activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE usuarios (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    empleado_id    INT UNSIGNED  NULL,
    nombre         VARCHAR(80)   NOT NULL,
    email          VARCHAR(100)  NOT NULL,
    password_hash  VARCHAR(255)  NOT NULL,
    rol            ENUM('admin','empleado') NOT NULL DEFAULT 'empleado',
    permisos       JSON          NOT NULL,
    activo         TINYINT(1)    NOT NULL DEFAULT 1,
    ultimo_acceso  DATETIME      NULL,
    creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email),
    UNIQUE KEY uq_usuarios_empleado (empleado_id),
    CONSTRAINT fk_usuarios_empleado FOREIGN KEY (empleado_id) REFERENCES empleados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_usuarios_email    CHECK (email = LOWER(email) AND email LIKE '_%@_%._%'),
    CONSTRAINT ck_usuarios_hash     CHECK (CHAR_LENGTH(password_hash) >= 60),
    CONSTRAINT ck_usuarios_nombre   CHECK (CHAR_LENGTH(TRIM(nombre)) >= 2),
    CONSTRAINT ck_usuarios_activo   CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE login_intentos (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(100)    NOT NULL,
    ip        VARCHAR(45)     NOT NULL,
    exito     TINYINT(1)      NOT NULL,
    creado_en DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_login_ip_fecha (ip, creado_en),
    CONSTRAINT ck_login_exito CHECK (exito IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE auditoria (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED    NULL,
    modulo     VARCHAR(40)     NOT NULL,
    accion     VARCHAR(40)     NOT NULL,
    entidad    VARCHAR(40)     NULL,
    entidad_id BIGINT UNSIGNED NULL,
    detalle    JSON            NULL,
    ip         VARCHAR(45)     NOT NULL,
    metodo     VARCHAR(7)      NOT NULL,
    ruta       VARCHAR(60)     NOT NULL,
    creado_en  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY ix_auditoria_fecha (creado_en),
    KEY ix_auditoria_usuario (usuario_id, creado_en),
    KEY ix_auditoria_modulo (modulo, creado_en),
    CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Moneda
-- ---------------------------------------------------------------------

CREATE TABLE tasas_cambio (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tasa_bs_por_usd DECIMAL(14,4) NOT NULL,
    vigente_desde   DATETIME      NOT NULL,
    fuente          VARCHAR(40)   NOT NULL DEFAULT 'BCV',
    registrada_por  INT UNSIGNED  NOT NULL,
    creado_en       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tasas_vigencia (vigente_desde),
    CONSTRAINT fk_tasas_usuario FOREIGN KEY (registrada_por) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_tasas_positiva CHECK (tasa_bs_por_usd > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Productores de leche
-- ---------------------------------------------------------------------

CREATE TABLE productores (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    nombre           VARCHAR(120)      NOT NULL,
    documento        VARCHAR(12)       NULL,
    telefono         VARCHAR(20)       NULL,
    correo           VARCHAR(100)      NULL,
    direccion        VARCHAR(160)      NULL,
    precio_litro_usd DECIMAL(12,4)     NOT NULL,
    credito_dias     SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    activo           TINYINT(1)        NOT NULL DEFAULT 1,
    creado_en        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME          NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productores_nombre (nombre),
    UNIQUE KEY uq_productores_documento (documento),
    CONSTRAINT ck_productores_documento CHECK (documento IS NULL OR documento REGEXP '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
    CONSTRAINT ck_productores_precio    CHECK (precio_litro_usd > 0),
    CONSTRAINT ck_productores_credito   CHECK (credito_dias <= 90),
    CONSTRAINT ck_productores_activo    CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE productores_precios (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    productor_id        INT UNSIGNED  NOT NULL,
    precio_anterior_usd DECIMAL(12,4) NULL,
    precio_nuevo_usd    DECIMAL(12,4) NOT NULL,
    motivo              VARCHAR(160)  NULL,
    usuario_id          INT UNSIGNED  NOT NULL,
    creado_en           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_precios_productor (productor_id, creado_en),
    CONSTRAINT fk_precios_productor FOREIGN KEY (productor_id) REFERENCES productores (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_precios_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuarios (id)    ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_precios_nuevo     CHECK (precio_nuevo_usd > 0)
) ENGINE=InnoDB;

-- Un cierre por productor y semana (sábado a viernes). El saldo puede ser negativo si hubo sobrepago.
CREATE TABLE productores_cierres (
    id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    productor_id          INT UNSIGNED  NOT NULL,
    semana_inicio         DATE          NOT NULL,
    semana_fin            DATE          NOT NULL,
    litros                DECIMAL(12,3) NOT NULL,
    bruto_usd             DECIMAL(12,2) NOT NULL,
    traslado_usd          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    abonos_usd            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    arrastre_anterior_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    saldo_usd             DECIMAL(12,2) NOT NULL,
    pagado_usd            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado                ENUM('cerrada','parcial','pagada','anulada') NOT NULL DEFAULT 'cerrada',
    notas                 VARCHAR(255)  NULL,
    cerrado_por           INT UNSIGNED  NULL,
    cerrado_en            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pagado_en             DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cierres_productor_semana (productor_id, semana_inicio),
    KEY ix_cierres_semana (semana_inicio),
    CONSTRAINT fk_cierres_productor FOREIGN KEY (productor_id) REFERENCES productores (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cierres_usuario   FOREIGN KEY (cerrado_por)  REFERENCES usuarios (id)    ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cierres_sabado    CHECK (DAYOFWEEK(semana_inicio) = 7),
    CONSTRAINT ck_cierres_rango     CHECK (semana_fin = semana_inicio + INTERVAL 6 DAY),
    CONSTRAINT ck_cierres_montos    CHECK (litros >= 0 AND bruto_usd >= 0 AND traslado_usd >= 0 AND abonos_usd >= 0 AND pagado_usd >= 0)
) ENGINE=InnoDB;

-- El precio se congela al registrar la entrega. Cambiar el precio base del productor
-- solo reescribe semanas abiertas (sin cierre vigente), y únicamente en ese momento.
-- Las semanas cerradas, parciales, pagadas o arrastradas conservan su precio histórico.
CREATE TABLE productores_entregas (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    productor_id       INT UNSIGNED    NOT NULL,
    fecha              DATE            NOT NULL,
    litros             DECIMAL(12,3)   NOT NULL,
    precio_litro_usd   DECIMAL(12,4)   NOT NULL,
    subtotal_usd       DECIMAL(12,2)   AS (ROUND(litros * precio_litro_usd, 2)) STORED,
    traslado_usd       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    cuajada_kg         DECIMAL(12,3)   NOT NULL DEFAULT 0.000,
    observaciones      VARCHAR(255)    NULL,
    cierre_id          INT UNSIGNED    NULL,
    registrado_por     INT UNSIGNED    NOT NULL,
    creado_en          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en     DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entregas_productor_fecha (productor_id, fecha),
    KEY ix_entregas_fecha (fecha),
    KEY ix_entregas_cierre (cierre_id),
    CONSTRAINT fk_entregas_productor FOREIGN KEY (productor_id)   REFERENCES productores (id)         ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_entregas_cierre    FOREIGN KEY (cierre_id)      REFERENCES productores_cierres (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_entregas_usuario   FOREIGN KEY (registrado_por) REFERENCES usuarios (id)            ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_entregas_litros    CHECK (litros > 0 AND litros <= 100000),
    CONSTRAINT ck_entregas_precio    CHECK (precio_litro_usd > 0),
    CONSTRAINT ck_entregas_extras    CHECK (traslado_usd >= 0 AND cuajada_kg >= 0)
) ENGINE=InnoDB;

-- Pagos y descuentos al productor (efectivo, pago móvil/transferencia, mercancía, vales).
CREATE TABLE productores_abonos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    productor_id    INT UNSIGNED    NOT NULL,
    fecha           DATE            NOT NULL,
    semana_inicio   DATE            NOT NULL,
    tipo            ENUM('efectivo','pago_movil','transferencia','mercancia','vale','otro') NOT NULL,
    moneda          ENUM('USD','VES') NOT NULL DEFAULT 'USD',
    monto           DECIMAL(12,2)   NOT NULL,
    tasa_bs_por_usd DECIMAL(14,4)   NULL,
    monto_usd       DECIMAL(12,2)   NOT NULL,
    referencia      VARCHAR(40)     NULL,
    descripcion     VARCHAR(255)    NULL,
    cierre_id       INT UNSIGNED    NULL,
    registrado_por  INT UNSIGNED    NOT NULL,
    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_abonos_productor_semana (productor_id, semana_inicio),
    KEY ix_abonos_cierre (cierre_id),
    CONSTRAINT fk_abonos_productor FOREIGN KEY (productor_id)   REFERENCES productores (id)         ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_abonos_cierre    FOREIGN KEY (cierre_id)      REFERENCES productores_cierres (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_abonos_usuario   FOREIGN KEY (registrado_por) REFERENCES usuarios (id)            ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_abonos_montos    CHECK (monto > 0 AND monto_usd > 0),
    CONSTRAINT ck_abonos_tasa      CHECK ((moneda = 'USD' AND tasa_bs_por_usd IS NULL) OR (moneda = 'VES' AND tasa_bs_por_usd > 0)),
    CONSTRAINT ck_abonos_semana    CHECK (DAYOFWEEK(semana_inicio) = 7)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Inventario y producción
-- ---------------------------------------------------------------------

CREATE TABLE productos (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    codigo         VARCHAR(30)   NULL,
    nombre         VARCHAR(100)  NOT NULL,
    unidad         ENUM('kg','unidad','litro') NOT NULL DEFAULT 'kg',
    precio_usd     DECIMAL(12,2) NOT NULL,
    stock          DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    umbral_alerta  DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    activo         TINYINT(1)    NOT NULL DEFAULT 1,
    creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productos_codigo (codigo),
    UNIQUE KEY uq_productos_nombre (nombre),
    CONSTRAINT ck_productos_precio CHECK (precio_usd >= 0),
    CONSTRAINT ck_productos_stock  CHECK (stock >= 0),
    CONSTRAINT ck_productos_umbral CHECK (umbral_alerta >= 0),
    CONSTRAINT ck_productos_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE producciones (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    fecha          DATE          NOT NULL,
    producto_id    INT UNSIGNED  NOT NULL,
    litros_leche   DECIMAL(12,3) NOT NULL,
    factor_l_kg    DECIMAL(8,4)  NOT NULL,
    kg_esperados   DECIMAL(12,3) AS (ROUND(litros_leche / factor_l_kg, 3)) STORED,
    kg_reales      DECIMAL(12,3) NULL,
    observaciones  VARCHAR(255)  NULL,
    registrado_por INT UNSIGNED  NOT NULL,
    creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_producciones_fecha (fecha),
    CONSTRAINT fk_producciones_producto FOREIGN KEY (producto_id)    REFERENCES productos (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_producciones_usuario  FOREIGN KEY (registrado_por) REFERENCES usuarios (id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_producciones_litros   CHECK (litros_leche > 0),
    CONSTRAINT ck_producciones_factor   CHECK (factor_l_kg > 0 AND factor_l_kg <= 50),
    CONSTRAINT ck_producciones_kg_real  CHECK (kg_reales IS NULL OR kg_reales >= 0)
) ENGINE=InnoDB;

CREATE TABLE lotes (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    codigo            VARCHAR(30)   NOT NULL,
    producto_id       INT UNSIGNED  NOT NULL,
    produccion_id     INT UNSIGNED  NULL,
    cantidad          DECIMAL(12,3) NOT NULL,
    fecha_elaboracion DATE          NOT NULL,
    fecha_vencimiento DATE          NULL,
    creado_en         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lotes_codigo (codigo),
    CONSTRAINT fk_lotes_producto   FOREIGN KEY (producto_id)   REFERENCES productos (id)    ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_lotes_produccion FOREIGN KEY (produccion_id) REFERENCES producciones (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_lotes_cantidad   CHECK (cantidad > 0),
    CONSTRAINT ck_lotes_fechas     CHECK (fecha_vencimiento IS NULL OR fecha_vencimiento >= fecha_elaboracion)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Ventas
-- ---------------------------------------------------------------------

CREATE TABLE clientes (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre    VARCHAR(120) NOT NULL,
    documento VARCHAR(12)  NULL,
    telefono  VARCHAR(20)  NULL,
    direccion VARCHAR(160) NULL,
    activo    TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_documento (documento),
    CONSTRAINT ck_clientes_documento CHECK (documento IS NULL OR documento REGEXP '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
    CONSTRAINT ck_clientes_activo    CHECK (activo IN (0,1))
) ENGINE=InnoDB;

-- Una factura no se edita: se anula (estado + quién + cuándo + motivo) y el stock/caja se revierten con asientos nuevos.
CREATE TABLE ventas (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    numero           VARCHAR(20)   NOT NULL,
    cliente_id       INT UNSIGNED  NOT NULL,
    fecha            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    moneda_pago      ENUM('USD','VES') NOT NULL,
    tasa_id          INT UNSIGNED  NOT NULL,
    tasa_bs_por_usd  DECIMAL(14,4) NOT NULL,
    total_usd        DECIMAL(12,2) NOT NULL,
    total_bs         DECIMAL(12,2) NOT NULL,
    estado           ENUM('emitida','anulada') NOT NULL DEFAULT 'emitida',
    emitida_por      INT UNSIGNED  NOT NULL,
    anulada_por      INT UNSIGNED  NULL,
    anulada_en       DATETIME      NULL,
    motivo_anulacion VARCHAR(255)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ventas_numero (numero),
    KEY ix_ventas_fecha (fecha),
    KEY ix_ventas_cliente (cliente_id, fecha),
    CONSTRAINT fk_ventas_cliente   FOREIGN KEY (cliente_id)  REFERENCES clientes (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ventas_tasa      FOREIGN KEY (tasa_id)     REFERENCES tasas_cambio (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ventas_emisor    FOREIGN KEY (emitida_por) REFERENCES usuarios (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ventas_anulador  FOREIGN KEY (anulada_por) REFERENCES usuarios (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_ventas_montos    CHECK (total_usd >= 0 AND total_bs >= 0 AND tasa_bs_por_usd > 0),
    CONSTRAINT ck_ventas_anulacion CHECK (
        (estado = 'emitida' AND anulada_por IS NULL AND anulada_en IS NULL)
        OR (estado = 'anulada' AND anulada_por IS NOT NULL AND anulada_en IS NOT NULL AND motivo_anulacion IS NOT NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE venta_items (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    venta_id        INT UNSIGNED  NOT NULL,
    producto_id     INT UNSIGNED  NOT NULL,
    lote_id         INT UNSIGNED  NULL,
    cantidad        DECIMAL(12,3) NOT NULL,
    precio_unit_usd DECIMAL(12,2) NOT NULL,
    subtotal_usd    DECIMAL(12,2) AS (ROUND(cantidad * precio_unit_usd, 2)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_items_venta_producto_lote (venta_id, producto_id, lote_id),
    CONSTRAINT fk_items_venta    FOREIGN KEY (venta_id)    REFERENCES ventas (id)    ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_items_producto FOREIGN KEY (producto_id) REFERENCES productos (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_items_lote     FOREIGN KEY (lote_id)     REFERENCES lotes (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_items_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_items_precio   CHECK (precio_unit_usd >= 0)
) ENGINE=InnoDB;

-- Kardex: cada cambio de stock deja su rastro con el origen.
CREATE TABLE inventario_movimientos (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    producto_id   INT UNSIGNED    NOT NULL,
    tipo          ENUM('produccion','venta','anulacion_venta','ajuste') NOT NULL,
    cantidad      DECIMAL(12,3)   NOT NULL,
    stock_final   DECIMAL(12,3)   NOT NULL,
    produccion_id INT UNSIGNED    NULL,
    venta_id      INT UNSIGNED    NULL,
    motivo        VARCHAR(255)    NULL,
    usuario_id    INT UNSIGNED    NOT NULL,
    creado_en     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_kardex_producto (producto_id, creado_en),
    CONSTRAINT fk_kardex_producto   FOREIGN KEY (producto_id)   REFERENCES productos (id)    ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_kardex_produccion FOREIGN KEY (produccion_id) REFERENCES producciones (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_kardex_venta      FOREIGN KEY (venta_id)      REFERENCES ventas (id)       ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_kardex_usuario    FOREIGN KEY (usuario_id)    REFERENCES usuarios (id)     ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_kardex_cantidad   CHECK (cantidad <> 0 AND stock_final >= 0),
    CONSTRAINT ck_kardex_ajuste     CHECK (tipo <> 'ajuste' OR motivo IS NOT NULL)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Nómina
-- ---------------------------------------------------------------------

CREATE TABLE nomina_semanal (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    empleado_id         INT UNSIGNED  NOT NULL,
    semana_inicio       DATE          NOT NULL,
    salario_base_usd    DECIMAL(12,2) NOT NULL,
    dias_no_trabajados  DECIMAL(3,1)  NOT NULL DEFAULT 0.0,
    pagos_extras_usd    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    vales_mercancia_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    bodega_bs           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tasa_bs_por_usd     DECIMAL(14,4) NULL,
    neto_usd            DECIMAL(12,2) NOT NULL,
    estado              ENUM('borrador','cerrada','pagada') NOT NULL DEFAULT 'borrador',
    registrado_por      INT UNSIGNED  NOT NULL,
    creado_en           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nomina_empleado_semana (empleado_id, semana_inicio),
    CONSTRAINT fk_nomina_empleado FOREIGN KEY (empleado_id)    REFERENCES empleados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_nomina_usuario  FOREIGN KEY (registrado_por) REFERENCES usuarios (id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_nomina_dias     CHECK (dias_no_trabajados BETWEEN 0 AND 7),
    CONSTRAINT ck_nomina_montos   CHECK (salario_base_usd >= 0 AND pagos_extras_usd >= 0 AND vales_mercancia_usd >= 0 AND bodega_bs >= 0),
    CONSTRAINT ck_nomina_tasa     CHECK (bodega_bs = 0 OR tasa_bs_por_usd > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Caja (libro de solo inserción: los errores se corrigen con un contra-asiento)
-- ---------------------------------------------------------------------

CREATE TABLE caja_movimientos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fecha           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tipo            ENUM('ingreso','egreso') NOT NULL,
    moneda          ENUM('USD','VES') NOT NULL,
    monto           DECIMAL(12,2)   NOT NULL,
    tasa_bs_por_usd DECIMAL(14,4)   NOT NULL,
    monto_usd       DECIMAL(12,2)   NOT NULL,
    concepto        VARCHAR(160)    NOT NULL,
    origen          ENUM('manual','venta','anulacion_venta','pago_productor','nomina','contra_asiento') NOT NULL,
    venta_id        INT UNSIGNED    NULL,
    cierre_id       INT UNSIGNED    NULL,
    nomina_id       INT UNSIGNED    NULL,
    revierte_id     BIGINT UNSIGNED NULL,
    registrado_por  INT UNSIGNED    NOT NULL,
    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_caja_revierte (revierte_id),
    KEY ix_caja_fecha (fecha),
    CONSTRAINT fk_caja_venta    FOREIGN KEY (venta_id)       REFERENCES ventas (id)              ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_caja_cierre   FOREIGN KEY (cierre_id)      REFERENCES productores_cierres (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_caja_nomina   FOREIGN KEY (nomina_id)      REFERENCES nomina_semanal (id)      ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_caja_revierte FOREIGN KEY (revierte_id)    REFERENCES caja_movimientos (id)    ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_caja_usuario  FOREIGN KEY (registrado_por) REFERENCES usuarios (id)            ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_caja_montos   CHECK (monto > 0 AND monto_usd > 0 AND tasa_bs_por_usd > 0),
    CONSTRAINT ck_caja_origen   CHECK (
        (origen IN ('venta','anulacion_venta') AND venta_id IS NOT NULL)
        OR (origen = 'pago_productor' AND cierre_id IS NOT NULL)
        OR (origen = 'nomina' AND nomina_id IS NOT NULL)
        OR (origen = 'contra_asiento' AND revierte_id IS NOT NULL)
        OR origen = 'manual'
    )
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Inmutabilidad y bloqueo de semanas cerradas
-- ---------------------------------------------------------------------

DELIMITER $$

CREATE TRIGGER trg_auditoria_no_update BEFORE UPDATE ON auditoria FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria es inmutable: UPDATE no permitido';
END$$

CREATE TRIGGER trg_auditoria_no_delete BEFORE DELETE ON auditoria FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria es inmutable: DELETE no permitido';
END$$

CREATE TRIGGER trg_caja_no_update BEFORE UPDATE ON caja_movimientos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La caja es de solo insercion: registre un contra-asiento';
END$$

CREATE TRIGGER trg_caja_no_delete BEFORE DELETE ON caja_movimientos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La caja es de solo insercion: registre un contra-asiento';
END$$

CREATE TRIGGER trg_entregas_semana_abierta_ins BEFORE INSERT ON productores_entregas FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM productores_cierres c
        WHERE c.productor_id = NEW.productor_id
          AND NEW.fecha BETWEEN c.semana_inicio AND c.semana_fin
          AND c.estado <> 'anulada'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La semana de esta entrega ya esta cerrada';
    END IF;
END$$

-- Una entrega ligada a un cierre vigente no cambia sus datos económicos; solo se libera si el cierre se anula.
CREATE TRIGGER trg_entregas_semana_abierta_upd BEFORE UPDATE ON productores_entregas FOR EACH ROW
BEGIN
    IF OLD.cierre_id IS NOT NULL
       AND (SELECT estado FROM productores_cierres WHERE id = OLD.cierre_id) <> 'anulada'
       AND NOT (NEW.cierre_id <=> OLD.cierre_id AND NEW.litros = OLD.litros
                AND NEW.precio_litro_usd = OLD.precio_litro_usd AND NEW.fecha = OLD.fecha
                AND NEW.productor_id = OLD.productor_id AND NEW.traslado_usd = OLD.traslado_usd) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La entrega pertenece a una semana cerrada';
    END IF;
END$$

CREATE TRIGGER trg_entregas_semana_abierta_del BEFORE DELETE ON productores_entregas FOR EACH ROW
BEGIN
    IF OLD.cierre_id IS NOT NULL
       AND (SELECT estado FROM productores_cierres WHERE id = OLD.cierre_id) <> 'anulada' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La entrega pertenece a una semana cerrada';
    END IF;
END$$

DELIMITER ;
