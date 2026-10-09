-- ASERP - El Castillo del Queso
-- Esquema MySQL (XAMPP)
-- Crea la BD 'aserp' antes de importar.

SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `transporte_asignaciones`;
DROP TABLE IF EXISTS `resumen_costos_diarios`;
DROP TABLE IF EXISTS `proveedores_pagos`;
DROP TABLE IF EXISTS `proveedores_entregas`;
DROP TABLE IF EXISTS `productores_precios_historial`;
DROP TABLE IF EXISTS `productores_entregas`;
DROP TABLE IF EXISTS `productores_descuentos`;
DROP TABLE IF EXISTS `productores_cierres`;
DROP TABLE IF EXISTS `productores_abonos`;
DROP TABLE IF EXISTS `producciones_queso`;
DROP TABLE IF EXISTS `movimiento_caja`;
DROP TABLE IF EXISTS `empleados_nomina`;
DROP TABLE IF EXISTS `detalle_factura`;
DROP TABLE IF EXISTS `compras`;
DROP TABLE IF EXISTS `bitacora_sesiones`;
DROP TABLE IF EXISTS `auditoria_sistema`;
DROP TABLE IF EXISTS `tasa_cambio`;
DROP TABLE IF EXISTS `facturas`;
DROP TABLE IF EXISTS `lotes`;
DROP TABLE IF EXISTS `transporte`;
DROP TABLE IF EXISTS `vehiculos`;
DROP TABLE IF EXISTS `bancos`;
DROP TABLE IF EXISTS `productos`;
DROP TABLE IF EXISTS `proveedores`;
DROP TABLE IF EXISTS `productores`;
DROP TABLE IF EXISTS `empleados`;
DROP TABLE IF EXISTS `usuarios`;

