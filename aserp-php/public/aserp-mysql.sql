-- ASERP El Castillo del Queso
-- Base de datos compatible con MySQL / phpMyAdmin
-- Generado: 2026-05-12
-- ╔══════════════════════════════════════════════════════════════╗
-- ║  INSTRUCCIONES DE IMPORTACIÓN                               ║
-- ║  1. Abre phpMyAdmin → selecciona o crea la BD "aserp"       ║
-- ║  2. Pestaña Importar → selecciona este archivo → Continuar  ║
-- ║  ✔ Este script NO borra datos existentes.                   ║
-- ║    Usa CREATE TABLE IF NOT EXISTS + INSERT IGNORE.          ║
-- ║    Si quieres partir de cero, descomenta las líneas DROP.   ║
-- ╚══════════════════════════════════════════════════════════════╝

SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4;
-- SET time_zone='+00:00'; -- desactivado: XAMPP requiere tablas de zona horaria cargadas

-- ── Tabla: auditoria_sistema ──
-- DROP TABLE IF EXISTS `auditoria_sistema`;
CREATE TABLE IF NOT EXISTS `auditoria_sistema` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `usuario_id` INT NULL,
  `bitacora_sesion_id` INT NULL,
  `modulo` VARCHAR(100) NOT NULL,
  `accion` VARCHAR(150) NOT NULL,
  `detalle_json` TEXT NULL,
  `metodo_http` VARCHAR(10) NULL,
  `ruta` VARCHAR(255) NULL,
  `ip` VARCHAR(45) NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `auditoria_sistema` (`id`, `usuario_id`, `bitacora_sesion_id`, `modulo`, `accion`, `detalle_json`, `metodo_http`, `ruta`, `ip`, `creado_en`) VALUES
(95, 1, 49, 'auth', 'login_exitoso', '{\"usuario_id\":1,\"rol\":\"admin\"}', 'POST', 'login', '::1', '2026-05-04 01:29:52');

-- ── Tabla: bancos ──
-- DROP TABLE IF EXISTS `bancos`;
CREATE TABLE IF NOT EXISTS `bancos` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `numero_cuenta` VARCHAR(50) NULL,
  `saldo` DECIMAL(18,4) NULL,
  `estado` TINYINT(1) NOT NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `bancos` (`id`, `nombre`, `numero_cuenta`, `saldo`, `estado`, `creado_en`) VALUES
(1, 'Banco de Venezuela', '0102-0000-00-0000000001', '15000.00', 1, '2026-05-03 16:37:31'),
(2, 'Banesco', '0134-0000-00-0000000002', '8500.00', 1, '2026-05-03 16:37:31'),
(3, 'Provincial BBVA', '0108-0000-00-0000000003', '3200.00', 1, '2026-05-03 16:37:31');

-- ── Tabla: bitacora_sesiones ──
-- DROP TABLE IF EXISTS `bitacora_sesiones`;
CREATE TABLE IF NOT EXISTS `bitacora_sesiones` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `usuario_id` INT NULL,
  `nombre_usuario` VARCHAR(150) NULL,
  `email` VARCHAR(150) NULL,
  `rol` VARCHAR(50) NULL,
  `session_token` VARCHAR(128) NOT NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `inicio_sesion` DATETIME NOT NULL,
  `fin_sesion` DATETIME NULL,
  `ultima_actividad` DATETIME NOT NULL,
  `estado` VARCHAR(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `bitacora_sesiones` (`id`, `usuario_id`, `nombre_usuario`, `email`, `rol`, `session_token`, `ip`, `user_agent`, `inicio_sesion`, `fin_sesion`, `ultima_actividad`, `estado`) VALUES
(1, 1, 'Administrador', 'admin@aserp.com', 'admin', 'ced509ec09ad9f0f61e83337e6eef4db', '::ffff:127.0.0.1', 'curl/8.14.1', '2026-05-03 16:48:43', NULL, '2026-05-03 16:48:43', 'activa'),
(2, 1, 'Administrador', 'admin@aserp.com', 'admin', '37ba98257c39a2b9375a4cdc7cabc3bd', '::ffff:127.0.0.1', 'curl/8.14.1', '2026-05-03 16:48:48', NULL, '2026-05-03 16:48:48', 'activa'),
(3, 1, 'Administrador', 'admin@aserp.com', 'admin', '78b3d2cb061aec6f37639884c0ef55ce', '::ffff:127.0.0.1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2026-05-03 16:49:39', '2026-05-03 16:50:16', '2026-05-03 16:49:39', 'cerrada'),
(4, 1, 'Administrador', 'admin@aserp.com', 'admin', 'a25f7e129a7f32828fd670d9ae0e8693', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-03 17:09:52', '2026-05-03 17:12:43', '2026-05-03 17:09:52', 'cerrada'),
(5, 1, 'Administrador', 'admin@aserp.com', 'admin', '1dd45bbbfdf42e9b2d975b908886a4d3', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-03 17:12:51', '2026-05-03 17:36:09', '2026-05-03 17:12:51', 'cerrada'),
(7, 1, 'Administrador', 'admin@aserp.com', 'admin', '22c68eb50cf453e93b5e1c16217124e4', '::ffff:127.0.0.1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2026-05-03 17:44:57', '2026-05-03 17:46:29', '2026-05-03 17:44:57', 'cerrada'),
(6, 1, 'Administrador', 'admin@aserp.com', 'admin', '69853f7e21d69754d7a2a7ed26656b44', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-03 17:36:13', '2026-05-03 17:58:00', '2026-05-03 17:36:13', 'cerrada'),
(8, 1, 'Administrador', 'admin@aserp.com', 'admin', 'e8294b89c1fc70948f1d00f58e8370b2', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-03 17:58:03', '2026-05-03 18:24:34', '2026-05-03 17:58:03', 'cerrada'),
(9, 1, 'Administrador', 'admin@aserp.com', 'admin', '0060d78c7d75551d2283bb14991e2abd', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-03 18:24:44', NULL, '2026-05-03 18:24:44', 'activa'),
(10, 1, 'Administrador', 'admin@aserp.com', 'admin', '3d1378ae234eda5b446088b1cdfeca98', '::ffff:127.0.0.1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2026-05-03 18:31:03', NULL, '2026-05-03 18:31:03', 'activa'),
(13, 1, 'Administrador', 'admin@aserp.com', 'admin', '43ed6225c9165ea410ea94d7fc4342d3', '::1', 'curl/8.14.1', '2026-05-03 18:53:40', NULL, '2026-05-03 18:53:40', 'activa'),
(14, 1, 'Administrador', 'admin@aserp.com', 'admin', '93ef63a4e7f39e06d2c2e2b681b99876', '::1', NULL, '2026-05-03 18:54:00', NULL, '2026-05-03 18:54:00', 'activa'),
(12, 3, 'Yexon Ovalles', 'oyexon870@gmail.com', 'admin', '77e134ae1126f062e0b188c4245e3c86', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-03 18:39:47', '2026-05-03 18:54:22', '2026-05-03 18:39:47', 'cerrada'),
(16, 3, 'Yexon Ovalles', 'oyexon870@gmail.com', 'admin', 'aa1a4bfa3c2add807f3aeabf958a36f9', '::ffff:127.0.0.1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-03 19:02:40', NULL, '2026-05-03 19:02:40', 'activa'),
(17, 1, 'Administrador', 'admin@aserp.com', 'admin', '36dd67ad02171eea7cad7046a7e65e69', '::1', NULL, '2026-05-03 19:02:45', NULL, '2026-05-03 19:02:45', 'activa'),
(18, 1, 'Administrador', 'admin@aserp.com', 'admin', 'a426818c1bb78840850970905c5b5095', '::1', NULL, '2026-05-03 19:03:11', NULL, '2026-05-03 19:03:11', 'activa'),
(19, 1, 'Administrador', 'admin@aserp.com', 'admin', '110cea44dbf6f2bd24f681f7ee6dd60d', '::1', NULL, '2026-05-03 19:03:25', NULL, '2026-05-03 19:03:25', 'activa'),
(20, 1, 'Administrador', 'admin@aserp.com', 'admin', '42669f5613c2e3ab53534272f305318a', '::1', NULL, '2026-05-03 19:03:52', NULL, '2026-05-03 19:03:52', 'activa'),
(21, 1, 'Administrador', 'admin@aserp.com', 'admin', '1c0a285971a61de6694fe464c3cf660d', '::1', NULL, '2026-05-03 19:04:22', NULL, '2026-05-03 19:04:22', 'activa'),
(22, 1, 'Administrador', 'admin@aserp.com', 'admin', 'f85d4673a64223094edb8469f8acf68b', '::1', NULL, '2026-05-03 19:08:05', NULL, '2026-05-03 19:08:05', 'activa'),
(23, 1, 'Administrador', 'admin@aserp.com', 'admin', '66db66e193c80214f63d9fa6d3fc7080', '::1', NULL, '2026-05-03 19:08:51', NULL, '2026-05-03 19:08:51', 'activa'),
(24, 1, 'Administrador', 'admin@aserp.com', 'admin', '9e875875fc7ca6c00d2931b917c2286c', '::1', NULL, '2026-05-03 19:09:32', NULL, '2026-05-03 19:09:32', 'activa'),
(25, 1, 'Administrador', 'admin@aserp.com', 'admin', '1ffce109e776ca99d054c43ba2e7e668', '::1', NULL, '2026-05-03 19:09:35', NULL, '2026-05-03 19:09:35', 'activa'),
(15, 4, 'gggg', 'oyexon@gmail.com', 'empleado', 'b5dac0610515bfe1be2e0d22e7114631', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-03 18:55:05', '2026-05-03 19:09:51', '2026-05-03 18:55:05', 'cerrada'),
(26, 4, 'gggg', 'oyexon@gmail.com', 'empleado', 'a6ab9f3db851d232528df4c9cb5a3604', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-03 19:10:18', '2026-05-03 19:15:47', '2026-05-03 19:10:18', 'cerrada'),
(27, 1, 'Administrador', 'admin@aserp.com', 'admin', 'b033dd6508169f34021fee95db4b741f', '::1', NULL, '2026-05-03 19:18:05', NULL, '2026-05-03 19:18:05', 'activa'),
(28, 4, 'gggg', 'oyexon@gmail.com', 'empleado', 'ede1bdbcea2fc9a3fa407a3c479f8d7e', '::1', NULL, '2026-05-03 19:18:10', NULL, '2026-05-03 19:18:10', 'activa'),
(30, 1, 'Administrador', 'admin@aserp.com', 'admin', '99616ba2ca360ef6b96bd6b36cae6923', '::1', NULL, '2026-05-03 19:27:21', NULL, '2026-05-03 19:27:21', 'activa'),
(31, 1, 'Administrador', 'admin@aserp.com', 'admin', 'b098bfc6c9401db82819feb60d8a6011', '::1', NULL, '2026-05-03 19:27:30', NULL, '2026-05-03 19:27:30', 'activa'),
(32, 2, 'Empleado Demo', 'empleado@aserp.com', 'empleado', 'e18a265ebf07e4bba81e3a2dbe0fe33f', '::1', NULL, '2026-05-03 19:27:33', NULL, '2026-05-03 19:27:33', 'activa'),
(33, 4, 'gggg', 'oyexon@gmail.com', 'empleado', '17b244f59851e5c69d609441447fd969', '::1', NULL, '2026-05-03 19:29:48', NULL, '2026-05-03 19:29:48', 'activa'),
(34, 4, 'gggg', 'oyexon@gmail.com', 'empleado', '0a0fe4d602a3df24bc67ac7f5face240', '::1', 'curl/8.14.1', '2026-05-03 19:29:49', NULL, '2026-05-03 19:29:49', 'activa'),
(35, 4, 'gggg', 'oyexon@gmail.com', 'empleado', 'b2f735b3bffa677da6c70858488fccff', '::1', NULL, '2026-05-03 19:30:39', NULL, '2026-05-03 19:30:39', 'activa'),
(29, 4, 'gggg', 'oyexon@gmail.com', 'empleado', '023a08e6ccb910ade9994403527a182e', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-03 19:19:44', '2026-05-03 19:32:49', '2026-05-03 19:19:44', 'cerrada'),
(37, 4, 'gggg', 'oyexon@gmail.com', 'empleado', '53c71eb74d2e8fb6d928651cf188bc5b', '::1', NULL, '2026-05-03 19:37:03', NULL, '2026-05-03 19:37:03', 'activa'),
(38, 1, 'Administrador', 'admin@aserp.com', 'admin', '50beb49ba340039d00b2779a01a40f43', '::1', NULL, '2026-05-03 19:37:05', NULL, '2026-05-03 19:37:05', 'activa'),
(39, 1, 'Administrador', 'admin@aserp.com', 'admin', '96ec04ccf7f3bca9fdc2edc767f49d2f', '::1', NULL, '2026-05-03 19:44:01', NULL, '2026-05-03 19:44:01', 'activa'),
(40, 4, 'gggg', 'oyexon@gmail.com', 'empleado', 'f297d1bc717cc3df5e54287633e4e527', '::1', NULL, '2026-05-03 19:44:03', NULL, '2026-05-03 19:44:03', 'activa'),
(41, 1, 'Administrador', 'admin@aserp.com', 'admin', 'd028e15ae1e3e6ad4b41a85da1e50962', '::1', NULL, '2026-05-03 19:50:04', NULL, '2026-05-03 19:50:04', 'activa'),
(36, 4, 'gggg', 'oyexon@gmail.com', 'empleado', '6377142e194f9cc194e2276f441ba0ef', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-03 19:33:17', '2026-05-03 19:53:12', '2026-05-03 19:33:17', 'cerrada'),
(11, 1, 'Administrador', 'admin@aserp.com', 'admin', '77b6c52fdfacf02023da0e1d2df0cca7', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-03 18:37:40', '2026-05-03 19:50:41', '2026-05-03 18:37:40', 'cerrada'),
(42, 1, 'Administrador', 'admin@aserp.com', 'admin', '1066d335f7ff4bafba6d096afce0b088', '::1', NULL, '2026-05-03 19:50:59', NULL, '2026-05-03 19:50:59', 'activa'),
(43, 1, 'Administrador', 'admin@aserp.com', 'admin', '9d83652d189bfef98a9e5f87f580800d', '::ffff:127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-03 19:55:13', NULL, '2026-05-03 19:55:13', 'activa'),
(44, 3, 'Yexon Ovalles', 'oyexon870@gmail.com', 'empleado', 'daf368e5b5669499078353887dd747ac', '::1', NULL, '2026-05-03 20:03:06', NULL, '2026-05-03 20:03:06', 'activa'),
(45, 5, 'yexon', 'oyexon870@gmail.com', 'empleado', 'd2b190b1b68cf95b885319a9f241d495', '::1', NULL, '2026-05-03 20:09:03', NULL, '2026-05-03 20:09:03', 'activa'),
(46, 1, 'Administrador', 'admin@aserp.com', 'admin', '4f3a9a4aec2a8addc9f792f93ecf6a2e', '::1', NULL, '2026-05-03 20:11:15', NULL, '2026-05-03 20:11:15', 'activa'),
(47, 1, 'Administrador', 'admin@aserp.com', 'admin', '15091dee53b9bca962f3ed3e055c8e2b', '::1', NULL, '2026-05-03 20:12:58', NULL, '2026-05-03 20:12:58', 'activa'),
(48, 6, 'Carlos Martines', 'Carlosmanel@gmail.com', 'admin', '2ccc1584ece0c50585d9eb70ccadb1f2', '::1', NULL, '2026-05-03 20:21:32', NULL, '2026-05-03 20:21:32', 'activa'),
(49, 1, 'Administrador', 'admin@aserp.com', 'admin', '69003c3a2e5dbad487d118b6983d385d', '::1', NULL, '2026-05-04 01:29:51', NULL, '2026-05-04 01:29:51', 'activa');

-- ── Tabla: compras ──
-- DROP TABLE IF EXISTS `compras`;
CREATE TABLE IF NOT EXISTS `compras` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `proveedor_id` INT NOT NULL,
  `monto` DECIMAL(18,4) NOT NULL,
  `descripcion` TEXT NULL,
  `fecha` DATETIME NOT NULL,
  `moneda` VARCHAR(10) NULL,
  `estado` TINYINT(1) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Tabla: detalle_factura ──
-- DROP TABLE IF EXISTS `detalle_factura`;
CREATE TABLE IF NOT EXISTS `detalle_factura` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `factura_id` INT NOT NULL,
  `producto_id` INT NOT NULL,
  `cantidad` DECIMAL(18,4) NOT NULL,
  `precio` DECIMAL(18,4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `detalle_factura` (`id`, `factura_id`, `producto_id`, `cantidad`, `precio`) VALUES
(1, 1, 1, '10.000', '8.50'),
(2, 1, 2, '5.000', '5.00'),
(3, 2, 3, '8.000', '3.50'),
(4, 2, 4, '4.000', '6.00'),
(5, 3, 1, '20.000', '8.50'),
(6, 3, 5, '10.000', '4.00'),
(7, 4, 2, '1.000', '5.00'),
(8, 5, 6, '5.000', '2.50'),
(9, 5, 2, '1.000', '5.00'),
(10, 6, 6, '1.000', '2.50'),
(11, 6, 4, '1.000', '6.00'),
(12, 6, 4, '1.000', '6.00'),
(13, 6, 1, '1.000', '8.50'),
(14, 6, 4, '1.000', '6.00'),
(15, 8, 2, '13.000', '5.00'),
(16, 8, 6, '3.000', '2.50'),
(17, 8, 1, '7.000', '8.50'),
(18, 9, 4, '10.000', '6.00'),
(19, 10, 4, '1.000', '6.00'),
(20, 10, 1, '50.000', '8.50');

-- ── Tabla: empleados ──
-- DROP TABLE IF EXISTS `empleados`;
CREATE TABLE IF NOT EXISTS `empleados` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `cargo` VARCHAR(100) NULL,
  `telefono` VARCHAR(50) NULL,
  `email` VARCHAR(100) NULL,
  `fecha_ingreso` DATE NULL,
  `estado` TINYINT(1) NOT NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `empleados` (`id`, `nombre`, `cargo`, `telefono`, `email`, `fecha_ingreso`, `estado`, `creado_en`) VALUES
(3, 'Pedro Rojas', 'Repartidor', '0412-9876543', NULL, '2022-05-01', 1, '2026-05-03 16:37:31'),
(4, 'Ana Pérez', 'Producción', '0424-1122334', NULL, '2021-07-20', 1, '2026-05-03 16:37:31'),
(1, 'Carlos Mora', 'Administrador', '0414-1234567', 'admin@castilloqueso.com', '2020-01-15', 1, '2026-05-03 16:37:31'),
(2, 'Maria González', 'Vendedora', '0416-7654321', 'mgonzalez@castilloqueso.com', '2021-03-10', 1, '2026-05-03 16:37:31');

-- ── Tabla: facturas ──
-- DROP TABLE IF EXISTS `facturas`;
CREATE TABLE IF NOT EXISTS `facturas` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `cliente` VARCHAR(255) NOT NULL,
  `total` DECIMAL(18,4) NOT NULL,
  `fecha` DATETIME NOT NULL,
  `moneda` VARCHAR(3) NOT NULL,
  `tasa_cambio` DECIMAL(18,4) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `facturas` (`id`, `cliente`, `total`, `fecha`, `moneda`, `tasa_cambio`) VALUES
(1, 'Supermercado El Éxito', '110.00', '2026-05-03 16:37:32', 'USD', '42.85'),
(2, 'Restaurante La Montaña', '52.00', '2026-05-03 16:37:32', 'USD', '42.85'),
(3, 'Distribuidora Andina', '210.00', '2026-05-03 16:37:32', 'USD', '42.85'),
(4, 'yexon', '5.00', '2026-05-03 17:14:24', 'USD', '42.85'),
(5, 'yexon', '17.50', '2026-05-03 17:55:26', 'USD', '500.00'),
(6, 'kjjj', '29.00', '2026-05-03 19:09:02', 'BS', '500.00'),
(7, 'carrlos', '100.00', '2026-05-04 00:00:00', 'VES', '500.00'),
(8, 'Yexon', '132.00', '2026-05-04 00:00:00', 'VES', '500.00'),
(9, 'Yexon', '60.00', '2026-05-04 00:00:00', 'USD', '500.00'),
(10, 'yexon', '431.00', '2026-05-04 00:00:00', 'USD', '520.00');

-- ── Tabla: lotes ──
-- DROP TABLE IF EXISTS `lotes`;
CREATE TABLE IF NOT EXISTS `lotes` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `producto_id` INT NOT NULL,
  `cantidad` DECIMAL(18,4) NOT NULL,
  `fecha_vencimiento` DATE NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `lotes` (`id`, `producto_id`, `cantidad`, `fecha_vencimiento`, `creado_en`) VALUES
(1, 5, '150.000', '2026-05-30', '2026-05-04 15:11:26');

-- ── Tabla: movimiento_caja ──
-- DROP TABLE IF EXISTS `movimiento_caja`;
CREATE TABLE IF NOT EXISTS `movimiento_caja` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `tipo` VARCHAR(20) NOT NULL,
  `monto` DECIMAL(18,4) NOT NULL,
  `descripcion` TEXT NULL,
  `fecha` DATETIME NOT NULL,
  `moneda` VARCHAR(10) NULL,
  `estado` TINYINT(1) NOT NULL,
  `origen_tipo` VARCHAR(20) NOT NULL,
  `factura_id` INT NULL,
  `compra_id` INT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `movimiento_caja` (`id`, `tipo`, `monto`, `descripcion`, `fecha`, `moneda`, `estado`, `origen_tipo`, `factura_id`, `compra_id`) VALUES
(1, 'ingreso', '450.00', 'Ventas semana 1 - quesos varios', '2026-05-03 16:37:32', 'USD', 1, 'manual', NULL, NULL),
(2, 'egreso', '120.00', 'Compra insumos empaque', '2026-05-03 16:37:32', 'USD', 1, 'manual', NULL, NULL),
(3, 'ingreso', '18500.00', 'Ventas en bolívares semana 1', '2026-05-03 16:37:32', 'BS', 1, 'manual', NULL, NULL),
(4, 'egreso', '5000.00', 'Pago salarios semana 1', '2026-05-03 16:37:32', 'BS', 1, 'manual', NULL, NULL),
(5, 'ingreso', '110.00', 'Ingreso automatico por venta factura #1 - Cliente: Supermercado El Éxito', '2026-05-03 16:37:32', 'USD', 1, 'venta', 1, NULL),
(6, 'ingreso', '52.00', 'Ingreso automatico por venta factura #2 - Cliente: Restaurante La Montaña', '2026-05-03 16:37:32', 'USD', 1, 'venta', 2, NULL),
(7, 'ingreso', '210.00', 'Ingreso automatico por venta factura #3 - Cliente: Distribuidora Andina', '2026-05-03 16:37:32', 'USD', 1, 'venta', 3, NULL),
(8, 'ingreso', '5.00', 'Ingreso automatico por venta factura #4 - Cliente: yexon', '2026-05-03 17:14:24', 'USD', 1, 'venta', 4, NULL),
(9, 'ingreso', '17.50', 'Ingreso automatico por venta factura #5 - Cliente: yexon', '2026-05-03 17:55:26', 'USD', 1, 'venta', 5, NULL),
(10, 'ingreso', '29.00', 'Ingreso automatico por venta factura #6 - Cliente: kjjj', '2026-05-03 19:09:02', 'BS', 1, 'venta', 6, NULL),
(11, 'ingreso', '10000.00', '', '2026-05-04 00:00:00', 'VES', 1, 'venta', NULL, NULL);

-- ── Tabla: producciones_queso ──
-- DROP TABLE IF EXISTS `producciones_queso`;
CREATE TABLE IF NOT EXISTS `producciones_queso` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `fecha` DATE NOT NULL,
  `tipo_queso` VARCHAR(100) NOT NULL DEFAULT 'Queso',
  `litros_leche` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `factor` DECIMAL(10,4) NOT NULL DEFAULT 8,
  `kg_esperado` DECIMAL(18,4) GENERATED ALWAYS AS (ROUND(`litros_leche` / NULLIF(`factor`,0), 3)) STORED,
  `kg_real` DECIMAL(18,4) NULL,
  `observaciones` TEXT NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Tabla: productores ──
-- DROP TABLE IF EXISTS `productores`;
CREATE TABLE IF NOT EXISTS `productores` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `nombre` VARCHAR(160) NOT NULL,
  `direccion` VARCHAR(255) NULL,
  `documento` VARCHAR(40) NULL,
  `telefono` VARCHAR(40) NULL,
  `correo` VARCHAR(150) NULL,
  `precio_litro_usd` DECIMAL(18,4) NOT NULL,
  `credito_dias` INT NOT NULL,
  `estado` TINYINT(1) NOT NULL,
  `creado_en` DATETIME NOT NULL,
  `actualizado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_productores_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `productores` (`id`, `nombre`, `direccion`, `documento`, `telefono`, `correo`, `precio_litro_usd`, `credito_dias`, `estado`, `creado_en`, `actualizado_en`) VALUES
(6, 'yexon ovalles 1', '', '30703203', '04243814698', 'oyexon870@gmail.com', '0.7500', 0, 1, '2026-05-04 15:01:14', '2026-05-04 15:01:14');

-- ── Tabla: productores_abonos ──
-- DROP TABLE IF EXISTS `productores_abonos`;
CREATE TABLE IF NOT EXISTS `productores_abonos` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `productor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `tipo` VARCHAR(60) NOT NULL,
  `monto_usd` DECIMAL(18,4) NOT NULL,
  `descripcion` VARCHAR(255) NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Tabla: productores_descuentos ──
-- DROP TABLE IF EXISTS `productores_descuentos`;
CREATE TABLE IF NOT EXISTS `productores_descuentos` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `productor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `tipo` VARCHAR(60) NOT NULL,
  `descripcion` VARCHAR(255) NULL,
  `monto_usd` DECIMAL(18,4) NOT NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Tabla: productores_entregas ──
-- DROP TABLE IF EXISTS `productores_entregas`;
CREATE TABLE IF NOT EXISTS `productores_entregas` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `productor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `litros` DECIMAL(18,4) NOT NULL,
  `precio_litro_usd` DECIMAL(18,4) NOT NULL,
  `subtotal_usd` DECIMAL(18,4) NOT NULL,
  `gasto_traslado_usd` DECIMAL(18,4) NOT NULL,
  `cuajada_kg` DECIMAL(18,4) NOT NULL,
  `observaciones` TEXT NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `productores_entregas` (`id`, `productor_id`, `fecha`, `litros`, `precio_litro_usd`, `subtotal_usd`, `gasto_traslado_usd`, `cuajada_kg`, `observaciones`, `creado_en`) VALUES
(1, 6, '2026-05-04', '120.200', '0.7500', '90.1500', '0.0000', '0.000', NULL, '2026-05-04 15:05:09');

-- ── Tabla: productores_precios_historial ──
-- DROP TABLE IF EXISTS `productores_precios_historial`;
CREATE TABLE IF NOT EXISTS `productores_precios_historial` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `productor_id` INT NOT NULL,
  `precio_anterior_usd` DECIMAL(18,4) NULL,
  `precio_nuevo_usd` DECIMAL(18,4) NOT NULL,
  `origen` VARCHAR(80) NOT NULL,
  `observacion` VARCHAR(255) NULL,
  `usuario_id` INT NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Tabla: productos ──
-- DROP TABLE IF EXISTS `productos`;
CREATE TABLE IF NOT EXISTS `productos` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `codigo` VARCHAR(80) NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `descripcion` TEXT NULL,
  `precio` DECIMAL(18,4) NOT NULL,
  `stock` DECIMAL(18,4) NOT NULL,
  `umbral_alerta` DECIMAL(18,4) NOT NULL,
  `moneda` VARCHAR(10) NULL,
  `unidad_medida` VARCHAR(50) NOT NULL,
  `presentacion` VARCHAR(120) NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `productos` (`id`, `codigo`, `nombre`, `descripcion`, `precio`, `stock`, `umbral_alerta`, `moneda`, `unidad_medida`, `presentacion`, `creado_en`) VALUES
(3, 'QT-001', 'Queso Telita 250g', NULL, '3.50', '52.000', '10.000', 'USD', 'unidad', 'Bolsa 250 g', '2026-05-03 16:37:31'),
(5, 'MT-001', 'Mantequilla 250g', NULL, '4.00', '25.000', '8.000', 'USD', 'unidad', 'Tarro 250 g', '2026-05-03 16:37:31'),
(2, 'QP-001', 'Queso Paisa 500g', NULL, '5.00', '60.000', '15.000', 'USD', 'kg', 'Pieza 500 g', '2026-05-03 16:37:31'),
(6, 'SL-001', 'Suero de Leche 1L', NULL, '2.50', '101.000', '20.000', 'USD', 'litro', 'Botella 1 L', '2026-05-03 16:37:31'),
(4, 'CF-001', 'Cuajada Fresca 1kg', NULL, '6.00', '27.000', '10.000', 'USD', 'kg', 'Bolsa 1 kg', '2026-05-03 16:37:31'),
(1, 'QBD-001', 'Queso Blanco Duro 1kg', NULL, '8.50', '62.000', '20.000', 'USD', 'kg', 'Rueda de 1 kg', '2026-05-03 16:37:31');

-- ── Tabla: proveedores ──
-- DROP TABLE IF EXISTS `proveedores`;
CREATE TABLE IF NOT EXISTS `proveedores` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `contacto` VARCHAR(100) NULL,
  `telefono` VARCHAR(50) NULL,
  `email` VARCHAR(100) NULL,
  `direccion` TEXT NULL,
  `creado_en` DATETIME NOT NULL,
  `precio_litro_usd` DECIMAL(18,4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `proveedores` (`id`, `nombre`, `contacto`, `telefono`, `email`, `direccion`, `creado_en`, `precio_litro_usd`) VALUES
(6, 'Yexon Ovalles', '', '04243814698', 'bitcambio870@gmail.com', '', '2026-05-04 13:41:46', '0.7500');

-- ── Tabla: proveedores_entregas ──
-- DROP TABLE IF EXISTS `proveedores_entregas`;
CREATE TABLE IF NOT EXISTS `proveedores_entregas` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `proveedor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `descripcion` VARCHAR(200) NULL,
  `cantidad` DECIMAL(18,4) NOT NULL,
  `precio_usd` DECIMAL(18,4) NOT NULL,
  `subtotal_usd` DECIMAL(18,4) NOT NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `proveedores_entregas` (`id`, `proveedor_id`, `fecha`, `descripcion`, `cantidad`, `precio_usd`, `subtotal_usd`, `creado_en`) VALUES
(3, 6, '2026-05-04', 'Entrega semanal', '120.150', '0.7500', '90.1125', '2026-05-04 13:42:19'),
(5, 6, '2026-05-04', 'Entrega semanal', '120.120', '0.7500', '90.0900', '2026-05-04 14:49:41');

-- ── Tabla: proveedores_pagos ──
-- DROP TABLE IF EXISTS `proveedores_pagos`;
CREATE TABLE IF NOT EXISTS `proveedores_pagos` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `proveedor_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `tipo` VARCHAR(60) NOT NULL,
  `monto_usd` DECIMAL(18,4) NOT NULL,
  `descripcion` VARCHAR(255) NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Tabla: tasa_cambio ──
-- DROP TABLE IF EXISTS `tasa_cambio`;
CREATE TABLE IF NOT EXISTS `tasa_cambio` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `tasa` DECIMAL(18,4) NULL,
  `fecha` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `tasa_cambio` (`id`, `tasa`, `fecha`) VALUES
(1, '42.85', '2026-05-03 16:37:32'),
(2, '500.00', '2026-05-03 17:17:29');

-- ── Tabla: transporte ──
-- DROP TABLE IF EXISTS `transporte`;
CREATE TABLE IF NOT EXISTS `transporte` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `nombre_vehiculo` VARCHAR(100) NULL,
  `placa` VARCHAR(50) NULL,
  `capacidad` INT NULL,
  `estado` TINYINT(1) NOT NULL,
  `conductor_actual_id` INT NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `transporte` (`id`, `nombre_vehiculo`, `placa`, `capacidad`, `estado`, `conductor_actual_id`, `creado_en`) VALUES
(1, 'Camioneta Toyota Hiace', 'ABC-123', 500, 1, NULL, '2026-05-03 16:37:32'),
(2, 'Furgoneta Ford Transit', 'XYZ-789', 800, 1, NULL, '2026-05-03 16:37:32'),
(3, 'Moto Honda CB150', 'M-456', 50, 1, NULL, '2026-05-03 16:37:32');

-- ── Tabla: transporte_asignaciones ──
-- DROP TABLE IF EXISTS `transporte_asignaciones`;
CREATE TABLE IF NOT EXISTS `transporte_asignaciones` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `transporte_id` INT NOT NULL,
  `empleado_id` INT NOT NULL,
  `fecha_asignacion` DATETIME NOT NULL,
  `fecha_desasignacion` DATETIME NULL,
  `observaciones` VARCHAR(255) NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Tabla: usuarios ──
-- DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `empleado_id` INT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `rol` VARCHAR(50) NOT NULL,
  `permisos_json` TEXT NULL,
  `estado` TINYINT(1) NOT NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `usuarios` (`id`, `empleado_id`, `nombre`, `email`, `password`, `rol`, `permisos_json`, `estado`, `creado_en`) VALUES
(7, NULL, 'Yexon Ovalles', 'oyexon870@gmail.com', '$2y$10$WWP5XahgqkTE5GoTgCp9mOW7GBsq81VMO4W.yp/hnuGATm67WAC1a', 'admin', '{\"dashboard\":true,\"inventario\":true,\"lotes\":true,\"ventas\":true,\"empleados\":true,\"usuarios\":true,\"transporte\":true,\"proveedores\":true,\"productores\":true,\"finanzas\":true,\"bancos\":true,\"auditoria\":true}', 1, '2026-05-04 12:52:13'),
(6, NULL, 'Carlos Martines', 'Carlosmanel@gmail.com', '$2y$10$pD.9lh4qN2uVuPg8E540UeHwCMl0Wa0UXjfQg6uS1ridRnWwz8FaW', 'admin', NULL, 1, '2026-05-03 20:20:05'),
(1, NULL, 'Administrador', 'admin@aserp.com', '$2y$10$zR1N4QFViJPPM89ZuugQZ.MLViynRrouYgMgIHcCzMLkQnOt0xrNC', 'admin', NULL, 1, '2026-05-03 16:37:31');

-- ── Tabla: vehiculos ──
-- DROP TABLE IF EXISTS `vehiculos`;
CREATE TABLE IF NOT EXISTS `vehiculos` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `nombre_vehiculo` VARCHAR(120) NOT NULL,
  `placa` VARCHAR(30) NULL,
  `capacidad` DECIMAL(18,4) NULL,
  `estado` VARCHAR(30) NOT NULL,
  `conductor_actual_id` INT NULL,
  `creado_en` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `vehiculos` (`id`, `nombre_vehiculo`, `placa`, `capacidad`, `estado`, `conductor_actual_id`, `creado_en`) VALUES
(1, 'moto', 'a55d5d5e', '150.00', 'en_ruta', 3, '2026-05-04 15:09:32');


-- ── Tabla: resumen_costos_diarios ──
-- DROP TABLE IF EXISTS `resumen_costos_diarios`;
CREATE TABLE IF NOT EXISTS `resumen_costos_diarios` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `fecha` DATE NOT NULL,
  `concepto` VARCHAR(40) NOT NULL,
  `monto` DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fecha_concepto` (`fecha`, `concepto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `resumen_costos_diarios` (`id`, `fecha`, `concepto`, `monto`) VALUES
(1, '2026-05-09', 'ventas_prod', '4.00'),
(3, '2026-05-09', 'cortesias_n', '40.00');

SET FOREIGN_KEY_CHECKS=1;