CREATE TABLE `usuarios` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `empleado_id` INT,
  `nombre` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `rol` VARCHAR(50) NOT NULL DEFAULT 'empleado',
  `permisos_json` TEXT,
  `estado` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuarios_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `empleados` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `cargo` VARCHAR(100),
  `telefono` VARCHAR(50),
  `email` VARCHAR(100),
  `fecha_ingreso` DATE,
  `estado` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cedula` VARCHAR(40),
  `apellidos` VARCHAR(150),
  `fecha_salida` DATE,
  `salario_semanal` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `cuenta_propia` TINYINT(1) NOT NULL DEFAULT 0,
  `direccion` VARCHAR(255),
  `expediente_penal` TINYINT(1) NOT NULL DEFAULT 0,
  `fecha_nacimiento` DATE,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `productores` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(160) NOT NULL,
  `direccion` VARCHAR(255),
  `documento` VARCHAR(40),
  `telefono` VARCHAR(40),
  `correo` VARCHAR(150),
  `precio_litro_usd` DECIMAL(12,4) NOT NULL DEFAULT '0',
  `credito_dias` INT NOT NULL DEFAULT 7,
  `estado` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `proveedores` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `contacto` VARCHAR(100),
  `telefono` VARCHAR(50),
  `email` VARCHAR(100),
  `direccion` TEXT,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `precio_litro_usd` DECIMAL(12,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `productos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(80),
  `nombre` VARCHAR(150) NOT NULL,
  `descripcion` TEXT,
  `precio` DECIMAL(10,2) NOT NULL,
  `stock` DECIMAL(12,3) NOT NULL DEFAULT '0',
  `umbral_alerta` DECIMAL(12,3) NOT NULL DEFAULT '5',
  `moneda` VARCHAR(10) DEFAULT 'USD',
  `unidad_medida` VARCHAR(50) NOT NULL DEFAULT 'unidad',
  `presentacion` VARCHAR(120),
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `productos_codigo_unique` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bancos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `numero_cuenta` VARCHAR(50),
  `saldo` DECIMAL(10,2) DEFAULT '0',
  `estado` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vehiculos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nombre_vehiculo` VARCHAR(120) NOT NULL,
  `placa` VARCHAR(30),
  `capacidad` DECIMAL(10,2) DEFAULT 0,
  `estado` VARCHAR(30) NOT NULL DEFAULT 'disponible',
  `conductor_actual_id` INT,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transporte` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nombre_vehiculo` VARCHAR(100),
  `placa` VARCHAR(50),
  `capacidad` INT,
  `estado` TINYINT(1) NOT NULL DEFAULT 1,
  `conductor_actual_id` INT,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lotes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `producto_id` INT NOT NULL,
  `cantidad` DECIMAL(12,3) NOT NULL,
  `fecha_vencimiento` DATE,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `facturas` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `cliente` VARCHAR(255) NOT NULL,
  `total` DECIMAL(12,2) NOT NULL,
  `fecha` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `moneda` VARCHAR(3) NOT NULL DEFAULT 'BS',
  `tasa_cambio` DECIMAL(12,2),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tasa_cambio` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tasa` DECIMAL(10,2),
  `fecha` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auditoria_sistema` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `usuario_id` INT,
  `bitacora_sesion_id` INT,
  `modulo` VARCHAR(100) NOT NULL,
  `accion` VARCHAR(150) NOT NULL,
  `detalle_json` TEXT,
  `metodo_http` VARCHAR(10),
  `ruta` VARCHAR(255),
  `ip` VARCHAR(45),
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bitacora_sesiones` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `usuario_id` INT,
  `nombre_usuario` VARCHAR(150),
  `email` VARCHAR(150),
  `rol` VARCHAR(50),
  `session_token` VARCHAR(128) NOT NULL,
  `ip` VARCHAR(45),
  `user_agent` TEXT,
  `inicio_sesion` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fin_sesion` TIMESTAMP NULL,
  `ultima_actividad` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'activa',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `compras` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `proveedor_id` INT NOT NULL,
  `monto` DECIMAL(10,2) NOT NULL,
  `descripcion` TEXT,
  `fecha` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `moneda` VARCHAR(10) DEFAULT 'USD',
  `estado` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `detalle_factura` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `factura_id` INT NOT NULL,
  `producto_id` INT NOT NULL,
  `cantidad` DECIMAL(12,3) NOT NULL,
  `precio` DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `empleados_nomina` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `empleado_id` INT NOT NULL,
  `semana_inicio` DATE NOT NULL,
  `dias_no_trabajados` DECIMAL(4,1) NOT NULL DEFAULT 0,
  `pagos_extras` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `vales_mercancia` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `bodega_bs` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `empleados_nomina_empleado_id_semana_inicio_key` (`empleado_id`,`semana_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `movimiento_caja` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tipo` VARCHAR(20) NOT NULL,
  `monto` DECIMAL(10,2) NOT NULL,
  `descripcion` TEXT,
  `fecha` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `moneda` VARCHAR(10) DEFAULT 'USD',
  `estado` TINYINT(1) NOT NULL DEFAULT 1,
  `origen_tipo` VARCHAR(20) NOT NULL DEFAULT 'manual',
  `factura_id` INT,
  `compra_id` INT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `producciones_queso` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `fecha` DATE NOT NULL DEFAULT (CURDATE()),
  `tipo_queso` VARCHAR(80) NOT NULL DEFAULT 'Queso',
  `litros_leche` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `factor` DECIMAL(10,4) NOT NULL DEFAULT 8,
  `kg_esperado` DECIMAL(12,3),
  `kg_real` DECIMAL(12,3),
  `observaciones` TEXT,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `productores_abonos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `productor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `semana_aplicada` DATE NULL,
  `tipo` VARCHAR(60) NOT NULL DEFAULT 'efectivo',
  `monto_usd` DECIMAL(12,4) NOT NULL DEFAULT '0',
  `descripcion` VARCHAR(255),
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `productores_cierres` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `productor_id` INT NOT NULL,
  `semana_inicio` DATE NOT NULL,
  `semana_fin` DATE NOT NULL,
  `litros` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `precio_litro` DECIMAL(12,4) NOT NULL DEFAULT 0,
  `total_factura` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `abonos_semana` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `arrastre_anterior` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `monto_pagado` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'cerrada',
  `notas` TEXT,
  `cerrado_por` INT,
  `cerrado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pagado_en` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `productores_cierres_unq` (`productor_id`,`semana_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `productores_descuentos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `productor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `tipo` VARCHAR(60) NOT NULL DEFAULT 'mercancia',
  `descripcion` VARCHAR(255),
  `monto_usd` DECIMAL(12,4) NOT NULL DEFAULT '0',
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `productores_entregas` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `productor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `litros` DECIMAL(12,3) NOT NULL DEFAULT '0',
  `precio_litro_usd` DECIMAL(12,4) NOT NULL DEFAULT '0',
  `subtotal_usd` DECIMAL(12,4) NOT NULL DEFAULT '0',
  `gasto_traslado_usd` DECIMAL(12,4) NOT NULL DEFAULT '0',
  `cuajada_kg` DECIMAL(12,3) NOT NULL DEFAULT '0',
  `observaciones` TEXT,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `productores_precios_historial` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `productor_id` INT NOT NULL,
  `precio_anterior_usd` DECIMAL(12,4),
  `precio_nuevo_usd` DECIMAL(12,4) NOT NULL DEFAULT '0',
  `origen` VARCHAR(80) NOT NULL DEFAULT 'actualizacion',
  `observacion` VARCHAR(255),
  `usuario_id` INT,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `proveedores_entregas` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `proveedor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `descripcion` VARCHAR(200),
  `cantidad` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `precio_usd` DECIMAL(12,4) NOT NULL DEFAULT 0,
  `subtotal_usd` DECIMAL(12,4) NOT NULL DEFAULT 0,
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `proveedores_pagos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `proveedor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `tipo` VARCHAR(60) NOT NULL DEFAULT 'efectivo',
  `monto_usd` DECIMAL(12,4) NOT NULL DEFAULT 0,
  `descripcion` VARCHAR(255),
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `resumen_costos_diarios` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `fecha` DATE NOT NULL,
  `concepto` VARCHAR(40) NOT NULL,
  `monto` DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resumen_costos_diarios_fecha_concepto_key` (`fecha`,`concepto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transporte_asignaciones` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `transporte_id` INT NOT NULL,
  `empleado_id` INT NOT NULL,
  `fecha_asignacion` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_desasignacion` TIMESTAMP NULL,
  `observaciones` VARCHAR(255),
  `creado_en` TIMESTAMP NULL NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos de `usuarios` (3 filas)
INSERT INTO `usuarios` (`id`,`empleado_id`,`nombre`,`email`,`password`,`rol`,`permisos_json`,`estado`,`creado_en`) VALUES
(1,NULL,'Administrador','admin@aserp.com','$2y$10$SSj5ZcZWWi93bX7eMqnHFOH8LBb3aGMg2e2CSGRwJAGXeH/mzU0Xy','admin',NULL,1,'2026-05-03 16:37:31.630905'),
(7,NULL,'Yexon Ovalles','oyexon870@gmail.com','$2y$10$OW20VC/jEH8eiSNsWILfX.wi/z3vc2g3naa3VgteUMQkCQ/WIQsTC','admin','{"dashboard":true,"inventario":true,"lotes":true,"ventas":true,"empleados":true,"usuarios":true,"transporte":true,"proveedores":true,"productores":true,"finanzas":true,"bancos":true,"auditoria":true}',1,'2026-05-04 12:52:13.575725'),
(6,NULL,'Carlos Martines','Carlosmanel@gmail.com','$2y$10$pD.9lh4qN2uVuPg8E540UeHwCMl0Wa0UXjfQg6uS1ridRnWwz8FaW','empleado',NULL,1,'2026-05-03 20:20:05.852537');

-- Datos de `empleados` (44 filas)
INSERT INTO `empleados` (`id`,`nombre`,`cargo`,`telefono`,`email`,`fecha_ingreso`,`estado`,`creado_en`,`cedula`,`apellidos`,`fecha_salida`,`salario_semanal`,`cuenta_propia`,`direccion`,`expediente_penal`,`fecha_nacimiento`) VALUES
(1,'Carlos Manuel','ADMINISTRADOR','0414-2348058',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-28601335','Martinez Guillen',NULL,85.00,1,'CAJA DE AGUA',0,NULL),
(2,'Rafael Elias','JEFE PRO.','0424-3385892',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-15392317','Martinez Milano',NULL,85.00,1,'CAJA DE AGUA',0,NULL),
(3,'Cesar Miguel','JEFE PRO.','0412-0343145',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-31073670','Martinez Guillen',NULL,120.00,1,'CAJA DE AGUA',0,NULL),
(4,'Jose Miguel','CONTROL DE LECHE','0424-3390883',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-30039919','Utrera Hernandez',NULL,80.00,1,'',0,NULL),
(5,'Jose Hemeregildo','UTILITY','NO',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-8770170','Milano Lugo',NULL,30.00,0,'',0,NULL),
(6,'Geomar Hurberto','SUPERVISOR','0412-8927364',NULL,'2026-01-04',1,'2026-05-21 15:55:01.676921','V-31834731','Garcia',NULL,45.00,1,'CAJA DE AGUA',0,'2004-03-26'),
(7,'Francisco eduardo','PALETERO','0412-4539433',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-17720960','Rondon Hernandez',NULL,140.00,0,'',0,NULL),
(8,'Luis Angel','PALETERO','0412-7834318',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-20586689','Martinez Zolorzano',NULL,120.00,1,'COROCITO',0,'1989-10-22'),
(9,'Eduardo  Miguel','PALETERO','NO',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-21335465','Rosales Bolivar',NULL,120.00,1,'BICENTENARIO',1,NULL),
(10,'Danny Javier','LEVANTADOR','0412-8922722',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-21335388','Carpio',NULL,75.00,1,'CALLE EL CARMEN',0,'1991-07-26'),
(11,'Robert Rodolfo','LEVANTADOR','NO',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-26848836','Rodriguez Alvarado',NULL,75.00,1,'CALLE MELLADO',0,'1997-02-24'),
(12,'Jesus Alberto','CUAJADOR','0412-7760035',NULL,'2026-01-04',1,'2026-05-21 15:55:01.676921','V-15001802','Flores Bolivar',NULL,100.00,0,'',0,NULL),
(13,'Enmanuel','REMATADOR','0424-2606962',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-17752095','Zambrano Becerra',NULL,60.00,1,'CAJA DE AGUA',0,'1985-10-11'),
(14,'FRANAKILN DAVID','REMATADOR','0426-4408753',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-31835059','CASTRO SANCHEZ',NULL,75.00,1,'LOMAS DE L PARAISO',0,'2000-01-10'),
(15,'Rafael Arturo','REMATADOR','0424-3100407',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-19986812','Rodriguez',NULL,70.00,1,'cheguevara',0,'1988-07-27'),
(16,'Nelson Javier','REMATADOR','0412-4326590',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-16076321','Agüero',NULL,60.00,1,'CAJA DE AGUA',0,NULL),
(17,'Jose Luis','HERVIDOR','0412-8175263',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-31728947','Carpio Perez',NULL,85.00,1,'BRISA DE RIO',0,'2003-09-30'),
(18,'Elier Alenxander','PALETERO','0424-3572102',NULL,'2026-01-04',1,'2026-05-21 15:55:01.676921','V-24976774','Marin Rodriguez',NULL,125.00,0,'',0,NULL),
(19,'Cesar Antonio','LEVANTADOR','0412-7469463',NULL,'2025-01-03',1,'2026-05-21 15:55:01.676921','V-32544522','Medina Gonzales',NULL,70.00,0,'LOS ROSALES',0,'2005-11-11'),
(20,'Maiker Jesus','PALETERO','0424-1571673',NULL,'2025-01-03',1,'2026-05-21 15:55:01.676921','V-26848190','Marin Rodriguez',NULL,120.00,1,'cheguevara',0,NULL),
(21,'Geiber Alonzo','REMATADOR','0412-4538109',NULL,'2026-01-04',1,'2026-05-21 15:55:01.676921','V-31227262','Ramos Gil',NULL,60.00,1,'SABANETA',0,'2006-01-10'),
(22,'GENESIS','REMATADOR','NO',NULL,'2026-01-07',1,'2026-05-21 15:55:01.676921','V-14643903','SANCHEZ',NULL,60.00,0,'',0,NULL),
(23,'Josue  gregorio','UTILITY','0426-3627166',NULL,'2025-01-03',1,'2026-05-21 15:55:01.676921','V-9.587.150','Jordan Garcia',NULL,90.00,0,'',0,NULL),
(24,'CARLOS','PALETERO','NO',NULL,'2025-01-03',1,'2026-05-21 15:55:01.676921','V-20877591','Aparicio',NULL,120.00,0,'',0,NULL),
(25,'Jose Miguel','LEVANTADOR','0424-1758145',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-32948486','Carrillo mata',NULL,70.00,1,'los rosales',0,'2006-09-24'),
(26,'Julio Cesar','LEVANTADOR','0412-0125512',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-25931009','Paez Gonzales',NULL,70.00,1,'BICENTENARIO',0,'1997-04-07'),
(27,'Hector Enrrique','PALETERO','0424-3607784',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-31576635','Perez Landaeta',NULL,120.00,0,'',0,NULL),
(28,'Elian Javier','REMATADOR','0412-4524112',NULL,'2026-01-04',1,'2026-05-21 15:55:01.676921','V-27842471','Orozco Fragoza',NULL,60.00,1,'LA VICTORIA',0,'2001-01-30'),
(29,'YESMER ALMILCAR','REMATADOR','0424-2027209',NULL,'2026-01-04',1,'2026-05-21 15:55:01.676921','V-31227158','MUÑOZ GIL',NULL,60.00,0,'MANANTIAL',0,'2003-12-15'),
(30,'Eduardo  Miguel','Exprimidor ','0412-7360455',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-32939412','Colon diaz',NULL,60.00,1,'LA COVERA',0,NULL),
(31,'Juan Jose','Exprimidor ','0426-6158591',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-24475502','Rojas Amariscua',NULL,100.00,1,'BICENTENARIO',1,'1993-03-08'),
(32,'Yoandri Miguel','AYUDANTE HERVIDOR','0412-4529070',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-32922411','Requena Rodriguez',NULL,60.00,0,'BRISA DE RIO',0,NULL),
(33,'Carlos eduardo','REMATADOR','0424-2291459',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-16714035','Rodriguez Palma',NULL,60.00,1,'BICENTENARIO',0,'1981-03-08'),
(34,'Alain Jesus','REMATADOR','NO',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','36633973','Maluenga Velazquez',NULL,60.00,0,'LOS ROSALES',0,NULL),
(35,'Samil Alexander','AYUDANTE','0424-2860620',NULL,'2026-01-06',1,'2026-05-21 15:55:01.676921','32498771','Galvis Pinto',NULL,60.00,0,'BICENRENARIO 1',0,'2008-01-03'),
(36,'Yessimar del Valle','ayudante de mantenimiento','0424-3120139',NULL,'2026-01-05',1,'2026-05-21 15:55:01.676921','v-23058958','Roman Alvarado',NULL,45.00,1,'CAJA DE AGUA',0,'1993-04-16'),
(37,'Pedro alejandro','PISONERO','0412-4534950',NULL,'2026-01-04',1,'2026-05-21 15:55:01.676921','V-31073395','ascanio oliveros',NULL,60.00,1,'bicentenario2',0,'2003-04-11'),
(38,'Luis Rafael','AYUDANTE','0412-4529331',NULL,'2026-01-04',1,'2026-05-21 15:55:01.676921','v-32900614','Martinez Jiraldo',NULL,50.00,1,'campo alegre',0,'2007-09-06'),
(39,'Dailis Doriana','COCINERA','NO',NULL,'2026-01-05',1,'2026-05-21 15:55:01.676921','V-20090693','Torrealba Rojas',NULL,70.00,1,'calle el trinfo',0,'1979-05-19'),
(40,'Jhonny Dario','REMATADOR','0424-1302358',NULL,'2026-01-03',1,'2026-05-21 15:55:01.676921','V-23058463','Rodriguez Guaita',NULL,60.00,1,'casco central, calle mellado casa nro 15',0,'1994-01-03'),
(41,'Nelsito','AYUDANTE','',NULL,'2026-01-18',1,'2026-05-21 15:55:01.676921','V-36661465','Agüero Roman',NULL,55.00,0,'',0,NULL),
(42,'Estiben Rodriguez','AYUDANTE','0414-1460344',NULL,'2026-05-03',1,'2026-05-21 15:55:01.676921','V-23058499','Gabriel Garrido',NULL,65.00,0,'',0,NULL),
(43,'Yexon Jose','ADMINISTRADOR','0424-3814698',NULL,'2026-05-03',1,'2026-05-21 15:55:01.676921','V-30703203','Ovalles Salas',NULL,60.00,0,'',0,NULL),
(44,'Wilfre felipe','PISONERO','0416-2496371',NULL,'2026-05-03',1,'2026-05-21 15:55:01.676921','V-8736063','Valor Hernandez',NULL,70.00,0,'',0,NULL);

-- Datos de `productos` (6 filas)
INSERT INTO `productos` (`id`,`codigo`,`nombre`,`descripcion`,`precio`,`stock`,`umbral_alerta`,`moneda`,`unidad_medida`,`presentacion`,`creado_en`) VALUES
(2,'QP-001','Queso Paisa 500g',NULL,5.00,60.000,15.000,'USD','kg','Pieza 500 g','2026-05-03 16:37:31.057270'),
(4,'CF-001','Cuajada Fresca 1kg',NULL,6.00,27.000,10.000,'USD','kg','Bolsa 1 kg','2026-05-03 16:37:31.150935'),
(3,'QT-001','Queso Telita 250g',NULL,3.50,28.000,10.000,'USD','unidad','Bolsa 250 g','2026-05-03 16:37:31.105653'),
(6,'SL-001','Suero de Leche 1L',NULL,2.50,81.000,20.000,'USD','litro','Botella 1 L','2026-05-03 16:37:31.246585'),
(5,'MT-001','Mantequilla 250g',NULL,4.00,10.000,8.000,'USD','unidad','Tarro 250 g','2026-05-03 16:37:31.201163'),
(1,'QBD-001','Queso Blanco Duro 1kg',NULL,8.50,1.000,20.000,'USD','kg','Rueda de 1 kg','2026-05-03 16:37:31.001931');

-- Datos de `bancos` (3 filas)
INSERT INTO `bancos` (`id`,`nombre`,`numero_cuenta`,`saldo`,`estado`,`creado_en`) VALUES
(1,'Banco de Venezuela','0102-0000-00-0000000001',15000.00,1,'2026-05-03 16:37:31.906340'),
(2,'Banesco','0134-0000-00-0000000002',8500.00,1,'2026-05-03 16:37:31.949062'),
(3,'Provincial BBVA','0108-0000-00-0000000003',3200.00,1,'2026-05-03 16:37:31.992762');

-- Datos de `lotes` (1 filas)
INSERT INTO `lotes` (`id`,`producto_id`,`cantidad`,`fecha_vencimiento`,`creado_en`) VALUES
(1,5,150.000,'2026-05-30','2026-05-04 15:11:26.719275');

-- Datos de `facturas` (18 filas)
INSERT INTO `facturas` (`id`,`cliente`,`total`,`fecha`,`moneda`,`tasa_cambio`) VALUES
(1,'Supermercado El Éxito',110.00,'2026-05-03 16:37:32.531000','USD',42.85),
(2,'Restaurante La Montaña',52.00,'2026-05-03 16:37:32.588000','USD',42.85),
(3,'Distribuidora Andina',210.00,'2026-05-03 16:37:32.642000','USD',42.85),
(4,'yexon',5.00,'2026-05-03 17:14:24.831000','USD',42.85),
(5,'yexon',17.50,'2026-05-03 17:55:26.107000','USD',500.00),
(6,'kjjj',29.00,'2026-05-03 19:09:02.437000','BS',500.00),
(7,'carrlos',100.00,'2026-05-04 00:00:00','VES',500.00),
(8,'Yexon',132.00,'2026-05-04 00:00:00','VES',500.00),
(9,'Yexon',60.00,'2026-05-04 00:00:00','USD',500.00),
(10,'yexon',431.00,'2026-05-04 00:00:00','USD',520.00),
(11,'carlos m',25.00,'2026-05-05 00:00:00','USD',500.00),
(12,'pérsona',47.00,'2026-05-06 00:00:00','VES',500.00),
(13,'pérsona',47.00,'2026-05-06 00:00:00','VES',500.00),
(14,'yexon',12.50,'2026-05-07 00:00:00','VES',500.00),
(15,'ovalles',2.50,'2026-05-07 00:00:00','USD',500.00),
(16,'yexon',40.00,'2026-05-07 00:00:00','USD',500.00),
(17,'rosa',105.00,'2026-05-08 00:00:00','VES',500.00),
(18,'Distribuidora jc',433.50,'2026-05-08 00:00:00','USD',500.00);

-- Datos de `tasa_cambio` (3 filas)
INSERT INTO `tasa_cambio` (`id`,`tasa`,`fecha`) VALUES
(1,42.85,'2026-05-03 16:37:32.035280'),
(2,500.00,'2026-05-03 17:17:29.223622'),
(3,500.00,'2026-05-21 00:00:00');

-- Datos de `auditoria_sistema` (176 filas)
INSERT INTO `auditoria_sistema` (`id`,`usuario_id`,`bitacora_sesion_id`,`modulo`,`accion`,`detalle_json`,`metodo_http`,`ruta`,`ip`,`creado_en`) VALUES
(145,NULL,NULL,'auth','login_fallido','{"email":"oyexon870@gmail.com","razon":"inactivo"}','POST','/aserp-php/?p=login','190.89.30.77','2026-05-21 13:00:03.333057'),
(147,NULL,NULL,'auth','login_fallido','{"email":"oyexon870@gmail.com","razon":"inactivo"}','POST','/aserp-php/?p=login','190.89.30.77','2026-05-21 13:00:15.869267'),
(149,7,52,'auth','login_exitoso','{"usuario_id":7,"rol":"admin"}','POST','/aserp-php/?p=login','190.89.30.77','2026-05-21 13:02:10.292527'),
(152,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"7","monto":"100","tipo":"pm_trans","fecha":"2026-05-21","nota":"gfdsgffghfgfsgf"}','POST','/aserp-php/?p=productor_detalle&id=10&tab=cierres','190.89.30.77','2026-05-21 13:03:56.933054'),
(153,7,52,'productores','pago_cierre','{"cierre_id":7,"monto":100,"tipo":"pm_trans"}','POST','/aserp-php/?p=productor_detalle&id=10&tab=cierres','190.89.30.77','2026-05-21 13:03:56.996098'),
(155,7,52,'productores','del_abono','{"action":"del_abono","tab":"control","aid":"6"}','POST','/aserp-php/?p=productor_detalle&id=11&semana=2026-W21&tab=control','190.89.30.77','2026-05-21 13:08:43.231201'),
(157,7,52,'usuarios','save','{"action":"save","id":"6","nombre":"Carlos Martines","email":"Carlosmanel@gmail.com","rol":"empleado","estado":"activo"}','POST','/aserp-php/?p=usuarios&action=edit&id=6','190.89.30.77','2026-05-21 13:09:23.673318'),
(159,7,52,'productores','delete','{"action":"delete","id":"10","ent_tipo":"productor"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 13:11:13.274053'),
(161,7,52,'productores','save','{"action":"save","ent_tipo":"productor","id":"12","nombre":"YEXON OVALLES","documento":"","telefono":"","email":"","direccion":"","precio_litro_usd":"0.5","credito_dias":"0","estado":"activo"}','POST','/aserp-php/?p=productores&action=edit&id=12&ent_tipo=productor','190.89.30.77','2026-05-21 14:01:21.045717'),
(163,7,52,'productores','POST','{"ws":"2026-05-16","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal','190.89.30.77','2026-05-21 14:03:01.861778'),
(166,7,52,'productores','save_entrega','{"action":"save_entrega","fecha":"2026-05-20","precio":"0.5","litros":"100"}','POST','/aserp-php/?p=productor_detalle&id=13&semana=2026-W21&tab=control','190.89.30.77','2026-05-21 14:06:12.452607'),
(168,7,52,'productores','save_entrega','{"action":"save_entrega","fecha":"2026-05-25","precio":"0.5","litros":"100"}','POST','/aserp-php/?p=productor_detalle&id=13&semana=2026-W22&tab=control','190.89.30.77','2026-05-21 14:07:36.493811'),
(170,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=13&tab=cierres','190.89.30.77','2026-05-21 14:08:29.295221'),
(171,7,52,'productores','cerrar_semana_manual','{"productor_id":13,"semana_inicio":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=13&tab=cierres','190.89.30.77','2026-05-21 14:08:29.320588'),
(146,NULL,NULL,'auth','login_fallido','{"email":"oyexon870@gmail.com","razon":"inactivo"}','POST','/aserp-php/?p=login','190.89.30.77','2026-05-21 13:00:08.340396'),
(148,NULL,NULL,'auth','login_fallido','{"email":"oyexon870@gmail.com","razon":"inactivo"}','POST','/aserp-php/?p=login','190.89.30.77','2026-05-21 13:00:21.602963'),
(150,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=10&tab=cierres','190.89.30.77','2026-05-21 13:03:06.885528'),
(151,7,52,'productores','cerrar_semana_manual','{"productor_id":10,"semana_inicio":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=10&tab=cierres','190.89.30.77','2026-05-21 13:03:06.907687'),
(154,7,52,'productores','upd_abono','{"action":"upd_abono","aid":"6","tab":"historial","tipo":"pm_trans","monto":"1","fecha":"2026-05-21","descripcion":""}','POST','/aserp-php/?p=productor_detalle&id=11&tab=historial','190.89.30.77','2026-05-21 13:08:31.873372'),
(156,7,52,'usuarios','save','{"action":"save","id":"6","nombre":"Carlos Martines","email":"Carlosmanel@gmail.com","rol":"admin","estado":"inactivo"}','POST','/aserp-php/?p=usuarios&action=edit&id=6','190.89.30.77','2026-05-21 13:09:16.007707'),
(158,7,52,'productores','delete','{"action":"delete","id":"11","ent_tipo":"productor"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 13:11:10.656289'),
(160,7,52,'productores','save','{"action":"save","ent_tipo":"productor","id":"","nombre":"YEXON OVALLES","documento":"","telefono":"","email":"","direccion":"","precio_litro_usd":"0.0000","credito_dias":"0","estado":"activo"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 14:01:10.231054'),
(162,7,52,'productores','save','{"action":"save","ent_tipo":"productor","id":"","nombre":"DAVID","documento":"","telefono":"","email":"","direccion":"","precio_litro_usd":"0.5","credito_dias":"0","estado":"activo"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 14:01:36.315465'),
(164,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=13&tab=cierres','190.89.30.77','2026-05-21 14:05:48.905600'),
(165,7,52,'productores','cerrar_semana_manual','{"productor_id":13,"semana_inicio":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=13&tab=cierres','190.89.30.77','2026-05-21 14:05:49.046427'),
(167,7,52,'productores','save_entrega','{"action":"save_entrega","fecha":"2026-05-21","precio":"0.5","litros":"100"}','POST','/aserp-php/?p=productor_detalle&id=13&semana=2026-W21&tab=control','190.89.30.77','2026-05-21 14:06:55.136514'),
(169,7,52,'productores','save_entrega','{"action":"save_entrega","fecha":"2026-05-26","precio":"0.5","litros":"100"}','POST','/aserp-php/?p=productor_detalle&id=13&semana=2026-W22&tab=control','190.89.30.77','2026-05-21 14:07:43.246685'),
(172,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"8","monto":"245.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=13&tab=cierres','190.89.30.77','2026-05-21 14:08:47.452033'),
(173,7,52,'productores','pago_cierre','{"cierre_id":8,"monto":245,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=13&tab=cierres','190.89.30.77','2026-05-21 14:08:47.472916'),
(95,1,49,'auth','login_exitoso','{"usuario_id":1,"rol":"admin"}','POST','login','::1','2026-05-04 01:29:52.104874'),
(174,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"9","monto":"345.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=13&tab=cierres','190.89.30.77','2026-05-21 14:09:00.074604'),
(175,7,52,'productores','pago_cierre','{"cierre_id":9,"monto":345,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=13&tab=cierres','190.89.30.77','2026-05-21 14:09:00.093283'),
(176,7,52,'productores','del_entrega','{"action":"del_entrega","eid":"20"}','POST','/aserp-php/?p=productor_detalle&id=13&semana=2026-W21&tab=control','190.89.30.77','2026-05-21 14:09:22.196737'),
(177,7,52,'productores','delete','{"action":"delete","id":"13","ent_tipo":"productor"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 14:09:29.364965'),
(178,7,52,'productores','POST','{"ws":"2026-05-16","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-16','190.89.30.77','2026-05-21 14:11:36.207980'),
(179,7,52,'productores','POST','{"ws":"2026-05-23","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-23','190.89.30.77','2026-05-21 14:12:27.583217'),
(180,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:13:51.247535'),
(181,7,52,'productores','cerrar_semana_manual','{"productor_id":12,"semana_inicio":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:13:51.272789'),
(182,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"10","monto":"200","tipo":"pm_trans","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:14:04.041527'),
(183,7,52,'productores','pago_cierre','{"cierre_id":10,"monto":200,"tipo":"pm_trans"}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:14:04.059862'),
(184,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:14:14.342409'),
(185,7,52,'productores','cerrar_semana_manual','{"productor_id":12,"semana_inicio":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:14:14.360367'),
(186,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"11","monto":"725.00","tipo":"efectivo","fecha":"2026-05-21","nota":"JFJUJUY"}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:14:37.576177'),
(187,7,52,'productores','pago_cierre','{"cierre_id":11,"monto":725,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:14:37.629506'),
(188,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"10","monto":"375.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:15:03.394394'),
(189,7,52,'productores','pago_cierre','{"cierre_id":10,"monto":375,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:15:03.412480'),
(190,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"10","arrastre":"375","notas":""}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:15:18.335181'),
(191,7,52,'productores','editar_arrastre','{"cierre_id":10,"arrastre":375}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:15:18.350604'),
(192,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"10","arrastre":"375.00","notas":""}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:30:13.910142'),
(193,7,52,'productores','editar_arrastre','{"cierre_id":10,"arrastre":375}','POST','/aserp-php/?p=productor_detalle&id=12&tab=cierres','190.89.30.77','2026-05-21 14:30:14.223440'),
(194,7,52,'productores','delete','{"action":"delete","id":"12","ent_tipo":"productor"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 14:30:28.596054'),
(195,7,52,'productores','save','{"action":"save","ent_tipo":"productor","id":"","nombre":"JOSE APONTE","documento":"","telefono":"","email":"","direccion":"","precio_litro_usd":"1","credito_dias":"0","estado":"activo"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 14:30:46.778377'),
(196,7,52,'productores','POST','{"ws":"2026-05-16","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-16','190.89.30.77','2026-05-21 14:32:37.622624'),
(197,7,52,'productores','POST','{"ws":"2026-05-16","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-16','190.89.30.77','2026-05-21 14:32:38.342999'),
(198,7,52,'productores','POST','{"ws":"2026-05-16","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-16','190.89.30.77','2026-05-21 14:32:40.673424'),
(199,7,52,'productores','POST','{"ws":"2026-05-23","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-23','190.89.30.77','2026-05-21 14:32:55.821037'),
(200,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:33:38.144254'),
(201,7,52,'productores','cerrar_semana_manual','{"productor_id":14,"semana_inicio":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:33:38.161170'),
(202,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"12","arrastre":"540","notas":""}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:35:08.634820'),
(203,7,52,'productores','editar_arrastre','{"cierre_id":12,"arrastre":540}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:35:08.650415'),
(204,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:35:15.603970'),
(205,7,52,'productores','cerrar_semana_manual','{"productor_id":14,"semana_inicio":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:35:15.632387'),
(206,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"12","arrastre":"540.00","notas":""}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:35:31.745436'),
(207,7,52,'productores','editar_arrastre','{"cierre_id":12,"arrastre":540}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:35:31.925676'),
(208,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"12","arrastre":"540.00","notas":""}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:36:02.269132'),
(209,7,52,'productores','editar_arrastre','{"cierre_id":12,"arrastre":540}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:36:02.283368'),
(210,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"13","monto":"1843.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:36:13.928971'),
(211,7,52,'productores','pago_cierre','{"cierre_id":13,"monto":1843,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=14&tab=cierres','190.89.30.77','2026-05-21 14:36:13.955130'),
(212,7,52,'productores','delete','{"action":"delete","id":"14","ent_tipo":"productor"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 14:37:17.412763'),
(213,7,52,'productores','save','{"action":"save","ent_tipo":"productor","id":"","nombre":"Yexon Ovalles","documento":"","telefono":"","email":"","direccion":"","precio_litro_usd":"1","credito_dias":"0","estado":"activo"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 14:37:26.291378'),
(214,7,52,'productores','POST','{"ws":"2026-05-16","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal','190.89.30.77','2026-05-21 14:37:39.888107'),
(215,7,52,'productores','POST','{"ws":"2026-05-23","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-23','190.89.30.77','2026-05-21 14:37:52.879001'),
(216,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:38:35.313880'),
(217,7,52,'productores','cerrar_semana_manual','{"productor_id":15,"semana_inicio":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:38:35.341250'),
(218,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"14","arrastre":"610","notas":""}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:38:58.514232'),
(219,7,52,'productores','editar_arrastre','{"cierre_id":14,"arrastre":610}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:38:58.530454'),
(220,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:39:17.258654'),
(221,7,52,'productores','cerrar_semana_manual','{"productor_id":15,"semana_inicio":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:39:17.391655'),
(222,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"15","monto":"1000","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:40:12.558248'),
(223,7,52,'productores','pago_cierre','{"cierre_id":15,"monto":1000,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:40:12.578600'),
(224,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"15","monto":"200","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:40:32.640981'),
(225,7,52,'productores','pago_cierre','{"cierre_id":15,"monto":200,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:40:32.659960'),
(226,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"15","monto":"720.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:41:16.873080'),
(227,7,52,'productores','pago_cierre','{"cierre_id":15,"monto":720,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=15&tab=cierres','190.89.30.77','2026-05-21 14:41:16.901628'),
(228,7,52,'productores','delete','{"action":"delete","id":"15","ent_tipo":"productor"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 14:43:17.420875'),
(229,7,52,'productores','save','{"action":"save","ent_tipo":"productor","id":"","nombre":"Yexon Ovalles","documento":"","telefono":"","email":"","direccion":"","precio_litro_usd":"1","credito_dias":"0","estado":"activo"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 14:43:24.776805'),
(230,7,52,'productores','POST','{"ws":"2026-05-16","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal','190.89.30.77','2026-05-21 14:43:41.128873'),
(231,7,52,'productores','POST','{"ws":"2026-05-23","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-23','190.89.30.77','2026-05-21 14:44:05.791250'),
(232,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:44:52.940883'),
(233,7,52,'productores','cerrar_semana_manual','{"productor_id":16,"semana_inicio":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:44:52.961132'),
(234,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:45:06.788253'),
(235,7,52,'productores','cerrar_semana_manual','{"productor_id":16,"semana_inicio":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:45:06.815107'),
(236,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"17","monto":"500","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:45:30.867597'),
(237,7,52,'productores','pago_cierre','{"cierre_id":17,"monto":500,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:45:30.887531'),
(238,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"16","arrastre":"100","notas":"MOTO"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:45:51.933315'),
(239,7,52,'productores','editar_arrastre','{"cierre_id":16,"arrastre":100}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:45:51.991032'),
(240,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"16","arrastre":"100.00","notas":"MOTO"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:46:14.125215'),
(241,7,52,'productores','editar_arrastre','{"cierre_id":16,"arrastre":100}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:46:14.140371'),
(242,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"17","monto":"590.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:46:27.545428'),
(243,7,52,'productores','pago_cierre','{"cierre_id":17,"monto":590,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:46:27.567799'),
(244,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"17","arrastre":"100","notas":""}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:46:39.683283'),
(245,7,52,'productores','editar_arrastre','{"cierre_id":17,"arrastre":100}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:46:39.702991'),
(246,7,52,'productores','POST','{"ws":"2026-05-30","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-30','190.89.30.77','2026-05-21 14:47:06.719419'),
(247,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-30"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:47:23.103472'),
(248,7,52,'productores','cerrar_semana_manual','{"productor_id":16,"semana_inicio":"2026-05-30"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:47:23.121777'),
(249,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"16","arrastre":"720","notas":"MOTO"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:48:07.643591'),
(250,7,52,'productores','editar_arrastre','{"cierre_id":16,"arrastre":720}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:48:07.657379'),
(251,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"18","monto":"1440.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:48:15.062052'),
(252,7,52,'productores','pago_cierre','{"cierre_id":18,"monto":1440,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:48:15.122186'),
(253,7,52,'productores','editar_arrastre','{"action":"editar_arrastre","cid":"17","arrastre":"100.00","notas":""}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:48:30.676102'),
(254,7,52,'productores','editar_arrastre','{"cierre_id":17,"arrastre":100}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:48:30.695938'),
(255,7,52,'productores','POST','{"ws":"2026-06-06","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-06-06','190.89.30.77','2026-05-21 14:49:47.440842'),
(256,7,52,'productores','POST','{"ws":"2026-06-13","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-06-13','190.89.30.77','2026-05-21 14:50:01.465254'),
(257,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-06-13"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:50:50.764634'),
(258,7,52,'productores','cerrar_semana_manual','{"productor_id":16,"semana_inicio":"2026-06-13"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:50:50.782941'),
(259,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-06-06"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:57:29.802439'),
(260,7,52,'productores','cerrar_semana_manual','{"productor_id":16,"semana_inicio":"2026-06-06"}','POST','/aserp-php/?p=productor_detalle&id=16&tab=cierres','190.89.30.77','2026-05-21 14:57:29.993907'),
(261,7,52,'productores','delete','{"action":"delete","id":"16","ent_tipo":"productor"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 15:02:54.037873'),
(262,7,52,'productores','save','{"action":"save","ent_tipo":"productor","id":"","nombre":"Yexon Ovalles","documento":"","telefono":"","email":"","direccion":"","precio_litro_usd":"1","credito_dias":"0","estado":"activo"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 15:03:06.367718'),
(263,7,52,'productores','POST','{"ws":"2026-05-16","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal','190.89.30.77','2026-05-21 15:03:32.318439'),
(264,7,52,'productores','POST','{"ws":"2026-05-23","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-23','190.89.30.77','2026-05-21 15:03:45.125944'),
(265,7,52,'productores','POST','{"ws":"2026-05-23","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-23','190.89.30.77','2026-05-21 15:03:57.042121'),
(266,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:07:41.277185'),
(267,7,52,'productores','cerrar_semana_manual','{"productor_id":17,"semana_inicio":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:07:41.327428'),
(268,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:07:48.811327'),
(269,7,52,'productores','cerrar_semana_manual','{"productor_id":17,"semana_inicio":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:07:48.830502'),
(270,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"22","monto":"1000","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:08:01.494191'),
(271,7,52,'productores','pago_cierre','{"cierre_id":22,"monto":1000,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:08:01.511329'),
(272,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"22","monto":"330.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:08:10.383318'),
(273,7,52,'productores','pago_cierre','{"cierre_id":22,"monto":330,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:08:10.404242'),
(274,7,52,'productores','POST','{"ws":"2026-05-30","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-30','190.89.30.77','2026-05-21 15:08:39.887688'),
(275,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-30"}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:09:12.873076'),
(276,7,52,'productores','cerrar_semana_manual','{"productor_id":17,"semana_inicio":"2026-05-30"}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:09:12.926029'),
(277,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"23","monto":"1000","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:09:59.680583'),
(278,7,52,'productores','pago_cierre','{"cierre_id":23,"monto":1000,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=17&tab=cierres','190.89.30.77','2026-05-21 15:09:59.699045'),
(279,7,52,'productores','delete','{"action":"delete","id":"17","ent_tipo":"productor"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 15:14:55.448814'),
(280,7,52,'productores','save','{"action":"save","ent_tipo":"productor","id":"","nombre":"Yexon Ovalles","documento":"","telefono":"","email":"","direccion":"","precio_litro_usd":"1","credito_dias":"0","estado":"activo"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 15:15:05.645728'),
(281,7,52,'productores','POST','{"ws":"2026-05-16","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal','190.89.30.77','2026-05-21 15:15:32.018685'),
(282,7,52,'productores','POST','{"ws":"2026-05-23","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-23','190.89.30.77','2026-05-21 15:15:46.943594'),
(283,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:15:57.774688'),
(284,7,52,'productores','cerrar_semana_manual','{"productor_id":18,"semana_inicio":"2026-05-23"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:15:57.792578'),
(285,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:16:05.482239'),
(286,7,52,'productores','cerrar_semana_manual','{"productor_id":18,"semana_inicio":"2026-05-16"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:16:05.498966'),
(287,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"24","monto":"600.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:16:18.902095'),
(288,7,52,'productores','pago_cierre','{"cierre_id":24,"monto":600,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:16:18.927319'),
(289,7,52,'productores','POST','{"ws":"2026-05-30","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-05-30','190.89.30.77','2026-05-21 15:16:48.320008'),
(290,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-05-30"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:17:00.328357'),
(291,7,52,'productores','cerrar_semana_manual','{"productor_id":18,"semana_inicio":"2026-05-30"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:17:00.346766'),
(292,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"26","monto":"800","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:17:19.063477'),
(293,7,52,'productores','pago_cierre','{"cierre_id":26,"monto":800,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:17:19.095476'),
(294,7,52,'productores','POST','{"ws":"2026-06-06","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-06-06','190.89.30.77','2026-05-21 15:19:42.284498'),
(295,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-06-06"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:20:14.501238'),
(296,7,52,'productores','cerrar_semana_manual','{"productor_id":18,"semana_inicio":"2026-06-06"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:20:14.550120'),
(297,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"27","monto":"1200.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:26:33.422135'),
(298,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"27","monto":"700.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:26:45.608085'),
(299,7,52,'productores','pago_cierre','{"cierre_id":27,"monto":700,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:26:45.638843'),
(300,7,52,'productores','POST','{"ws":"2026-06-13","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-06-13','190.89.30.77','2026-05-21 15:27:39.356169'),
(301,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-06-13"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:28:02.671986'),
(302,7,52,'productores','cerrar_semana_manual','{"productor_id":18,"semana_inicio":"2026-06-13"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:28:02.699380'),
(303,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"28","monto":"250","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:28:13.212517'),
(304,7,52,'productores','pago_cierre','{"cierre_id":28,"monto":250,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:28:13.243921'),
(305,7,52,'productores','POST','{"ws":"2026-06-20","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-06-20','190.89.30.77','2026-05-21 15:28:49.119948'),
(306,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-06-20"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:29:15.087996'),
(307,7,52,'productores','cerrar_semana_manual','{"productor_id":18,"semana_inicio":"2026-06-20"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:29:15.118795'),
(308,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"29","monto":"1000","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:29:28.671985'),
(309,7,52,'productores','pago_cierre','{"cierre_id":29,"monto":1000,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:29:28.708301'),
(310,7,52,'productores','POST','{"ws":"2026-06-27","precio":"[array]","lit":"[array]","merc":"[array]","pm":"[array]","efec":"[array]","costo":"[array]"}','POST','/aserp-php/?p=control_semanal&ws=2026-06-27','190.89.30.77','2026-05-21 15:30:04.802971'),
(311,7,52,'productores','cerrar_semana_actual','{"action":"cerrar_semana_actual","ws":"2026-06-27"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:30:40.790395'),
(312,7,52,'productores','cerrar_semana_manual','{"productor_id":18,"semana_inicio":"2026-06-27"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:30:40.920416'),
(313,7,52,'productores','pagar_cierre','{"action":"pagar_cierre","cid":"30","monto":"940.00","tipo":"efectivo","fecha":"2026-05-21","nota":""}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:31:06.206570'),
(314,7,52,'productores','pago_cierre','{"cierre_id":30,"monto":940,"tipo":"efectivo"}','POST','/aserp-php/?p=productor_detalle&id=18&tab=cierres','190.89.30.77','2026-05-21 15:31:06.276446'),
(315,7,52,'productores','delete','{"action":"delete","id":"18","ent_tipo":"productor"}','POST','/aserp-php/?p=productores','190.89.30.77','2026-05-21 15:35:34.397440'),
(316,7,53,'auth','login_exitoso','{"usuario_id":7,"rol":"admin"}','POST','/aserp-php/?p=login','127.0.0.1','2026-05-21 15:56:49.213106'),
(317,7,52,'empleados','save_nomina','{"action":"save_nomina","semana":"2026-05-17","nom":"[array]"}','POST','/aserp-php/?p=empleados','190.89.30.77','2026-05-21 15:58:13.995364'),
(318,7,52,'empleados','save_nomina','{"action":"save_nomina","semana":"2026-05-17","nom":"[array]"}','POST','/aserp-php/?p=empleados&semana=2026-05-17','190.89.30.77','2026-05-21 15:58:55.091482'),
(319,7,52,'empleados','save_nomina','{"action":"save_nomina","semana":"2026-05-17","nom":"[array]"}','POST','/aserp-php/?p=empleados&semana=2026-05-17','190.89.30.77','2026-05-21 15:59:31.416217');

-- Datos de `bitacora_sesiones` (53 filas)
INSERT INTO `bitacora_sesiones` (`id`,`usuario_id`,`nombre_usuario`,`email`,`rol`,`session_token`,`ip`,`user_agent`,`inicio_sesion`,`fin_sesion`,`ultima_actividad`,`estado`) VALUES
(1,1,'Administrador','admin@aserp.com','admin','ced509ec09ad9f0f61e83337e6eef4db','::ffff:127.0.0.1','curl/8.14.1','2026-05-03 16:48:43.365030',NULL,'2026-05-03 16:48:43.365030','activa'),
(2,1,'Administrador','admin@aserp.com','admin','37ba98257c39a2b9375a4cdc7cabc3bd','::ffff:127.0.0.1','curl/8.14.1','2026-05-03 16:48:48.772479',NULL,'2026-05-03 16:48:48.772479','activa'),
(3,1,'Administrador','admin@aserp.com','admin','78b3d2cb061aec6f37639884c0ef55ce','::ffff:127.0.0.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36','2026-05-03 16:49:39.755395','2026-05-03 16:50:16.002000','2026-05-03 16:49:39.755395','cerrada'),
(4,1,'Administrador','admin@aserp.com','admin','a25f7e129a7f32828fd670d9ae0e8693','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-05-03 17:09:52.353691','2026-05-03 17:12:43.448000','2026-05-03 17:09:52.353691','cerrada'),
(5,1,'Administrador','admin@aserp.com','admin','1dd45bbbfdf42e9b2d975b908886a4d3','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-05-03 17:12:51.570646','2026-05-03 17:36:09.895000','2026-05-03 17:12:51.570646','cerrada'),
(7,1,'Administrador','admin@aserp.com','admin','22c68eb50cf453e93b5e1c16217124e4','::ffff:127.0.0.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36','2026-05-03 17:44:57.968461','2026-05-03 17:46:29.085000','2026-05-03 17:44:57.968461','cerrada'),
(6,1,'Administrador','admin@aserp.com','admin','69853f7e21d69754d7a2a7ed26656b44','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-05-03 17:36:13.345657','2026-05-03 17:58:00.885000','2026-05-03 17:36:13.345657','cerrada'),
(8,1,'Administrador','admin@aserp.com','admin','e8294b89c1fc70948f1d00f58e8370b2','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-05-03 17:58:03.473143','2026-05-03 18:24:34.834000','2026-05-03 17:58:03.473143','cerrada'),
(9,1,'Administrador','admin@aserp.com','admin','0060d78c7d75551d2283bb14991e2abd','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-05-03 18:24:44.023645',NULL,'2026-05-03 18:24:44.023645','activa'),
(10,1,'Administrador','admin@aserp.com','admin','3d1378ae234eda5b446088b1cdfeca98','::ffff:127.0.0.1','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36','2026-05-03 18:31:03.610912',NULL,'2026-05-03 18:31:03.610912','activa'),
(13,1,'Administrador','admin@aserp.com','admin','43ed6225c9165ea410ea94d7fc4342d3','::1','curl/8.14.1','2026-05-03 18:53:40.791777',NULL,'2026-05-03 18:53:40.791777','activa'),
(14,1,'Administrador','admin@aserp.com','admin','93ef63a4e7f39e06d2c2e2b681b99876','::1',NULL,'2026-05-03 18:54:00.763449',NULL,'2026-05-03 18:54:00.763449','activa'),
(12,3,'Yexon Ovalles','oyexon870@gmail.com','admin','77e134ae1126f062e0b188c4245e3c86','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-05-03 18:39:47.232878','2026-05-03 18:54:22.641000','2026-05-03 18:39:47.232878','cerrada'),
(16,3,'Yexon Ovalles','oyexon870@gmail.com','admin','aa1a4bfa3c2add807f3aeabf958a36f9','::ffff:127.0.0.1','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36','2026-05-03 19:02:40.755154',NULL,'2026-05-03 19:02:40.755154','activa'),
(17,1,'Administrador','admin@aserp.com','admin','36dd67ad02171eea7cad7046a7e65e69','::1',NULL,'2026-05-03 19:02:45.401212',NULL,'2026-05-03 19:02:45.401212','activa'),
(18,1,'Administrador','admin@aserp.com','admin','a426818c1bb78840850970905c5b5095','::1',NULL,'2026-05-03 19:03:11.285534',NULL,'2026-05-03 19:03:11.285534','activa'),
(19,1,'Administrador','admin@aserp.com','admin','110cea44dbf6f2bd24f681f7ee6dd60d','::1',NULL,'2026-05-03 19:03:25.216835',NULL,'2026-05-03 19:03:25.216835','activa'),
(20,1,'Administrador','admin@aserp.com','admin','42669f5613c2e3ab53534272f305318a','::1',NULL,'2026-05-03 19:03:52.726791',NULL,'2026-05-03 19:03:52.726791','activa'),
(21,1,'Administrador','admin@aserp.com','admin','1c0a285971a61de6694fe464c3cf660d','::1',NULL,'2026-05-03 19:04:22.418317',NULL,'2026-05-03 19:04:22.418317','activa'),
(22,1,'Administrador','admin@aserp.com','admin','f85d4673a64223094edb8469f8acf68b','::1',NULL,'2026-05-03 19:08:05.445087',NULL,'2026-05-03 19:08:05.445087','activa'),
(23,1,'Administrador','admin@aserp.com','admin','66db66e193c80214f63d9fa6d3fc7080','::1',NULL,'2026-05-03 19:08:51.336136',NULL,'2026-05-03 19:08:51.336136','activa'),
(24,1,'Administrador','admin@aserp.com','admin','9e875875fc7ca6c00d2931b917c2286c','::1',NULL,'2026-05-03 19:09:32.350283',NULL,'2026-05-03 19:09:32.350283','activa'),
(25,1,'Administrador','admin@aserp.com','admin','1ffce109e776ca99d054c43ba2e7e668','::1',NULL,'2026-05-03 19:09:35.247197',NULL,'2026-05-03 19:09:35.247197','activa'),
(15,4,'gggg','oyexon@gmail.com','empleado','b5dac0610515bfe1be2e0d22e7114631','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-05-03 18:55:05.974861','2026-05-03 19:09:51.586000','2026-05-03 18:55:05.974861','cerrada'),
(26,4,'gggg','oyexon@gmail.com','empleado','a6ab9f3db851d232528df4c9cb5a3604','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-05-03 19:10:18.392145','2026-05-03 19:15:47.667000','2026-05-03 19:10:18.392145','cerrada'),
(27,1,'Administrador','admin@aserp.com','admin','b033dd6508169f34021fee95db4b741f','::1',NULL,'2026-05-03 19:18:05.011448',NULL,'2026-05-03 19:18:05.011448','activa'),
(28,4,'gggg','oyexon@gmail.com','empleado','ede1bdbcea2fc9a3fa407a3c479f8d7e','::1',NULL,'2026-05-03 19:18:10.142043',NULL,'2026-05-03 19:18:10.142043','activa'),
(30,1,'Administrador','admin@aserp.com','admin','99616ba2ca360ef6b96bd6b36cae6923','::1',NULL,'2026-05-03 19:27:21.157171',NULL,'2026-05-03 19:27:21.157171','activa'),
(31,1,'Administrador','admin@aserp.com','admin','b098bfc6c9401db82819feb60d8a6011','::1',NULL,'2026-05-03 19:27:30.866780',NULL,'2026-05-03 19:27:30.866780','activa'),
(32,2,'Empleado Demo','empleado@aserp.com','empleado','e18a265ebf07e4bba81e3a2dbe0fe33f','::1',NULL,'2026-05-03 19:27:33.267157',NULL,'2026-05-03 19:27:33.267157','activa'),
(33,4,'gggg','oyexon@gmail.com','empleado','17b244f59851e5c69d609441447fd969','::1',NULL,'2026-05-03 19:29:48.114313',NULL,'2026-05-03 19:29:48.114313','activa'),
(34,4,'gggg','oyexon@gmail.com','empleado','0a0fe4d602a3df24bc67ac7f5face240','::1','curl/8.14.1','2026-05-03 19:29:49.982906',NULL,'2026-05-03 19:29:49.982906','activa'),
(35,4,'gggg','oyexon@gmail.com','empleado','b2f735b3bffa677da6c70858488fccff','::1',NULL,'2026-05-03 19:30:39.729859',NULL,'2026-05-03 19:30:39.729859','activa'),
(29,4,'gggg','oyexon@gmail.com','empleado','023a08e6ccb910ade9994403527a182e','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-05-03 19:19:44.339965','2026-05-03 19:32:49.778000','2026-05-03 19:19:44.339965','cerrada'),
(37,4,'gggg','oyexon@gmail.com','empleado','53c71eb74d2e8fb6d928651cf188bc5b','::1',NULL,'2026-05-03 19:37:03.589768',NULL,'2026-05-03 19:37:03.589768','activa'),
(38,1,'Administrador','admin@aserp.com','admin','50beb49ba340039d00b2779a01a40f43','::1',NULL,'2026-05-03 19:37:05.340650',NULL,'2026-05-03 19:37:05.340650','activa'),
(39,1,'Administrador','admin@aserp.com','admin','96ec04ccf7f3bca9fdc2edc767f49d2f','::1',NULL,'2026-05-03 19:44:01.097758',NULL,'2026-05-03 19:44:01.097758','activa'),
(40,4,'gggg','oyexon@gmail.com','empleado','f297d1bc717cc3df5e54287633e4e527','::1',NULL,'2026-05-03 19:44:03.929487',NULL,'2026-05-03 19:44:03.929487','activa'),
(41,1,'Administrador','admin@aserp.com','admin','d028e15ae1e3e6ad4b41a85da1e50962','::1',NULL,'2026-05-03 19:50:04.021491',NULL,'2026-05-03 19:50:04.021491','activa'),
(36,4,'gggg','oyexon@gmail.com','empleado','6377142e194f9cc194e2276f441ba0ef','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-05-03 19:33:17.285455','2026-05-03 19:53:12.977000','2026-05-03 19:33:17.285455','cerrada'),
(11,1,'Administrador','admin@aserp.com','admin','77b6c52fdfacf02023da0e1d2df0cca7','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-05-03 18:37:40.391649','2026-05-03 19:50:41.443000','2026-05-03 18:37:40.391649','cerrada'),
(42,1,'Administrador','admin@aserp.com','admin','1066d335f7ff4bafba6d096afce0b088','::1',NULL,'2026-05-03 19:50:59.793450',NULL,'2026-05-03 19:50:59.793450','activa'),
(43,1,'Administrador','admin@aserp.com','admin','9d83652d189bfef98a9e5f87f580800d','::ffff:127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-05-03 19:55:13.441449',NULL,'2026-05-03 19:55:13.441449','activa'),
(44,3,'Yexon Ovalles','oyexon870@gmail.com','empleado','daf368e5b5669499078353887dd747ac','::1',NULL,'2026-05-03 20:03:06.270599',NULL,'2026-05-03 20:03:06.270599','activa'),
(45,5,'yexon','oyexon870@gmail.com','empleado','d2b190b1b68cf95b885319a9f241d495','::1',NULL,'2026-05-03 20:09:03.199261',NULL,'2026-05-03 20:09:03.199261','activa'),
(46,1,'Administrador','admin@aserp.com','admin','4f3a9a4aec2a8addc9f792f93ecf6a2e','::1',NULL,'2026-05-03 20:11:15.848316',NULL,'2026-05-03 20:11:15.848316','activa'),
(47,1,'Administrador','admin@aserp.com','admin','15091dee53b9bca962f3ed3e055c8e2b','::1',NULL,'2026-05-03 20:12:58.795283',NULL,'2026-05-03 20:12:58.795283','activa'),
(48,6,'Carlos Martines','Carlosmanel@gmail.com','admin','2ccc1584ece0c50585d9eb70ccadb1f2','::1',NULL,'2026-05-03 20:21:32.330648',NULL,'2026-05-03 20:21:32.330648','activa'),
(49,1,'Administrador','admin@aserp.com','admin','69003c3a2e5dbad487d118b6983d385d','::1',NULL,'2026-05-04 01:29:51.759512',NULL,'2026-05-04 01:29:51.759512','activa'),
(50,1,'Administrador','admin@aserp.com','admin','adbb50d98523e279618ea5aa50cf0f90e9588336eb129d06e92f2f7ee18b613a','127.0.0.1','curl/8.14.1','2026-05-21 01:39:49.887360','2026-05-21 01:39:58.831118','2026-05-21 01:39:58.791870','cerrada'),
(51,1,'Administrador','admin@aserp.com','admin','4a7f2d1f3352d2c5995abd99a6b300985086cabc764e954f94263b04bd629e52','127.0.0.1','curl/8.14.1','2026-05-21 01:46:04.272388',NULL,'2026-05-21 01:46:17.400087','activa'),
(53,7,'Yexon Ovalles','oyexon870@gmail.com','admin','95ec3c980774707b139f2516e4475b4a0460e61e8f56b407f68251b0ddf476e7','127.0.0.1','curl/8.14.1','2026-05-21 15:56:49.178045',NULL,'2026-05-21 15:56:49.178045','activa'),
(52,7,'Yexon Ovalles','oyexon870@gmail.com','admin','acf1173f112cbf58a034b49d20a40d44a7d567b807f4986a45e9e75f88f922e5','190.89.30.77','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-05-21 13:02:10.255874',NULL,'2026-05-21 15:59:31.419840','activa');

-- Datos de `detalle_factura` (31 filas)
INSERT INTO `detalle_factura` (`id`,`factura_id`,`producto_id`,`cantidad`,`precio`) VALUES
(1,1,1,10.000,8.50),
(2,1,2,5.000,5.00),
(3,2,3,8.000,3.50),
(4,2,4,4.000,6.00),
(5,3,1,20.000,8.50),
(6,3,5,10.000,4.00),
(7,4,2,1.000,5.00),
(8,5,6,5.000,2.50),
(9,5,2,1.000,5.00),
(10,6,6,1.000,2.50),
(11,6,4,1.000,6.00),
(12,6,4,1.000,6.00),
(13,6,1,1.000,8.50),
(14,6,4,1.000,6.00),
(15,8,2,13.000,5.00),
(16,8,6,3.000,2.50),
(17,8,1,7.000,8.50),
(18,9,4,10.000,6.00),
(19,10,4,1.000,6.00),
(20,10,1,50.000,8.50),
(21,11,6,10.000,2.50),
(22,12,3,12.000,3.50),
(23,12,6,2.000,2.50),
(24,13,3,12.000,3.50),
(25,13,6,2.000,2.50),
(26,14,6,5.000,2.50),
(27,15,6,1.000,2.50),
(28,16,5,10.000,4.00),
(29,17,1,10.000,8.50),
(30,17,5,5.000,4.00),
(31,18,1,51.000,8.50);

-- Datos de `empleados_nomina` (88 filas)
INSERT INTO `empleados_nomina` (`id`,`empleado_id`,`semana_inicio`,`dias_no_trabajados`,`pagos_extras`,`vales_mercancia`,`bodega_bs`,`creado_en`) VALUES
(1,1,'2026-05-10',0.0,0.00,75.00,9.80,'2026-05-21 15:55:01.716345'),
(2,2,'2026-05-10',0.0,0.00,0.00,1.46,'2026-05-21 15:55:01.716345'),
(3,3,'2026-05-10',1.0,0.00,20.00,3.11,'2026-05-21 15:55:01.716345'),
(4,4,'2026-05-10',0.0,0.00,10.00,0.00,'2026-05-21 15:55:01.716345'),
(5,5,'2026-05-10',0.0,0.00,3.00,24.81,'2026-05-21 15:55:01.716345'),
(6,6,'2026-05-10',0.0,0.00,0.00,3.98,'2026-05-21 15:55:01.716345'),
(7,7,'2026-05-10',0.0,0.00,17.20,20.04,'2026-05-21 15:55:01.716345'),
(8,8,'2026-05-10',0.0,0.00,0.00,7.92,'2026-05-21 15:55:01.716345'),
(9,9,'2026-05-10',0.0,0.00,0.00,4.66,'2026-05-21 15:55:01.716345'),
(10,10,'2026-05-10',0.0,0.00,0.00,8.58,'2026-05-21 15:55:01.716345'),
(11,11,'2026-05-10',0.0,0.00,0.00,19.41,'2026-05-21 15:55:01.716345'),
(12,12,'2026-05-10',0.0,0.00,0.00,1.55,'2026-05-21 15:55:01.716345'),
(13,13,'2026-05-10',0.0,0.00,4.20,15.84,'2026-05-21 15:55:01.716345'),
(14,14,'2026-05-10',0.0,0.00,0.00,2.81,'2026-05-21 15:55:01.716345'),
(15,15,'2026-05-10',0.0,0.00,0.00,1.55,'2026-05-21 15:55:01.716345'),
(16,16,'2026-05-10',0.0,0.00,0.00,0.00,'2026-05-21 15:55:01.716345'),
(17,17,'2026-05-10',0.0,0.00,10.00,8.95,'2026-05-21 15:55:01.716345'),
(18,18,'2026-05-10',0.0,0.00,0.00,2.23,'2026-05-21 15:55:01.716345'),
(19,19,'2026-05-10',0.0,0.00,0.00,11.84,'2026-05-21 15:55:01.716345'),
(20,20,'2026-05-10',0.0,0.00,0.00,3.11,'2026-05-21 15:55:01.716345'),
(21,21,'2026-05-10',0.0,0.00,0.00,11.30,'2026-05-21 15:55:01.716345'),
(22,22,'2026-05-10',0.0,0.00,0.00,1.55,'2026-05-21 15:55:01.716345'),
(23,23,'2026-05-10',0.0,0.00,0.00,0.00,'2026-05-21 15:55:01.716345'),
(24,24,'2026-05-10',0.0,0.00,0.00,31.36,'2026-05-21 15:55:01.716345'),
(25,25,'2026-05-10',1.0,0.00,0.00,0.87,'2026-05-21 15:55:01.716345'),
(26,26,'2026-05-10',0.0,0.00,0.00,24.25,'2026-05-21 15:55:01.716345'),
(27,27,'2026-05-10',0.0,0.00,0.00,1.55,'2026-05-21 15:55:01.716345'),
(28,28,'2026-05-10',0.0,0.00,0.00,12.37,'2026-05-21 15:55:01.716345'),
(29,29,'2026-05-10',0.0,0.00,0.00,19.89,'2026-05-21 15:55:01.716345'),
(30,30,'2026-05-10',0.0,0.00,0.00,2.23,'2026-05-21 15:55:01.716345'),
(31,31,'2026-05-10',0.0,0.00,0.00,0.00,'2026-05-21 15:55:01.716345'),
(32,32,'2026-05-10',0.0,0.00,2.00,0.00,'2026-05-21 15:55:01.716345'),
(33,33,'2026-05-10',0.0,0.00,0.00,26.59,'2026-05-21 15:55:01.716345'),
(34,34,'2026-05-10',0.0,0.00,0.00,0.87,'2026-05-21 15:55:01.716345'),
(35,35,'2026-05-10',0.0,0.00,0.00,1.55,'2026-05-21 15:55:01.716345'),
(36,36,'2026-05-10',0.0,0.00,0.00,1.55,'2026-05-21 15:55:01.716345'),
(37,37,'2026-05-10',0.0,0.00,0.00,15.60,'2026-05-21 15:55:01.716345'),
(38,38,'2026-05-10',0.0,0.00,0.00,1.55,'2026-05-21 15:55:01.716345'),
(39,39,'2026-05-10',0.0,0.00,0.00,0.00,'2026-05-21 15:55:01.716345'),
(40,40,'2026-05-10',0.0,0.00,0.00,3.11,'2026-05-21 15:55:01.716345'),
(41,41,'2026-05-10',0.0,0.00,0.00,1.55,'2026-05-21 15:55:01.716345'),
(42,42,'2026-05-10',1.0,0.00,0.00,0.00,'2026-05-21 15:55:01.716345'),
(43,43,'2026-05-10',0.0,0.00,20.00,0.00,'2026-05-21 15:55:01.716345'),
(44,44,'2026-05-10',0.0,0.00,0.00,14.26,'2026-05-21 15:55:01.716345'),
(57,13,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.037965'),
(58,14,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.039810'),
(59,15,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.042079'),
(60,16,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.044288'),
(61,17,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.046504'),
(62,18,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.048831'),
(63,19,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.051683'),
(64,20,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.053785'),
(65,21,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.057113'),
(66,22,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.060218'),
(67,23,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.062520'),
(68,24,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.065075'),
(84,40,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.107854'),
(85,41,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.111362'),
(86,42,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.113722'),
(87,43,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.116300'),
(88,44,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.118922'),
(45,1,'2026-05-17',1.0,0.00,7.50,0.00,'2026-05-21 15:58:14.005044'),
(46,2,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.009093'),
(47,3,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.011486'),
(48,4,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.014394'),
(49,5,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.017285'),
(50,6,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.020319'),
(51,7,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.022702'),
(52,8,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.025624'),
(53,9,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.027914'),
(54,10,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.030056'),
(55,11,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.032596'),
(56,12,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.034919'),
(69,25,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.068724'),
(70,26,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.070782'),
(71,27,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.073097'),
(72,28,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.075781'),
(73,29,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.078680'),
(74,30,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.081234'),
(75,31,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.083789'),
(76,32,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.086354'),
(77,33,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.089919'),
(78,34,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.091914'),
(79,35,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.094479'),
(80,36,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.096898'),
(81,37,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.099678'),
(82,38,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.102186'),
(83,39,'2026-05-17',0.0,0.00,0.00,0.00,'2026-05-21 15:58:14.104755');

-- Datos de `movimiento_caja` (11 filas)
INSERT INTO `movimiento_caja` (`id`,`tipo`,`monto`,`descripcion`,`fecha`,`moneda`,`estado`,`origen_tipo`,`factura_id`,`compra_id`) VALUES
(1,'ingreso',450.00,'Ventas semana 1 - quesos varios','2026-05-03 16:37:32.351903','USD',1,'manual',NULL,NULL),
(2,'egreso',120.00,'Compra insumos empaque','2026-05-03 16:37:32.394477','USD',1,'manual',NULL,NULL),
(3,'ingreso',18500.00,'Ventas en bolívares semana 1','2026-05-03 16:37:32.440679','BS',1,'manual',NULL,NULL),
(4,'egreso',5000.00,'Pago salarios semana 1','2026-05-03 16:37:32.491321','BS',1,'manual',NULL,NULL),
(5,'ingreso',110.00,'Ingreso automatico por venta factura #1 - Cliente: Supermercado El Éxito','2026-05-03 16:37:32.548605','USD',1,'venta',1,NULL),
(6,'ingreso',52.00,'Ingreso automatico por venta factura #2 - Cliente: Restaurante La Montaña','2026-05-03 16:37:32.602122','USD',1,'venta',2,NULL),
(7,'ingreso',210.00,'Ingreso automatico por venta factura #3 - Cliente: Distribuidora Andina','2026-05-03 16:37:32.658365','USD',1,'venta',3,NULL),
(8,'ingreso',5.00,'Ingreso automatico por venta factura #4 - Cliente: yexon','2026-05-03 17:14:24.870818','USD',1,'venta',4,NULL),
(9,'ingreso',17.50,'Ingreso automatico por venta factura #5 - Cliente: yexon','2026-05-03 17:55:26.167662','USD',1,'venta',5,NULL),
(10,'ingreso',29.00,'Ingreso automatico por venta factura #6 - Cliente: kjjj','2026-05-03 19:09:02.471008','BS',1,'venta',6,NULL),
(11,'ingreso',10000.00,'','2026-05-04 00:00:00','VES',1,'venta',NULL,NULL);

-- Datos de `producciones_queso` (2 filas)
INSERT INTO `producciones_queso` (`id`,`fecha`,`tipo_queso`,`litros_leche`,`factor`,`kg_esperado`,`kg_real`,`observaciones`,`creado_en`) VALUES
(1,'2026-05-05','Queso Blanco',512.200,9.0000,56.911,53.000,'','2026-05-05 18:38:08.368050'),
(2,'2026-05-05','Queso Blanco',512.200,9.0000,56.911,53.000,'','2026-05-05 18:38:41.758871');

-- Datos de `resumen_costos_diarios` (2 filas)
INSERT INTO `resumen_costos_diarios` (`id`,`fecha`,`concepto`,`monto`) VALUES
(1,'2026-05-09','ventas_prod',4.00),
(3,'2026-05-09','cortesias_n',40.00);

SET FOREIGN_KEY_CHECKS=1;