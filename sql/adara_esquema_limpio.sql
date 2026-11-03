-- =====================================================================
-- ADARA · Esquema limpio para empresas nuevas (SaaSManager::aprovisionar)
-- Generado: 2026-11-03 05:02:04 desde `u980302731_adarabd` con bin/generar_esquema_limpio.php
-- 66 tablas, sin datos de negocio. Catálogos de sistema: bancos_catalogo, gas_correlativos.
-- Sin USE ni CREATE DATABASE: se ejecuta sobre la base recién creada.
-- =====================================================================

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `almacenes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `orden` smallint(5) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_almacenes_nombre` (`nombre`),
  KEY `ix_almacenes_orden` (`activo`,`orden`,`id`),
  CONSTRAINT `ck_almacenes_nombre` CHECK (char_length(trim(`nombre`)) >= 2),
  CONSTRAINT `ck_almacenes_activo` CHECK (`activo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `api_idempotencia` (
  `clave` char(36) NOT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `endpoint` varchar(60) NOT NULL,
  `huella_peticion` char(64) NOT NULL,
  `codigo_http` smallint(5) unsigned NOT NULL,
  `respuesta_json` mediumtext NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`clave`),
  KEY `ix_idempotencia_creado` (`creado_en`),
  KEY `ix_idempotencia_usuario` (`usuario_id`,`creado_en`),
  CONSTRAINT `fk_idempotencia_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_idempotencia_clave` CHECK (`clave` regexp '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'),
  CONSTRAINT `ck_idempotencia_http` CHECK (`codigo_http` between 200 and 299),
  CONSTRAINT `ck_idempotencia_json` CHECK (json_valid(`respuesta_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auditoria` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `modulo` varchar(40) NOT NULL,
  `accion` varchar(40) NOT NULL,
  `entidad` varchar(40) DEFAULT NULL,
  `entidad_id` bigint(20) unsigned DEFAULT NULL,
  `detalle` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detalle`)),
  `ip` varchar(45) NOT NULL,
  `metodo` varchar(7) NOT NULL,
  `ruta` varchar(60) NOT NULL,
  `creado_en` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `ix_auditoria_fecha` (`creado_en`),
  KEY `ix_auditoria_usuario` (`usuario_id`,`creado_en`),
  KEY `ix_auditoria_modulo` (`modulo`,`creado_en`),
  KEY `ix_auditoria_accion` (`accion`,`creado_en`),
  CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_auditoria_no_update BEFORE UPDATE ON auditoria FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria es inmutable: UPDATE no permitido';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_auditoria_no_delete BEFORE DELETE ON auditoria FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria es inmutable: DELETE no permitido';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bancos_catalogo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre_banco` varchar(80) NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bancos_catalogo_nombre` (`nombre_banco`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bancos_cierres_diarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cuenta_id` int(10) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `saldo_inicio` decimal(18,2) NOT NULL,
  `total_ingresos` decimal(18,2) NOT NULL,
  `total_egresos` decimal(18,2) NOT NULL,
  `total_comisiones` decimal(18,2) NOT NULL,
  `saldo_final` decimal(18,2) NOT NULL,
  `estado` enum('cuadrado') NOT NULL DEFAULT 'cuadrado',
  `archivo` varchar(180) DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_banco_cierre` (`cuenta_id`,`fecha`),
  KEY `ix_banco_cierre_fecha` (`fecha`),
  KEY `fk_banco_cierre_usuario` (`registrado_por`),
  CONSTRAINT `fk_banco_cierre_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `bancos_cuentas` (`id`),
  CONSTRAINT `fk_banco_cierre_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_banco_cierre_formula` CHECK (`saldo_final` = `saldo_inicio` + `total_ingresos` - `total_egresos` - `total_comisiones`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bancos_cuentas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `alias_cuenta` varchar(80) NOT NULL,
  `nombre_banco` varchar(80) DEFAULT NULL,
  `numero_cuenta` varchar(30) DEFAULT NULL,
  `cedula` varchar(24) DEFAULT NULL,
  `telefono` varchar(24) DEFAULT NULL,
  `moneda` enum('BS','USD') NOT NULL DEFAULT 'BS',
  `saldo_actual_bs` decimal(18,2) NOT NULL DEFAULT 0.00,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bancos_alias` (`alias_cuenta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bancos_movimientos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cuenta_id` int(10) unsigned NOT NULL,
  `cierre_id` int(10) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `tipo` enum('ingreso','egreso') NOT NULL,
  `categoria` varchar(80) NOT NULL,
  `metodo_pago` varchar(40) DEFAULT NULL,
  `banco_origen_destino` varchar(80) DEFAULT NULL,
  `descripcion` varchar(255) NOT NULL DEFAULT '',
  `beneficiario` varchar(120) DEFAULT NULL,
  `entidad_relacionada` varchar(100) DEFAULT NULL,
  `referencia` varchar(40) DEFAULT NULL,
  `monto_bs` decimal(18,2) NOT NULL,
  `comision_bs` decimal(18,2) NOT NULL DEFAULT 0.00,
  `monto_usd` decimal(18,4) DEFAULT NULL,
  `tasa_usd_dia` decimal(18,6) NOT NULL,
  `hoja` varchar(40) DEFAULT NULL,
  `fila` int(10) unsigned DEFAULT NULL,
  `estado_auditoria` enum('conciliado','huerfano','no_aplica') NOT NULL DEFAULT 'no_aplica',
  `modulo_origen` varchar(50) DEFAULT NULL,
  `id_origen` bigint(20) unsigned DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_banco_mov_cuenta_fecha` (`cuenta_id`,`fecha`),
  KEY `ix_banco_mov_cierre` (`cierre_id`),
  KEY `fk_banco_mov_usuario` (`registrado_por`),
  KEY `ix_banco_mov_auditoria` (`estado_auditoria`,`fecha`),
  KEY `ix_banco_mov_origen` (`modulo_origen`,`id_origen`),
  CONSTRAINT `fk_banco_mov_cierre` FOREIGN KEY (`cierre_id`) REFERENCES `bancos_cierres_diarios` (`id`),
  CONSTRAINT `fk_banco_mov_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `bancos_cuentas` (`id`),
  CONSTRAINT `fk_banco_mov_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_banco_mov_monto` CHECK (`monto_bs` > 0),
  CONSTRAINT `ck_banco_mov_comision` CHECK (`comision_bs` >= 0),
  CONSTRAINT `ck_banco_mov_tasa` CHECK (`tasa_usd_dia` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_movimientos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `tipo` enum('ingreso','egreso') NOT NULL,
  `cuenta` enum('caja','banco') NOT NULL DEFAULT 'caja',
  `moneda` enum('USD','VES') NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `tasa_bs_por_usd` decimal(14,4) NOT NULL,
  `monto_usd` decimal(12,2) NOT NULL,
  `concepto` varchar(160) NOT NULL,
  `origen` enum('manual','venta','anulacion_venta','pago_productor','nomina','contra_asiento','pago_gas','reparacion_gas') NOT NULL,
  `venta_id` int(10) unsigned DEFAULT NULL,
  `cierre_id` int(10) unsigned DEFAULT NULL,
  `abono_id` bigint(20) unsigned DEFAULT NULL,
  `gas_pago_id` bigint(20) unsigned DEFAULT NULL,
  `gas_reparacion_id` int(10) unsigned DEFAULT NULL,
  `nomina_id` int(10) unsigned DEFAULT NULL,
  `revierte_id` bigint(20) unsigned DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_caja_revierte` (`revierte_id`),
  UNIQUE KEY `uq_caja_abono` (`abono_id`),
  UNIQUE KEY `uq_caja_gas_pago` (`gas_pago_id`),
  UNIQUE KEY `uq_caja_gas_reparacion` (`gas_reparacion_id`),
  KEY `ix_caja_fecha` (`fecha`),
  KEY `fk_caja_venta` (`venta_id`),
  KEY `fk_caja_cierre` (`cierre_id`),
  KEY `fk_caja_nomina` (`nomina_id`),
  KEY `fk_caja_usuario` (`registrado_por`),
  KEY `ix_caja_cuenta_moneda` (`cuenta`,`moneda`),
  KEY `ix_caja_tipo_fecha` (`tipo`,`fecha`),
  CONSTRAINT `fk_caja_abono` FOREIGN KEY (`abono_id`) REFERENCES `productores_abonos` (`id`),
  CONSTRAINT `fk_caja_cierre` FOREIGN KEY (`cierre_id`) REFERENCES `productores_cierres` (`id`),
  CONSTRAINT `fk_caja_gas_pago` FOREIGN KEY (`gas_pago_id`) REFERENCES `gas_pagos` (`id`),
  CONSTRAINT `fk_caja_gas_reparacion` FOREIGN KEY (`gas_reparacion_id`) REFERENCES `gas_reparaciones` (`id`),
  CONSTRAINT `fk_caja_nomina` FOREIGN KEY (`nomina_id`) REFERENCES `nomina_semanal` (`id`),
  CONSTRAINT `fk_caja_revierte` FOREIGN KEY (`revierte_id`) REFERENCES `caja_movimientos` (`id`),
  CONSTRAINT `fk_caja_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_caja_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `ck_caja_montos` CHECK (`monto` > 0 and `monto_usd` > 0 and `tasa_bs_por_usd` > 0),
  CONSTRAINT `ck_caja_origen` CHECK (`origen` in ('venta','anulacion_venta') and `venta_id` is not null or `origen` = 'pago_productor' and (`abono_id` is not null or `cierre_id` is not null) or `origen` = 'nomina' and `nomina_id` is not null or `origen` = 'contra_asiento' and `revierte_id` is not null or `origen` = 'pago_gas' and `gas_pago_id` is not null or `origen` = 'reparacion_gas' and `gas_reparacion_id` is not null or `origen` = 'manual')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_caja_no_update BEFORE UPDATE ON caja_movimientos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La caja es de solo insercion: registre un contra-asiento';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_caja_no_delete BEFORE DELETE ON caja_movimientos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La caja es de solo insercion: registre un contra-asiento';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cargos` (
  `nombre` varchar(60) NOT NULL,
  `cobertura_minima` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`nombre`),
  CONSTRAINT `ck_cargos_nombre` CHECK (char_length(trim(`nombre`)) >= 2 and `nombre` = ucase(`nombre`)),
  CONSTRAINT `ck_cargos_cobertura` CHECK (`cobertura_minima` between 1 and 20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clientes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `documento` varchar(12) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `direccion` varchar(160) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clientes_documento` (`documento`),
  CONSTRAINT `ck_clientes_documento` CHECK (`documento` is null or `documento` regexp '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
  CONSTRAINT `ck_clientes_activo` CHECK (`activo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cobranzas_terceros` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empleado_id` int(10) unsigned NOT NULL,
  `acreedor` varchar(80) NOT NULL,
  `concepto` varchar(120) NOT NULL,
  `monto_usd` decimal(12,2) NOT NULL,
  `moneda_origen` enum('USD','VES') NOT NULL DEFAULT 'USD',
  `monto_origen` decimal(14,2) NOT NULL,
  `tasa_bs_por_usd` decimal(14,4) DEFAULT NULL,
  `fecha` date NOT NULL,
  `estado` enum('pendiente','descontado','anulado') NOT NULL DEFAULT 'pendiente',
  `semana_descuento` date DEFAULT NULL,
  `descontado_en` datetime DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `actualizado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_cobranzas_empleado` (`empleado_id`,`estado`),
  KEY `ix_cobranzas_estado_fecha` (`estado`,`fecha`),
  KEY `fk_cobranzas_usuario` (`registrado_por`),
  KEY `fk_cobranzas_actualizo` (`actualizado_por`),
  KEY `ix_cobranzas_semana` (`semana_descuento`,`estado`,`empleado_id`),
  CONSTRAINT `fk_cobranzas_actualizo` FOREIGN KEY (`actualizado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_cobranzas_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados` (`id`),
  CONSTRAINT `fk_cobranzas_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_cobranzas_monto` CHECK (`monto_usd` > 0),
  CONSTRAINT `ck_cobranzas_textos` CHECK (char_length(trim(`acreedor`)) >= 2 and char_length(trim(`concepto`)) >= 2),
  CONSTRAINT `ck_cobranzas_descontado` CHECK (`estado` <> 'descontado' or `descontado_en` is not null),
  CONSTRAINT `ck_cobranzas_origen` CHECK (`monto_origen` > 0 and (`moneda_origen` = 'USD' or `tasa_bs_por_usd` > 0)),
  CONSTRAINT `ck_cobranzas_semana` CHECK (`estado` <> 'descontado' or `semana_descuento` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cxc_clientes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `documento` varchar(14) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `domicilio` varchar(160) DEFAULT NULL,
  `saldo_favor` decimal(12,2) NOT NULL DEFAULT 0.00,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cxc_clientes_documento` (`documento`),
  KEY `ix_cxc_clientes_nombre` (`nombre`),
  KEY `fk_cxc_clientes_usuario` (`registrado_por`),
  CONSTRAINT `fk_cxc_clientes_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_cxc_clientes_nombre` CHECK (char_length(trim(`nombre`)) >= 2),
  CONSTRAINT `ck_cxc_clientes_doc` CHECK (`documento` is null or `documento` regexp '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
  CONSTRAINT `ck_cxc_clientes_saldo` CHECK (`saldo_favor` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cxc_devolucion_detalles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `devolucion_id` int(10) unsigned NOT NULL,
  `renglon` smallint(5) unsigned NOT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `precio_unitario` decimal(12,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `descripcion` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cxc_ddet_renglon` (`devolucion_id`,`renglon`),
  CONSTRAINT `fk_cxc_ddet_devolucion` FOREIGN KEY (`devolucion_id`) REFERENCES `cxc_devoluciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_cxc_ddet_montos` CHECK (`cantidad` > 0 and `precio_unitario` > 0 and `subtotal` > 0),
  CONSTRAINT `ck_cxc_ddet_subtotal` CHECK (`subtotal` = round(`cantidad` * `precio_unitario`,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cxc_devoluciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `factura_origen_id` int(10) unsigned DEFAULT NULL,
  `factura_aplicada_id` int(10) unsigned DEFAULT NULL,
  `monto` decimal(12,2) NOT NULL,
  `fecha` date NOT NULL,
  `motivo` varchar(160) DEFAULT NULL,
  `destino` enum('origen','otra_factura','credito_pendiente') NOT NULL,
  `estado` enum('aplicada','pendiente','anulada') NOT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_cxc_dev_cliente` (`cliente_id`,`fecha`),
  KEY `ix_cxc_dev_aplicada` (`factura_aplicada_id`),
  KEY `fk_cxc_dev_origen` (`factura_origen_id`),
  KEY `fk_cxc_dev_usuario` (`registrado_por`),
  CONSTRAINT `fk_cxc_dev_aplicada` FOREIGN KEY (`factura_aplicada_id`) REFERENCES `cxc_facturas` (`id`),
  CONSTRAINT `fk_cxc_dev_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `cxc_clientes` (`id`),
  CONSTRAINT `fk_cxc_dev_origen` FOREIGN KEY (`factura_origen_id`) REFERENCES `cxc_facturas` (`id`),
  CONSTRAINT `fk_cxc_dev_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_cxc_dev_monto` CHECK (`monto` > 0),
  CONSTRAINT `ck_cxc_dev_destino` CHECK (`estado` = 'anulada' or `destino` = 'origen' and `factura_origen_id` is not null and `factura_aplicada_id` = `factura_origen_id` and `estado` = 'aplicada' or `destino` = 'otra_factura' and `factura_aplicada_id` is not null and `estado` = 'aplicada' or `destino` = 'credito_pendiente' and `factura_aplicada_id` is null and `estado` in ('pendiente','aplicada'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_cxc_dev_no_update BEFORE UPDATE ON cxc_devoluciones FOR EACH ROW
BEGIN
    IF NOT (
        (
            OLD.estado IN ('aplicada','pendiente') AND NEW.estado = 'anulada'
            AND NEW.monto = OLD.monto AND NEW.cliente_id = OLD.cliente_id
            AND NEW.destino = OLD.destino
        )
        OR (
            OLD.estado = 'pendiente' AND NEW.estado = 'aplicada'
            AND NEW.monto = OLD.monto AND NEW.cliente_id = OLD.cliente_id
            AND NEW.destino = OLD.destino
            AND NEW.factura_origen_id <=> OLD.factura_origen_id
            AND NEW.factura_aplicada_id <=> OLD.factura_aplicada_id
        )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las devoluciones de CxC no se modifican; solo se anulan o se aplican FIFO';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_cxc_dev_no_delete BEFORE DELETE ON cxc_devoluciones FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las devoluciones de CxC no se eliminan';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cxc_factura_detalles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `factura_id` int(10) unsigned NOT NULL,
  `renglon` smallint(5) unsigned NOT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `precio_unitario` decimal(12,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `descripcion` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cxc_fdet_renglon` (`factura_id`,`renglon`),
  CONSTRAINT `fk_cxc_fdet_factura` FOREIGN KEY (`factura_id`) REFERENCES `cxc_facturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_cxc_fdet_montos` CHECK (`cantidad` > 0 and `precio_unitario` > 0 and `subtotal` > 0),
  CONSTRAINT `ck_cxc_fdet_subtotal` CHECK (`subtotal` = round(`cantidad` * `precio_unitario`,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cxc_facturas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `numero_factura` varchar(20) NOT NULL,
  `es_aleatorio` tinyint(1) NOT NULL DEFAULT 0,
  `domicilio` varchar(160) DEFAULT NULL,
  `observaciones` varchar(240) DEFAULT NULL,
  `fecha_emision` date NOT NULL,
  `fecha_vencimiento` date NOT NULL,
  `monto_total` decimal(12,2) NOT NULL,
  `total_pagado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_devuelto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `saldo_pendiente` decimal(12,2) NOT NULL,
  `estado` enum('vigente','por_vencer','vencida','pagada','anulada') NOT NULL DEFAULT 'vigente',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cxc_facturas_numero` (`numero_factura`),
  KEY `ix_cxc_facturas_cliente_saldo` (`cliente_id`,`saldo_pendiente`),
  KEY `ix_cxc_facturas_vencimiento` (`fecha_vencimiento`),
  KEY `ix_cxc_facturas_estado` (`estado`,`fecha_vencimiento`),
  KEY `fk_cxc_facturas_usuario` (`registrado_por`),
  CONSTRAINT `fk_cxc_facturas_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `cxc_clientes` (`id`),
  CONSTRAINT `fk_cxc_facturas_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_cxc_facturas_numero` CHECK (`numero_factura` regexp '^[A-Za-z0-9-]{1,20}$'),
  CONSTRAINT `ck_cxc_facturas_fechas` CHECK (`fecha_vencimiento` >= `fecha_emision`),
  CONSTRAINT `ck_cxc_facturas_montos` CHECK (`monto_total` > 0 and `total_pagado` >= 0 and `total_devuelto` >= 0 and `saldo_pendiente` >= 0),
  CONSTRAINT `ck_cxc_facturas_flag` CHECK (`es_aleatorio` in (0,1)),
  CONSTRAINT `ck_cxc_facturas_saldo` CHECK (`estado` = 'anulada' and `saldo_pendiente` = 0 or `estado` <> 'anulada' and `saldo_pendiente` = `monto_total` - `total_pagado` - `total_devuelto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cxc_pagos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `factura_id` int(10) unsigned NOT NULL,
  `cliente_id` int(10) unsigned NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `fecha` date NOT NULL,
  `metodo` enum('efectivo','punto','transferencia','pago_movil','saldo_favor','otro') NOT NULL,
  `referencia` varchar(60) DEFAULT NULL,
  `notas` varchar(160) DEFAULT NULL,
  `tasa_cambio` decimal(12,4) DEFAULT NULL,
  `monto_bs` decimal(12,2) DEFAULT NULL,
  `estado` enum('aplicado','anulado') NOT NULL DEFAULT 'aplicado',
  `lote` char(16) DEFAULT NULL COMMENT 'Agrupa los pagos creados por un mismo abono a varias facturas',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_cxc_pagos_factura` (`factura_id`),
  KEY `ix_cxc_pagos_cliente_fecha` (`cliente_id`,`fecha`),
  KEY `ix_cxc_pagos_lote` (`lote`),
  KEY `fk_cxc_pagos_usuario` (`registrado_por`),
  CONSTRAINT `fk_cxc_pagos_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `cxc_clientes` (`id`),
  CONSTRAINT `fk_cxc_pagos_factura` FOREIGN KEY (`factura_id`) REFERENCES `cxc_facturas` (`id`),
  CONSTRAINT `fk_cxc_pagos_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_cxc_pagos_monto` CHECK (`monto` > 0),
  CONSTRAINT `ck_cxc_pagos_tasa` CHECK (`tasa_cambio` is null and `monto_bs` is null or `tasa_cambio` > 0 and `monto_bs` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_cxc_pagos_no_update BEFORE UPDATE ON cxc_pagos FOR EACH ROW
BEGIN
    IF NOT (
        OLD.estado = 'aplicado' AND NEW.estado = 'anulado'
        AND NEW.monto = OLD.monto AND NEW.factura_id = OLD.factura_id
        AND NEW.cliente_id = OLD.cliente_id AND NEW.metodo = OLD.metodo
        AND NEW.fecha = OLD.fecha
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos de CxC no se modifican; solo se anulan';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_cxc_pagos_no_delete BEFORE DELETE ON cxc_pagos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos de CxC no se eliminan';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cxc_saldo_movimientos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `tipo` enum('devolucion','abono_excedente','aplicado_factura','reverso') NOT NULL,
  `monto` decimal(12,2) NOT NULL COMMENT '+ cr├®dito a favor, ÔêÆ cr├®dito consumido',
  `saldo_resultante` decimal(12,2) NOT NULL,
  `factura_id` int(10) unsigned DEFAULT NULL,
  `devolucion_id` int(10) unsigned DEFAULT NULL,
  `pago_id` bigint(20) unsigned DEFAULT NULL,
  `lote` char(16) DEFAULT NULL,
  `fecha` date NOT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_cxc_smov_cliente` (`cliente_id`,`id`),
  KEY `fk_cxc_smov_factura` (`factura_id`),
  KEY `fk_cxc_smov_devolucion` (`devolucion_id`),
  KEY `fk_cxc_smov_pago` (`pago_id`),
  KEY `fk_cxc_smov_usuario` (`registrado_por`),
  CONSTRAINT `fk_cxc_smov_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `cxc_clientes` (`id`),
  CONSTRAINT `fk_cxc_smov_devolucion` FOREIGN KEY (`devolucion_id`) REFERENCES `cxc_devoluciones` (`id`),
  CONSTRAINT `fk_cxc_smov_factura` FOREIGN KEY (`factura_id`) REFERENCES `cxc_facturas` (`id`),
  CONSTRAINT `fk_cxc_smov_pago` FOREIGN KEY (`pago_id`) REFERENCES `cxc_pagos` (`id`),
  CONSTRAINT `fk_cxc_smov_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_cxc_smov_monto` CHECK (`monto` <> 0 and `saldo_resultante` >= 0),
  CONSTRAINT `ck_cxc_smov_signo` CHECK (`tipo` = 'aplicado_factura' and `monto` < 0 and `pago_id` is not null or `tipo` = 'devolucion' and `monto` > 0 and `devolucion_id` is not null or `tipo` = 'abono_excedente' and `monto` > 0 and `lote` is not null or `tipo` = 'reverso' and `monto` <> 0 and (`pago_id` is not null or `devolucion_id` is not null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_cxc_smov_no_update BEFORE UPDATE ON cxc_saldo_movimientos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los movimientos de saldo a favor no se modifican';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_cxc_smov_no_delete BEFORE DELETE ON cxc_saldo_movimientos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los movimientos de saldo a favor no se eliminan';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dias_libres` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empleado_id` int(10) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `tipo` enum('libre','no_trabajado','justificado') NOT NULL DEFAULT 'libre',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dias_libres_empleado_fecha` (`empleado_id`,`fecha`),
  KEY `ix_dias_libres_fecha` (`fecha`,`tipo`),
  KEY `fk_dias_libres_usuario` (`registrado_por`),
  CONSTRAINT `fk_dias_libres_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados` (`id`),
  CONSTRAINT `fk_dias_libres_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleados` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cedula` varchar(12) DEFAULT NULL,
  `nombres` varchar(60) NOT NULL,
  `apellidos` varchar(60) NOT NULL,
  `cargo` varchar(60) DEFAULT NULL,
  `profesion` varchar(80) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `direccion` varchar(160) DEFAULT NULL,
  `direccion_domicilio` text DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `fecha_ingreso` date DEFAULT NULL,
  `fecha_salida` date DEFAULT NULL,
  `salario_semanal_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `dias_libres_mes` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `dia_cierre` tinyint(3) unsigned NOT NULL DEFAULT 6 COMMENT '1=lunes a 7=domingo. 6 = ciclo domingo a sabado',
  `tipo_cuenta` enum('propia','tercero') NOT NULL DEFAULT 'propia',
  `banco` varchar(80) DEFAULT NULL,
  `titular_cedula` varchar(14) DEFAULT NULL,
  `titular_telefono` varchar(20) DEFAULT NULL,
  `numero_cuenta` char(20) DEFAULT NULL,
  `expediente_penal` tinyint(1) NOT NULL DEFAULT 0,
  `foto_perfil` varchar(180) DEFAULT NULL,
  `foto_documento` varchar(180) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_empleados_cedula` (`cedula`),
  KEY `ix_empleados_cargo` (`activo`,`cargo`),
  CONSTRAINT `ck_empleados_cedula` CHECK (`cedula` is null or `cedula` regexp '^[VE]-[0-9]{6,9}$'),
  CONSTRAINT `ck_empleados_salario` CHECK (`salario_semanal_usd` >= 0),
  CONSTRAINT `ck_empleados_fechas` CHECK (`fecha_salida` is null or `fecha_ingreso` is null or `fecha_salida` >= `fecha_ingreso`),
  CONSTRAINT `ck_empleados_penal` CHECK (`expediente_penal` in (0,1)),
  CONSTRAINT `ck_empleados_libres` CHECK (`dias_libres_mes` <= 31),
  CONSTRAINT `ck_empleados_flags` CHECK (`activo` in (0,1)),
  CONSTRAINT `ck_empleados_cuenta` CHECK (`numero_cuenta` is null or `numero_cuenta` regexp '^[0-9]{20}$'),
  CONSTRAINT `ck_empleados_titular_cedula` CHECK (`titular_cedula` is null or `titular_cedula` regexp '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
  CONSTRAINT `ck_empleados_titular_tel` CHECK (`titular_telefono` is null or `titular_telefono` regexp '^0[24][0-9]{2}-[0-9]{7}$'),
  CONSTRAINT `ck_empleados_tercero` CHECK (`tipo_cuenta` = 'propia' or `titular_cedula` is not null),
  CONSTRAINT `ck_empleados_dia_cierre` CHECK (`dia_cierre` between 1 and 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empresa_configuracion` (
  `id` tinyint(3) unsigned NOT NULL,
  `nombre_empresa` varchar(120) NOT NULL,
  `rif_documento` varchar(20) NOT NULL,
  `telefono` varchar(30) NOT NULL DEFAULT '',
  `direccion` varchar(180) NOT NULL DEFAULT '',
  `color_primario` varchar(7) NOT NULL,
  `color_secundario` varchar(7) NOT NULL,
  `ruta_logo` varchar(180) DEFAULT NULL,
  `ruta_sello` varchar(180) DEFAULT NULL,
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_empresa_config_unica` CHECK (`id` = 1),
  CONSTRAINT `ck_empresa_color_primario` CHECK (`color_primario` regexp '^#[0-9A-Fa-f]{6}$'),
  CONSTRAINT `ck_empresa_color_secundario` CHECK (`color_secundario` regexp '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `formulas_produccion` (
  `producto_id` int(10) unsigned NOT NULL,
  `base` enum('leche','cuajada') NOT NULL DEFAULT 'leche',
  `factor_l_kg_ref` decimal(8,4) DEFAULT NULL,
  `cuajada_ref_kg` decimal(8,3) DEFAULT NULL,
  `masa_min_kg` decimal(8,3) DEFAULT NULL,
  `masa_max_kg` decimal(8,3) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`producto_id`),
  CONSTRAINT `fk_formulas_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `ck_formulas_factor` CHECK (`factor_l_kg_ref` is null or `factor_l_kg_ref` > 0 and `factor_l_kg_ref` <= 50),
  CONSTRAINT `ck_formulas_cuajada` CHECK (`base` = 'leche' and `cuajada_ref_kg` is null and `masa_min_kg` is null and `masa_max_kg` is null or `base` = 'cuajada' and `cuajada_ref_kg` > 0 and `masa_min_kg` > 0 and `masa_max_kg` >= `masa_min_kg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gas_cilindros` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `serial` varchar(40) NOT NULL,
  `capacidad_kg` tinyint(3) unsigned NOT NULL,
  `estado_fisico` enum('optimo','regular','mal_estado') NOT NULL DEFAULT 'optimo',
  `observacion_fisica` varchar(255) DEFAULT NULL,
  `pertenencia` enum('propio','tercero') NOT NULL DEFAULT 'propio',
  `tipo_tercero` enum('empleado','transportista','productor','otro') DEFAULT NULL,
  `empleado_id` int(10) unsigned DEFAULT NULL,
  `transportista_id` int(10) unsigned DEFAULT NULL,
  `productor_id` int(10) unsigned DEFAULT NULL,
  `propietario_nombre` varchar(120) DEFAULT NULL,
  `despacho_abierto_id` int(10) unsigned DEFAULT NULL,
  `estado_registro` enum('activo','archivado') NOT NULL DEFAULT 'activo',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gas_cil_serial` (`serial`),
  KEY `ix_gas_cil_fisico` (`estado_fisico`,`estado_registro`),
  KEY `ix_gas_cil_empleado` (`empleado_id`),
  KEY `ix_gas_cil_transportista` (`transportista_id`),
  KEY `ix_gas_cil_productor` (`productor_id`),
  KEY `ix_gas_cil_abierto` (`despacho_abierto_id`),
  KEY `fk_gas_cil_usuario` (`registrado_por`),
  KEY `ix_gas_cil_cap_fisico` (`estado_registro`,`capacidad_kg`,`estado_fisico`),
  CONSTRAINT `fk_gas_cil_abierto` FOREIGN KEY (`despacho_abierto_id`) REFERENCES `gas_despachos` (`id`),
  CONSTRAINT `fk_gas_cil_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados` (`id`),
  CONSTRAINT `fk_gas_cil_productor` FOREIGN KEY (`productor_id`) REFERENCES `productores` (`id`),
  CONSTRAINT `fk_gas_cil_transportista` FOREIGN KEY (`transportista_id`) REFERENCES `log_transportistas` (`id`),
  CONSTRAINT `fk_gas_cil_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_gas_cil_dueno` CHECK (`pertenencia` = 'propio' and `tipo_tercero` is null and `empleado_id` is null and `transportista_id` is null and `productor_id` is null and `propietario_nombre` is null or `pertenencia` = 'tercero' and `tipo_tercero` = 'empleado' and `empleado_id` is not null and `transportista_id` is null and `productor_id` is null and `propietario_nombre` is null or `pertenencia` = 'tercero' and `tipo_tercero` = 'transportista' and `transportista_id` is not null and `empleado_id` is null and `productor_id` is null and `propietario_nombre` is null or `pertenencia` = 'tercero' and `tipo_tercero` = 'productor' and `productor_id` is not null and `empleado_id` is null and `transportista_id` is null and `propietario_nombre` is null or `pertenencia` = 'tercero' and `tipo_tercero` = 'otro' and `empleado_id` is null and `transportista_id` is null and `productor_id` is null and `propietario_nombre` is not null and char_length(trim(`propietario_nombre`)) >= 2),
  CONSTRAINT `ck_gas_cil_serial` CHECK (char_length(trim(`serial`)) >= 1),
  CONSTRAINT `ck_gas_cil_cap` CHECK (`capacidad_kg` in (10,15,27,43))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_cil_bi BEFORE INSERT ON gas_cilindros FOR EACH ROW
BEGIN
    UPDATE gas_correlativos
       SET ultimo = LAST_INSERT_ID(ultimo + 1)
     WHERE clave = 'cilindro';
    SET NEW.id = LAST_INSERT_ID();
    SET NEW.serial = CONCAT('CIL-', LPAD(NEW.id, 6, '0'));
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_cil_bu BEFORE UPDATE ON gas_cilindros FOR EACH ROW
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_cil_no_delete BEFORE DELETE ON gas_cilindros FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La bombona no se elimina: archivela para conservar los viajes';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gas_correlativos` (
  `clave` varchar(20) NOT NULL,
  `ultimo` int(10) unsigned NOT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gas_despacho_cilindros` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `despacho_id` int(10) unsigned NOT NULL,
  `cilindro_id` int(10) unsigned NOT NULL,
  `pertenencia` enum('propio','tercero') NOT NULL DEFAULT 'propio',
  `tipo_tercero` enum('empleado','transportista','productor','otro') DEFAULT NULL,
  `empleado_id` int(10) unsigned DEFAULT NULL,
  `transportista_id` int(10) unsigned DEFAULT NULL,
  `productor_id` int(10) unsigned DEFAULT NULL,
  `propietario_nombre` varchar(120) DEFAULT NULL,
  `estado_fisico` enum('optimo','regular','mal_estado') NOT NULL DEFAULT 'optimo',
  `capacidad_kg` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `precio_unitario_usd` decimal(12,2) NOT NULL DEFAULT 0.01,
  `subtotal_usd` decimal(12,2) NOT NULL DEFAULT 0.01,
  `cargo_estado` enum('no_aplica','pendiente','cargado') NOT NULL DEFAULT 'no_aplica',
  `cargo_destino` enum('nomina','liquidacion_leche','cxp_transporte','manual') DEFAULT NULL,
  `cargo_ref_id` bigint(20) unsigned DEFAULT NULL,
  `cargo_en` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gas_dc_despacho_cil` (`despacho_id`,`cilindro_id`),
  KEY `ix_gas_dc_cilindro` (`cilindro_id`),
  KEY `ix_gas_dc_cargo_empleado` (`cargo_estado`,`empleado_id`),
  KEY `ix_gas_dc_cargo_productor` (`cargo_estado`,`productor_id`),
  KEY `ix_gas_dc_cargo_transportista` (`cargo_estado`,`transportista_id`),
  KEY `fk_gas_dc_empleado` (`empleado_id`),
  KEY `fk_gas_dc_transportista` (`transportista_id`),
  KEY `fk_gas_dc_productor` (`productor_id`),
  CONSTRAINT `fk_gas_dc_cilindro` FOREIGN KEY (`cilindro_id`) REFERENCES `gas_cilindros` (`id`),
  CONSTRAINT `fk_gas_dc_despacho` FOREIGN KEY (`despacho_id`) REFERENCES `gas_despachos` (`id`),
  CONSTRAINT `fk_gas_dc_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados` (`id`),
  CONSTRAINT `fk_gas_dc_productor` FOREIGN KEY (`productor_id`) REFERENCES `productores` (`id`),
  CONSTRAINT `fk_gas_dc_transportista` FOREIGN KEY (`transportista_id`) REFERENCES `log_transportistas` (`id`),
  CONSTRAINT `ck_gas_dc_precio` CHECK (`precio_unitario_usd` > 0 and `subtotal_usd` = `precio_unitario_usd`),
  CONSTRAINT `ck_gas_dc_dueno` CHECK (`pertenencia` = 'propio' and `tipo_tercero` is null and `empleado_id` is null and `transportista_id` is null and `productor_id` is null and `propietario_nombre` is null or `pertenencia` = 'tercero' and `tipo_tercero` = 'empleado' and `empleado_id` is not null and `propietario_nombre` is not null and `transportista_id` is null and `productor_id` is null or `pertenencia` = 'tercero' and `tipo_tercero` = 'transportista' and `transportista_id` is not null and `propietario_nombre` is not null and `empleado_id` is null and `productor_id` is null or `pertenencia` = 'tercero' and `tipo_tercero` = 'productor' and `productor_id` is not null and `propietario_nombre` is not null and `empleado_id` is null and `transportista_id` is null or `pertenencia` = 'tercero' and `tipo_tercero` = 'otro' and `propietario_nombre` is not null and char_length(trim(`propietario_nombre`)) >= 2 and `empleado_id` is null and `transportista_id` is null and `productor_id` is null),
  CONSTRAINT `ck_gas_dc_cargo` CHECK (`pertenencia` = 'propio' and `cargo_estado` = 'no_aplica' and `cargo_destino` is null and `cargo_ref_id` is null and `cargo_en` is null or `pertenencia` = 'tercero' and `cargo_estado` = 'pendiente' and `cargo_destino` is null and `cargo_ref_id` is null and `cargo_en` is null or `pertenencia` = 'tercero' and `cargo_estado` = 'cargado' and `cargo_destino` is not null and `cargo_ref_id` is not null and `cargo_en` is not null),
  CONSTRAINT `ck_gas_dc_cap` CHECK (`capacidad_kg` in (10,15,27,43))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_dc_bi BEFORE INSERT ON gas_despacho_cilindros FOR EACH ROW
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_dc_ai AFTER INSERT ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    DECLARE v_rows INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @gas_ciclo = NULL;
        RESIGNAL;
    END;

    SET @gas_ciclo = 1;
    UPDATE gas_cilindros
       SET despacho_abierto_id = NEW.despacho_id
     WHERE id = NEW.cilindro_id
       AND despacho_abierto_id IS NULL;
    SET v_rows = ROW_COUNT();
    SET @gas_ciclo = NULL;

    IF v_rows <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se pudo reservar la bombona para este despacho';
    END IF;

    CALL gas_recalcular_despacho(NEW.despacho_id);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_dc_bu BEFORE UPDATE ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    IF NOT (NEW.despacho_id = OLD.despacho_id AND NEW.cilindro_id = OLD.cilindro_id
            AND NEW.pertenencia = OLD.pertenencia
            AND NEW.tipo_tercero <=> OLD.tipo_tercero
            AND NEW.empleado_id <=> OLD.empleado_id
            AND NEW.transportista_id <=> OLD.transportista_id
            AND NEW.productor_id <=> OLD.productor_id
            AND NEW.propietario_nombre <=> OLD.propietario_nombre
            AND NEW.estado_fisico = OLD.estado_fisico) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La foto del cilindro en el viaje no se reescribe';
    END IF;

    IF @gas_precio = 1 THEN
        IF NOT (NEW.cargo_estado = OLD.cargo_estado
                AND NEW.cargo_destino <=> OLD.cargo_destino
                AND NEW.cargo_ref_id <=> OLD.cargo_ref_id
                AND NEW.cargo_en <=> OLD.cargo_en) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cambio de precio no toca el cargo al tercero';
        END IF;
    ELSE
        IF NEW.precio_unitario_usd <> OLD.precio_unitario_usd OR NEW.subtotal_usd <> OLD.subtotal_usd THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El precio se edita en el despacho, no en la linea';
        END IF;
        IF OLD.cargo_estado = 'cargado' AND NOT (
            NEW.cargo_estado = OLD.cargo_estado
            AND NEW.cargo_destino <=> OLD.cargo_destino
            AND NEW.cargo_ref_id <=> OLD.cargo_ref_id
            AND NEW.cargo_en <=> OLD.cargo_en
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cargo al tercero ya fue aplicado';
        END IF;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_dc_au AFTER UPDATE ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    IF @gas_precio IS NULL AND NEW.subtotal_usd <> OLD.subtotal_usd THEN
        CALL gas_recalcular_despacho(NEW.despacho_id);
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_dc_bd BEFORE DELETE ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    DECLARE v_pagado DECIMAL(12,2);
    DECLARE v_entrega DATE;

    SELECT pagado_usd, fecha_entrega INTO v_pagado, v_entrega
      FROM gas_despachos WHERE id = OLD.despacho_id;

    IF v_entrega IS NOT NULL OR v_pagado > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se quitan bombonas de un despacho entregado o con abonos';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_dc_ad AFTER DELETE ON gas_despacho_cilindros FOR EACH ROW
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @gas_ciclo = NULL;
        RESIGNAL;
    END;

    SET @gas_ciclo = 1;
    UPDATE gas_cilindros
       SET despacho_abierto_id = NULL
     WHERE id = OLD.cilindro_id
       AND despacho_abierto_id = OLD.despacho_id;
    SET @gas_ciclo = NULL;

    CALL gas_recalcular_despacho(OLD.despacho_id);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gas_despachos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo_despacho` varchar(20) NOT NULL,
  `proveedor_id` int(10) unsigned NOT NULL,
  `fecha_salida` date NOT NULL,
  `fecha_entrega` date DEFAULT NULL,
  `precio_10_usd` decimal(12,2) NOT NULL,
  `precio_15_usd` decimal(12,2) NOT NULL,
  `precio_27_usd` decimal(12,2) NOT NULL,
  `precio_43_usd` decimal(12,2) NOT NULL,
  `semana_inicio` date GENERATED ALWAYS AS (`fecha_salida` - interval dayofweek(`fecha_salida`) MOD 7 day) STORED,
  `cantidad` smallint(5) unsigned NOT NULL DEFAULT 0,
  `costo_total_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pagado_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `saldo_usd` decimal(12,2) GENERATED ALWAYS AS (`costo_total_usd` - `pagado_usd`) STORED,
  `estado_deuda` enum('pendiente','saldo_restante','cancelado') NOT NULL DEFAULT 'pendiente',
  `observaciones` varchar(255) DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gas_despacho_codigo` (`codigo_despacho`),
  KEY `ix_gas_despacho_prov` (`proveedor_id`,`fecha_salida`),
  KEY `ix_gas_despacho_semana` (`semana_inicio`,`estado_deuda`),
  KEY `ix_gas_despacho_entrega` (`fecha_entrega`),
  KEY `fk_gas_despacho_usuario` (`registrado_por`),
  CONSTRAINT `fk_gas_despacho_prov` FOREIGN KEY (`proveedor_id`) REFERENCES `gas_proveedores` (`id`),
  CONSTRAINT `fk_gas_despacho_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_gas_despacho_fechas` CHECK (`fecha_entrega` is null or `fecha_entrega` >= `fecha_salida`),
  CONSTRAINT `ck_gas_despacho_pagado` CHECK (`pagado_usd` >= 0 and `pagado_usd` <= `costo_total_usd`),
  CONSTRAINT `ck_gas_despacho_deuda` CHECK (`pagado_usd` = 0 and `estado_deuda` = 'pendiente' or `pagado_usd` > 0 and `pagado_usd` < `costo_total_usd` and `estado_deuda` = 'saldo_restante' or `costo_total_usd` > 0 and `pagado_usd` = `costo_total_usd` and `estado_deuda` = 'cancelado'),
  CONSTRAINT `ck_gas_despacho_precios` CHECK (`precio_10_usd` > 0 and `precio_15_usd` > 0 and `precio_27_usd` > 0 and `precio_43_usd` > 0),
  CONSTRAINT `ck_gas_despacho_costo` CHECK (`costo_total_usd` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_despacho_bi BEFORE INSERT ON gas_despachos FOR EACH ROW
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_despacho_bu BEFORE UPDATE ON gas_despachos FOR EACH ROW
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_despacho_au AFTER UPDATE ON gas_despachos FOR EACH ROW
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_despacho_bd BEFORE DELETE ON gas_despachos FOR EACH ROW
BEGIN
    IF OLD.cantidad > 0 OR OLD.pagado_usd > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se borra un despacho vacio, sin abonos';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gas_pagos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `despacho_id` int(10) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `semana_inicio` date GENERATED ALWAYS AS (`fecha` - interval dayofweek(`fecha`) MOD 7 day) STORED,
  `metodo` enum('efectivo','pago_movil','transferencia','otro') NOT NULL,
  `cuenta` enum('caja','banco') NOT NULL,
  `moneda` enum('USD','VES') NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `tasa_bs_por_usd` decimal(14,4) NOT NULL,
  `monto_usd` decimal(12,2) NOT NULL,
  `referencia` varchar(40) DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `anulado_en` datetime DEFAULT NULL,
  `anulado_por` int(10) unsigned DEFAULT NULL,
  `motivo_anulacion` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_gas_pago_despacho` (`despacho_id`,`anulado_en`),
  KEY `ix_gas_pago_semana` (`semana_inicio`),
  KEY `fk_gas_pago_usuario` (`registrado_por`),
  KEY `fk_gas_pago_anulador` (`anulado_por`),
  CONSTRAINT `fk_gas_pago_anulador` FOREIGN KEY (`anulado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_gas_pago_despacho` FOREIGN KEY (`despacho_id`) REFERENCES `gas_despachos` (`id`),
  CONSTRAINT `fk_gas_pago_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_gas_pago_montos` CHECK (`monto` > 0 and `monto_usd` > 0 and `tasa_bs_por_usd` > 0),
  CONSTRAINT `ck_gas_pago_usd` CHECK (`moneda` = 'USD' and `monto_usd` = `monto` or `moneda` = 'VES' and `monto_usd` = round(`monto` / `tasa_bs_por_usd`,2)),
  CONSTRAINT `ck_gas_pago_cuenta` CHECK (`metodo` = 'efectivo' and `cuenta` = 'caja' or `metodo` in ('pago_movil','transferencia') and `cuenta` = 'banco' or `metodo` = 'otro'),
  CONSTRAINT `ck_gas_pago_referencia` CHECK (`metodo` not in ('pago_movil','transferencia') or `referencia` is not null and trim(`referencia`) <> ''),
  CONSTRAINT `ck_gas_pago_anulacion` CHECK (`anulado_en` is null and `anulado_por` is null and `motivo_anulacion` is null or `anulado_en` is not null and `anulado_por` is not null and `motivo_anulacion` is not null and char_length(trim(`motivo_anulacion`)) >= 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_pago_bi BEFORE INSERT ON gas_pagos FOR EACH ROW
BEGIN
    DECLARE v_saldo DECIMAL(12,2);
    DECLARE v_costo DECIMAL(12,2);
    DECLARE v_despacho_id INT UNSIGNED;

    SET v_despacho_id = NEW.despacho_id;

    IF NEW.moneda = 'USD' THEN
        SET NEW.monto_usd = NEW.monto;
    ELSE
        SET NEW.monto_usd = ROUND(NEW.monto / NEW.tasa_bs_por_usd, 2);
    END IF;

    SELECT saldo_usd, costo_total_usd INTO v_saldo, v_costo
      FROM gas_despachos
     WHERE id = v_despacho_id
       FOR UPDATE;

    IF v_costo IS NULL OR v_costo <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El despacho no tiene bombonas que cobrar';
    END IF;
    IF NEW.monto_usd > v_saldo THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El abono supera el saldo del despacho';
    END IF;
    IF NEW.anulado_en IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un abono nuevo no nace anulado';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_pago_ai AFTER INSERT ON gas_pagos FOR EACH ROW
BEGIN
    CALL gas_recalcular_despacho(NEW.despacho_id);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_pago_bu BEFORE UPDATE ON gas_pagos FOR EACH ROW
BEGIN
    IF OLD.anulado_en IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El abono ya esta anulado';
    END IF;
    IF NOT (NEW.despacho_id = OLD.despacho_id AND NEW.fecha = OLD.fecha
            AND NEW.metodo = OLD.metodo AND NEW.cuenta = OLD.cuenta
            AND NEW.moneda = OLD.moneda AND NEW.monto = OLD.monto
            AND NEW.tasa_bs_por_usd = OLD.tasa_bs_por_usd AND NEW.monto_usd = OLD.monto_usd
            AND NEW.referencia <=> OLD.referencia AND NEW.registrado_por = OLD.registrado_por) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los datos economicos del abono son inmutables';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_pago_au AFTER UPDATE ON gas_pagos FOR EACH ROW
BEGIN
    CALL gas_recalcular_despacho(NEW.despacho_id);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_pago_bd BEFORE DELETE ON gas_pagos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los abonos de gas no se eliminan: se anulan';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gas_proveedores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `documento` varchar(12) DEFAULT NULL,
  `nombre` varchar(120) NOT NULL,
  `contacto` varchar(80) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `correo` varchar(100) DEFAULT NULL,
  `direccion` varchar(160) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `estado` enum('activo','inactivo','eliminado') NOT NULL DEFAULT 'activo',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gas_prov_nombre` (`nombre`),
  UNIQUE KEY `uq_gas_prov_documento` (`documento`),
  KEY `ix_gas_prov_estado` (`estado`,`nombre`),
  KEY `fk_gas_prov_usuario` (`registrado_por`),
  CONSTRAINT `fk_gas_prov_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_gas_prov_documento` CHECK (`documento` is null or `documento` regexp '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
  CONSTRAINT `ck_gas_prov_nombre` CHECK (char_length(trim(`nombre`)) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_prov_no_delete BEFORE DELETE ON gas_proveedores FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El proveedor no se elimina: paselo a estado eliminado';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gas_reparaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cilindro_id` int(10) unsigned NOT NULL,
  `responsable` varchar(120) NOT NULL,
  `fecha_salida` date NOT NULL,
  `costo_usd` decimal(12,2) DEFAULT NULL,
  `estado` enum('en_reparacion','reparado') NOT NULL DEFAULT 'en_reparacion',
  `fecha_retorno` date DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_gas_rep_cilindro` (`cilindro_id`,`estado`),
  KEY `ix_gas_rep_estado` (`estado`,`fecha_salida`),
  KEY `fk_gas_rep_usuario` (`registrado_por`),
  CONSTRAINT `fk_gas_rep_cilindro` FOREIGN KEY (`cilindro_id`) REFERENCES `gas_cilindros` (`id`),
  CONSTRAINT `fk_gas_rep_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_gas_rep_responsable` CHECK (char_length(trim(`responsable`)) >= 2),
  CONSTRAINT `ck_gas_rep_estado` CHECK (`estado` = 'en_reparacion' and `costo_usd` is null and `fecha_retorno` is null or `estado` = 'reparado' and `costo_usd` > 0 and `fecha_retorno` is not null and `fecha_retorno` >= `fecha_salida`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_gas_rep_bi BEFORE INSERT ON gas_reparaciones FOR EACH ROW
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_empleados_historial` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empleado_id` int(10) unsigned NOT NULL,
  `fecha_ingreso` date DEFAULT NULL,
  `fecha_salida` date DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_hr_hist_empleado` (`empleado_id`,`fecha_ingreso`,`id`),
  CONSTRAINT `fk_hr_hist_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ck_hr_hist_fechas` CHECK (`fecha_salida` is null or `fecha_ingreso` is null or `fecha_salida` >= `fecha_ingreso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_prestamos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empleado_id` int(10) unsigned NOT NULL,
  `monto_total` decimal(12,2) NOT NULL,
  `cuota_semanal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tipo` enum('prestamo','efectivo','transferencia','pago_movil','mercancia','otros') NOT NULL DEFAULT 'prestamo',
  `fecha` date NOT NULL,
  `estado` enum('activo','pagado') NOT NULL DEFAULT 'activo',
  `concepto` varchar(120) NOT NULL DEFAULT 'Préstamo interno',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_hr_pr_emp` (`empleado_id`,`estado`,`fecha`),
  KEY `fk_hr_pr_user` (`registrado_por`),
  CONSTRAINT `fk_hr_pr_emp` FOREIGN KEY (`empleado_id`) REFERENCES `empleados` (`id`),
  CONSTRAINT `fk_hr_pr_user` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_hr_pr_monto` CHECK (`monto_total` > 0 and `cuota_semanal` >= 0 and `cuota_semanal` <= `monto_total`),
  CONSTRAINT `ck_hr_pr_conc` CHECK (char_length(trim(`concepto`)) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_prestamos_cuotas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `prestamo_id` int(10) unsigned NOT NULL,
  `monto_cuota` decimal(12,2) NOT NULL,
  `nomina_id` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_pc_nomina` (`prestamo_id`,`nomina_id`),
  KEY `ix_hr_pc_nomina` (`nomina_id`),
  CONSTRAINT `fk_hr_pc_nom` FOREIGN KEY (`nomina_id`) REFERENCES `nomina_semanal` (`id`),
  CONSTRAINT `fk_hr_pc_pr` FOREIGN KEY (`prestamo_id`) REFERENCES `hr_prestamos` (`id`),
  CONSTRAINT `ck_hr_pc_monto` CHECK (`monto_cuota` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventario_almacenes` (
  `producto_id` int(10) unsigned NOT NULL,
  `almacen_id` int(10) unsigned NOT NULL,
  `stock` decimal(12,3) NOT NULL DEFAULT 0.000,
  `stock_secundario` int(11) NOT NULL DEFAULT 0,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`producto_id`,`almacen_id`),
  KEY `ix_inv_alm_almacen` (`almacen_id`),
  CONSTRAINT `fk_inv_alm_almacen` FOREIGN KEY (`almacen_id`) REFERENCES `almacenes` (`id`),
  CONSTRAINT `fk_inv_alm_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `ck_inv_alm_stock` CHECK (`stock` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventario_movimientos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` int(10) unsigned NOT NULL,
  `almacen_id` int(10) unsigned DEFAULT NULL,
  `tipo` enum('produccion','venta','anulacion_venta','ajuste') NOT NULL,
  `cantidad` decimal(12,3) NOT NULL,
  `stock_final` decimal(12,3) NOT NULL,
  `produccion_id` int(10) unsigned DEFAULT NULL,
  `produccion_diaria_id` int(10) unsigned DEFAULT NULL,
  `venta_id` int(10) unsigned DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_kardex_producto` (`producto_id`,`creado_en`),
  KEY `fk_kardex_produccion` (`produccion_id`),
  KEY `fk_kardex_venta` (`venta_id`),
  KEY `fk_kardex_usuario` (`usuario_id`),
  KEY `ix_kardex_almacen` (`almacen_id`,`creado_en`),
  KEY `fk_kardex_prod_diaria` (`produccion_diaria_id`),
  CONSTRAINT `fk_kardex_almacen` FOREIGN KEY (`almacen_id`) REFERENCES `almacenes` (`id`),
  CONSTRAINT `fk_kardex_prod_diaria` FOREIGN KEY (`produccion_diaria_id`) REFERENCES `produccion_diaria` (`id`),
  CONSTRAINT `fk_kardex_produccion` FOREIGN KEY (`produccion_id`) REFERENCES `producciones` (`id`),
  CONSTRAINT `fk_kardex_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `fk_kardex_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_kardex_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `ck_kardex_cantidad` CHECK (`cantidad` <> 0 and `stock_final` >= 0),
  CONSTRAINT `ck_kardex_ajuste` CHECK (`tipo` <> 'ajuste' or `motivo` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `log_fletes_diarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transportista_id` int(10) unsigned NOT NULL,
  `ruta_id` int(10) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `litros_recolectados` decimal(10,2) NOT NULL,
  `costo_flete` decimal(10,2) NOT NULL,
  `pago_id` int(10) unsigned DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_log_flete_dia` (`transportista_id`,`ruta_id`,`fecha`),
  KEY `ix_log_flete_fecha` (`fecha`),
  KEY `ix_log_flete_pago` (`pago_id`),
  KEY `fk_log_flete_ruta` (`ruta_id`),
  KEY `fk_log_flete_user` (`registrado_por`),
  CONSTRAINT `fk_log_flete_pago` FOREIGN KEY (`pago_id`) REFERENCES `log_pagos_semanales` (`id`),
  CONSTRAINT `fk_log_flete_ruta` FOREIGN KEY (`ruta_id`) REFERENCES `log_rutas` (`id`),
  CONSTRAINT `fk_log_flete_tr` FOREIGN KEY (`transportista_id`) REFERENCES `log_transportistas` (`id`),
  CONSTRAINT `fk_log_flete_user` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_log_flete_litros` CHECK (`litros_recolectados` > 0),
  CONSTRAINT `ck_log_flete_costo` CHECK (`costo_flete` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `log_pagos_semanales` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transportista_id` int(10) unsigned NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `total_litros` decimal(12,2) NOT NULL,
  `total_pagar` decimal(12,2) NOT NULL,
  `estado` enum('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_log_pago_tr` (`transportista_id`,`fecha_inicio`),
  KEY `fk_log_pago_usuario` (`registrado_por`),
  CONSTRAINT `fk_log_pago_tr` FOREIGN KEY (`transportista_id`) REFERENCES `log_transportistas` (`id`),
  CONSTRAINT `fk_log_pago_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_log_pago_rango` CHECK (`fecha_fin` >= `fecha_inicio`),
  CONSTRAINT `ck_log_pago_montos` CHECK (`total_litros` >= 0 and `total_pagar` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `log_pagos_transporte` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pago_semanal_id` int(10) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `descripcion_mercancia` varchar(255) DEFAULT NULL,
  `tipo` enum('efectivo','transferencia','pago_movil','mercancia','otros') NOT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_log_pt_pago` (`pago_semanal_id`,`fecha`),
  KEY `fk_log_pt_usuario` (`registrado_por`),
  CONSTRAINT `fk_log_pt_cierre` FOREIGN KEY (`pago_semanal_id`) REFERENCES `log_pagos_semanales` (`id`),
  CONSTRAINT `fk_log_pt_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_log_pt_monto` CHECK (`monto` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `log_ruta_productores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ruta_id` int(10) unsigned NOT NULL,
  `productor_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_log_rp` (`ruta_id`,`productor_id`),
  KEY `ix_log_rp_productor` (`productor_id`),
  CONSTRAINT `fk_log_rp_productor` FOREIGN KEY (`productor_id`) REFERENCES `productores` (`id`),
  CONSTRAINT `fk_log_rp_ruta` FOREIGN KEY (`ruta_id`) REFERENCES `log_rutas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `log_rutas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transportista_id` int(10) unsigned NOT NULL,
  `nombre_zona` varchar(80) NOT NULL,
  `precio_viaje` decimal(10,2) NOT NULL,
  `vehiculos_asignados` int(10) unsigned NOT NULL DEFAULT 1,
  `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_log_ruta_tr` (`transportista_id`,`estado`),
  KEY `fk_log_ruta_usuario` (`registrado_por`),
  CONSTRAINT `fk_log_ruta_tr` FOREIGN KEY (`transportista_id`) REFERENCES `log_transportistas` (`id`),
  CONSTRAINT `fk_log_ruta_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_log_ruta_nombre` CHECK (char_length(`nombre_zona`) >= 2),
  CONSTRAINT `ck_log_ruta_precio` CHECK (`precio_viaje` >= 0),
  CONSTRAINT `ck_log_ruta_nveh` CHECK (`vehiculos_asignados` >= 1 and `vehiculos_asignados` <= 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `log_transportista_vehiculos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transportista_id` int(10) unsigned NOT NULL,
  `tipo_vehiculo` enum('moto','carro','camioneta','camion','otro') NOT NULL DEFAULT 'otro',
  `marca` varchar(60) DEFAULT NULL,
  `modelo` varchar(60) DEFAULT NULL,
  `color` varchar(40) DEFAULT NULL,
  `placa` varchar(15) NOT NULL,
  `limite_carga_kg` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_log_tv_placa` (`placa`),
  KEY `ix_log_tv_tr` (`transportista_id`),
  CONSTRAINT `fk_log_tv_tr` FOREIGN KEY (`transportista_id`) REFERENCES `log_transportistas` (`id`),
  CONSTRAINT `ck_log_tv_placa` CHECK (char_length(trim(`placa`)) >= 3),
  CONSTRAINT `ck_log_tv_limite` CHECK (`limite_carga_kg` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `log_transportistas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `documento` varchar(20) DEFAULT NULL,
  `nombre` varchar(120) NOT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `modalidad` enum('individual','cooperativa') NOT NULL DEFAULT 'individual',
  `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_log_tr_documento` (`documento`),
  KEY `ix_log_tr_nombre` (`nombre`),
  KEY `fk_log_tr_usuario` (`registrado_por`),
  CONSTRAINT `fk_log_tr_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_log_tr_nombre` CHECK (char_length(`nombre`) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_intentos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `exito` tinyint(1) NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_login_ip_fecha` (`ip`,`creado_en`),
  CONSTRAINT `ck_login_exito` CHECK (`exito` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lotes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) NOT NULL,
  `producto_id` int(10) unsigned NOT NULL,
  `produccion_id` int(10) unsigned DEFAULT NULL,
  `cantidad` decimal(12,3) NOT NULL,
  `fecha_elaboracion` date NOT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lotes_codigo` (`codigo`),
  KEY `fk_lotes_producto` (`producto_id`),
  KEY `fk_lotes_produccion` (`produccion_id`),
  CONSTRAINT `fk_lotes_produccion` FOREIGN KEY (`produccion_id`) REFERENCES `producciones` (`id`),
  CONSTRAINT `fk_lotes_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `ck_lotes_cantidad` CHECK (`cantidad` > 0),
  CONSTRAINT `ck_lotes_fechas` CHECK (`fecha_vencimiento` is null or `fecha_vencimiento` >= `fecha_elaboracion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nomina_semanal` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empleado_id` int(10) unsigned NOT NULL,
  `semana_inicio` date NOT NULL,
  `semana_fin` date NOT NULL,
  `salario_base_usd` decimal(12,2) NOT NULL,
  `salario_diario_usd` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `dias_no_trabajados` decimal(3,1) NOT NULL DEFAULT 0.0,
  `dias_ausencia` decimal(3,1) NOT NULL DEFAULT 0.0,
  `dias_libres_excedidos` decimal(3,1) NOT NULL DEFAULT 0.0,
  `dias_fuera_contrato` decimal(3,1) NOT NULL DEFAULT 0.0,
  `desc_dias_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pagos_extras_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vales_mercancia_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tasa_bs_por_usd` decimal(14,4) DEFAULT NULL,
  `neto_usd` decimal(12,2) NOT NULL,
  `deuda_saldo_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cuota_deuda_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cobranzas_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estado` enum('borrador','cerrada','pagada') NOT NULL DEFAULT 'borrador',
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nomina_empleado_semana` (`empleado_id`,`semana_inicio`),
  KEY `fk_nomina_usuario` (`registrado_por`),
  KEY `ix_nomina_semana` (`semana_inicio`,`estado`),
  CONSTRAINT `fk_nomina_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados` (`id`),
  CONSTRAINT `fk_nomina_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_nomina_dias` CHECK (`dias_no_trabajados` between 0 and 7),
  CONSTRAINT `ck_nomina_periodo` CHECK (`semana_fin` = `semana_inicio` + interval 6 day),
  CONSTRAINT `ck_nomina_desglose` CHECK (`dias_ausencia` >= 0 and `dias_libres_excedidos` >= 0 and `dias_fuera_contrato` >= 0 and `dias_ausencia` + `dias_libres_excedidos` + `dias_fuera_contrato` = `dias_no_trabajados`),
  CONSTRAINT `ck_nomina_tasa` CHECK (`tasa_bs_por_usd` is null or `tasa_bs_por_usd` > 0),
  CONSTRAINT `ck_nomina_montos` CHECK (`salario_base_usd` >= 0 and `salario_diario_usd` >= 0 and `desc_dias_usd` >= 0 and `desc_dias_usd` <= `salario_base_usd` and `pagos_extras_usd` >= 0 and `vales_mercancia_usd` >= 0 and `deuda_saldo_usd` >= 0 and `cuota_deuda_usd` >= 0 and `cuota_deuda_usd` <= `deuda_saldo_usd` and `cobranzas_usd` >= 0),
  CONSTRAINT `ck_nomina_neto` CHECK (`neto_usd` = `salario_base_usd` - `desc_dias_usd` + `pagos_extras_usd` - `vales_mercancia_usd` - `cuota_deuda_usd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prestamos_empleados` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empleado_id` int(10) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `monto_usd` decimal(12,2) NOT NULL,
  `concepto` varchar(120) NOT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_prestamos_empleado` (`empleado_id`,`fecha`),
  KEY `fk_prestamos_usuario` (`registrado_por`),
  CONSTRAINT `fk_prestamos_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados` (`id`),
  CONSTRAINT `fk_prestamos_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_prestamos_monto` CHECK (`monto_usd` > 0),
  CONSTRAINT `ck_prestamos_concepto` CHECK (char_length(trim(`concepto`)) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prod_catalogo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` int(10) unsigned DEFAULT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `unidad_medida` enum('KG','LTS','UND') NOT NULL DEFAULT 'KG',
  `orden` smallint(5) unsigned NOT NULL DEFAULT 0,
  `precio_produccion` decimal(12,2) NOT NULL DEFAULT 0.00,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_cat_nombre` (`nombre`),
  UNIQUE KEY `uq_prod_cat_producto` (`producto_id`),
  KEY `ix_prod_cat_orden` (`activo`,`orden`,`id`),
  CONSTRAINT `fk_prod_cat_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `ck_prod_cat_nombre` CHECK (char_length(trim(`nombre`)) >= 2),
  CONSTRAINT `ck_prod_cat_orden` CHECK (`orden` <= 9999),
  CONSTRAINT `ck_prod_cat_precio` CHECK (`precio_produccion` >= 0),
  CONSTRAINT `ck_prod_cat_activo` CHECK (`activo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prod_control_leche_diario` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `ventas_leche` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cortesias_leche` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vales_leche` decimal(10,2) NOT NULL DEFAULT 0.00,
  `leche_faltante` decimal(10,2) NOT NULL DEFAULT 0.00,
  `registrado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_cld_fecha` (`fecha`),
  KEY `fk_prod_cld_user` (`registrado_por`),
  CONSTRAINT `fk_prod_cld_user` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_prod_cld_vals` CHECK (`ventas_leche` >= 0 and `cortesias_leche` >= 0 and `vales_leche` >= 0 and `leche_faltante` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prod_control_leche_productor` (
  `fecha` date NOT NULL,
  `productor_id` int(10) unsigned NOT NULL,
  `densidad` decimal(12,3) DEFAULT NULL,
  `faltante` decimal(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`fecha`,`productor_id`),
  KEY `fk_pclp_productor` (`productor_id`),
  CONSTRAINT `fk_pclp_productor` FOREIGN KEY (`productor_id`) REFERENCES `productores` (`id`),
  CONSTRAINT `ck_pclp_vals` CHECK ((`densidad` is null or `densidad` >= 0) and `faltante` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produccion_costos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `litros_recibidos` decimal(12,3) NOT NULL,
  `litros_procesados` decimal(12,3) NOT NULL,
  `precio_litro_usd` decimal(12,4) NOT NULL,
  `costo_leche_usd` decimal(12,2) NOT NULL,
  `nomina_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `personal_extra_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `transporte_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `operativo_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `otros_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gasto_total_usd` decimal(12,2) NOT NULL,
  `kg_total` decimal(12,3) NOT NULL,
  `costo_comun_kg_usd` decimal(12,4) NOT NULL,
  `margen_pct` decimal(5,2) NOT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_costos_fecha` (`fecha`),
  KEY `fk_costos_usuario` (`registrado_por`),
  CONSTRAINT `fk_costos_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_costos_litros` CHECK (`litros_recibidos` >= 0 and `litros_procesados` > 0),
  CONSTRAINT `ck_costos_montos` CHECK (`costo_leche_usd` >= 0 and `nomina_usd` >= 0 and `personal_extra_usd` >= 0 and `transporte_usd` >= 0 and `operativo_usd` >= 0 and `otros_usd` >= 0 and `gasto_total_usd` >= 0 and `kg_total` > 0 and `costo_comun_kg_usd` >= 0),
  CONSTRAINT `ck_costos_margen` CHECK (`margen_pct` >= 0 and `margen_pct` <= 500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produccion_costos_quesos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `costo_id` int(10) unsigned NOT NULL,
  `orden` tinyint(3) unsigned NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `litros_ref` decimal(10,3) NOT NULL,
  `kg_ref` decimal(10,3) NOT NULL,
  `litros_dia` decimal(12,3) NOT NULL,
  `kg_dia` decimal(12,3) NOT NULL,
  `sal_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `empaque_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `otros_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `costo_total_usd` decimal(12,2) NOT NULL,
  `costo_kg_usd` decimal(12,4) NOT NULL,
  `precio_venta_kg_usd` decimal(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_costos_quesos_orden` (`costo_id`,`orden`),
  CONSTRAINT `fk_costos_quesos_costo` FOREIGN KEY (`costo_id`) REFERENCES `produccion_costos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_costos_quesos_ref` CHECK (`litros_ref` > 0 and `kg_ref` > 0 and `litros_dia` >= 0 and `kg_dia` >= 0),
  CONSTRAINT `ck_costos_quesos_extra` CHECK (`sal_usd` >= 0 and `empaque_usd` >= 0 and `otros_usd` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produccion_diaria` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `producto_id` int(10) unsigned NOT NULL,
  `cantidad` decimal(12,3) NOT NULL,
  `cantidad_secundaria` int(11) NOT NULL DEFAULT 0,
  `peso_bruto` decimal(12,3) DEFAULT NULL,
  `tara_unitaria` decimal(12,3) NOT NULL DEFAULT 0.000,
  `tara_total` decimal(12,3) NOT NULL DEFAULT 0.000,
  `precio_unit_usd` decimal(12,2) NOT NULL,
  `total_usd` decimal(12,2) GENERATED ALWAYS AS (round(`cantidad` * `precio_unit_usd`,2)) STORED,
  `distribuido` decimal(12,3) NOT NULL DEFAULT 0.000,
  `distribuido_secundario` int(11) NOT NULL DEFAULT 0,
  `pendiente` decimal(12,3) GENERATED ALWAYS AS (`cantidad` - `distribuido`) STORED,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_diaria_fecha_producto` (`fecha`,`producto_id`),
  KEY `ix_prod_diaria_producto` (`producto_id`),
  KEY `fk_prod_diaria_usuario` (`registrado_por`),
  CONSTRAINT `fk_prod_diaria_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `fk_prod_diaria_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_prod_diaria_cantidad` CHECK (`cantidad` > 0 and `cantidad` <= 100000),
  CONSTRAINT `ck_prod_diaria_precio` CHECK (`precio_unit_usd` >= 0),
  CONSTRAINT `ck_prod_diaria_dist` CHECK (`distribuido` >= 0 and `distribuido` <= `cantidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produccion_distribuciones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `produccion_diaria_id` int(10) unsigned NOT NULL,
  `almacen_id` int(10) unsigned NOT NULL,
  `cantidad` decimal(12,3) NOT NULL,
  `cantidad_secundaria` int(11) NOT NULL DEFAULT 0,
  `usuario_id` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_dist_produccion` (`produccion_diaria_id`),
  KEY `ix_dist_almacen` (`almacen_id`,`creado_en`),
  KEY `fk_dist_usuario` (`usuario_id`),
  CONSTRAINT `fk_dist_almacen` FOREIGN KEY (`almacen_id`) REFERENCES `almacenes` (`id`),
  CONSTRAINT `fk_dist_produccion` FOREIGN KEY (`produccion_diaria_id`) REFERENCES `produccion_diaria` (`id`),
  CONSTRAINT `fk_dist_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_dist_cantidad` CHECK (`cantidad` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_distribuciones_no_update BEFORE UPDATE ON produccion_distribuciones FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las distribuciones son de solo insercion';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_distribuciones_no_delete BEFORE DELETE ON produccion_distribuciones FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las distribuciones son de solo insercion';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `producciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `producto_id` int(10) unsigned NOT NULL,
  `litros_leche` decimal(12,3) NOT NULL,
  `factor_l_kg` decimal(8,4) NOT NULL,
  `kg_esperados` decimal(12,3) GENERATED ALWAYS AS (round(`litros_leche` / `factor_l_kg`,3)) STORED,
  `cuajada_kg` decimal(12,3) DEFAULT NULL,
  `rend_cuajada_ref_kg` decimal(8,3) DEFAULT NULL,
  `rend_masa_min_kg` decimal(8,3) DEFAULT NULL,
  `rend_masa_max_kg` decimal(8,3) DEFAULT NULL,
  `masa_min_kg` decimal(12,3) GENERATED ALWAYS AS (round(`cuajada_kg` * `rend_masa_min_kg` / `rend_cuajada_ref_kg`,3)) STORED,
  `masa_max_kg` decimal(12,3) GENERATED ALWAYS AS (round(`cuajada_kg` * `rend_masa_max_kg` / `rend_cuajada_ref_kg`,3)) STORED,
  `kg_reales` decimal(12,3) DEFAULT NULL,
  `precio_litro_prom_usd` decimal(12,4) DEFAULT NULL,
  `costo_leche_usd` decimal(12,2) GENERATED ALWAYS AS (round(`litros_leche` * `precio_litro_prom_usd`,2)) STORED,
  `observaciones` varchar(255) DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_producciones_fecha` (`fecha`),
  KEY `fk_producciones_producto` (`producto_id`),
  KEY `fk_producciones_usuario` (`registrado_por`),
  CONSTRAINT `fk_producciones_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `fk_producciones_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_producciones_litros` CHECK (`litros_leche` > 0),
  CONSTRAINT `ck_producciones_factor` CHECK (`factor_l_kg` > 0 and `factor_l_kg` <= 50),
  CONSTRAINT `ck_producciones_kg_real` CHECK (`kg_reales` is null or `kg_reales` >= 0),
  CONSTRAINT `ck_producciones_cuajada` CHECK (`cuajada_kg` is null and `rend_cuajada_ref_kg` is null and `rend_masa_min_kg` is null and `rend_masa_max_kg` is null or `cuajada_kg` > 0 and `rend_cuajada_ref_kg` > 0 and `rend_masa_min_kg` > 0 and `rend_masa_max_kg` >= `rend_masa_min_kg`),
  CONSTRAINT `ck_producciones_precio` CHECK (`precio_litro_prom_usd` is null or `precio_litro_prom_usd` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `documento` varchar(12) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `correo` varchar(100) DEFAULT NULL,
  `direccion` varchar(160) DEFAULT NULL,
  `precio_litro_usd` decimal(12,4) NOT NULL,
  `credito_dias` smallint(5) unsigned NOT NULL DEFAULT 7,
  `dia_cierre` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `grupo_cierre` varchar(50) DEFAULT NULL,
  `tolerancia_pago` enum('bajo','medio','alto') NOT NULL DEFAULT 'medio',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_productores_nombre` (`nombre`),
  UNIQUE KEY `uq_productores_documento` (`documento`),
  KEY `ix_productores_dia_cierre` (`activo`,`dia_cierre`),
  KEY `ix_productores_grupo_cierre` (`grupo_cierre`),
  CONSTRAINT `ck_productores_documento` CHECK (`documento` is null or `documento` regexp '^[VEJGP]-[0-9]{6,9}(-[0-9])?$'),
  CONSTRAINT `ck_productores_credito` CHECK (`credito_dias` <= 90),
  CONSTRAINT `ck_productores_activo` CHECK (`activo` in (0,1)),
  CONSTRAINT `ck_productores_dia_cierre` CHECK (`dia_cierre` between 1 and 7),
  CONSTRAINT `ck_productores_precio` CHECK (`precio_litro_usd` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productores_abonos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `productor_id` int(10) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `semana_inicio` date NOT NULL,
  `tipo` enum('efectivo','pago_movil','transferencia','mercancia','vale','otro','pm_trans') NOT NULL,
  `moneda` enum('USD','VES') NOT NULL DEFAULT 'USD',
  `monto` decimal(12,2) NOT NULL,
  `tasa_bs_por_usd` decimal(14,4) DEFAULT NULL,
  `monto_usd` decimal(12,2) NOT NULL,
  `referencia` varchar(40) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `cierre_id` int(10) unsigned DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `anulado_en` datetime DEFAULT NULL,
  `anulado_por` int(10) unsigned DEFAULT NULL,
  `motivo_anulacion` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_abonos_productor_semana` (`productor_id`,`semana_inicio`),
  KEY `ix_abonos_cierre` (`cierre_id`),
  KEY `fk_abonos_usuario` (`registrado_por`),
  KEY `ix_abonos_semana` (`semana_inicio`),
  KEY `fk_abonos_anulador` (`anulado_por`),
  CONSTRAINT `fk_abonos_anulador` FOREIGN KEY (`anulado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_abonos_cierre` FOREIGN KEY (`cierre_id`) REFERENCES `productores_cierres` (`id`),
  CONSTRAINT `fk_abonos_productor` FOREIGN KEY (`productor_id`) REFERENCES `productores` (`id`),
  CONSTRAINT `fk_abonos_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_abonos_montos` CHECK (`monto` > 0 and `monto_usd` > 0),
  CONSTRAINT `ck_abonos_tasa` CHECK (`moneda` = 'USD' and `tasa_bs_por_usd` is null or `moneda` = 'VES' and `tasa_bs_por_usd` > 0),
  CONSTRAINT `ck_abonos_anulacion` CHECK (`anulado_en` is null and `anulado_por` is null and `motivo_anulacion` is null or `anulado_en` is not null and `anulado_por` is not null and `motivo_anulacion` is not null),
  CONSTRAINT `ck_abonos_referencia_banco` CHECK (`tipo` not in ('pago_movil','transferencia') or `referencia` is not null and trim(`referencia`) <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_abonos_inicio_ciclo BEFORE INSERT ON productores_abonos FOR EACH ROW
BEGIN
    IF WEEKDAY(NEW.semana_inicio) <> (SELECT MOD(p.dia_cierre, 7) FROM productores p WHERE p.id = NEW.productor_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago no aplica a un ciclo valido del productor';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_abonos_tipo_vigente BEFORE INSERT ON productores_abonos FOR EACH ROW
BEGIN
    IF NEW.tipo = 'pm_trans' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pm_trans es historico: registre Pago Movil o Transferencia';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_abonos_inmutable
BEFORE UPDATE ON productores_abonos
FOR EACH ROW
BEGIN
    IF OLD.anulado_en IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago ya esta anulado';
    END IF;
    IF NOT (NEW.productor_id = OLD.productor_id AND NEW.fecha = OLD.fecha AND NEW.semana_inicio = OLD.semana_inicio
            AND NEW.tipo = OLD.tipo AND NEW.moneda = OLD.moneda AND NEW.monto = OLD.monto
            AND NEW.monto_usd = OLD.monto_usd AND NEW.tasa_bs_por_usd <=> OLD.tasa_bs_por_usd
            AND NEW.registrado_por = OLD.registrado_por) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los datos economicos del pago son inmutables';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_abonos_no_delete BEFORE DELETE ON productores_abonos FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos no se eliminan: se anulan';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productores_cierres` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `productor_id` int(10) unsigned NOT NULL,
  `semana_inicio` date NOT NULL,
  `semana_fin` date NOT NULL,
  `litros` decimal(12,3) NOT NULL,
  `bruto_usd` decimal(12,2) NOT NULL,
  `traslado_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `abonos_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `arrastre_anterior_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `saldo_usd` decimal(12,2) NOT NULL,
  `pagado_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estado` enum('cerrada','parcial','pagada','arrastrada','anulada') NOT NULL DEFAULT 'cerrada',
  `notas` varchar(500) DEFAULT NULL,
  `cerrado_por` int(10) unsigned DEFAULT NULL,
  `cerrado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `pagado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cierres_productor_semana` (`productor_id`,`semana_inicio`),
  KEY `ix_cierres_semana` (`semana_inicio`),
  KEY `fk_cierres_usuario` (`cerrado_por`),
  KEY `ix_cierres_productor_rango` (`productor_id`,`semana_inicio`,`semana_fin`),
  KEY `ix_cierres_estado` (`estado`,`productor_id`,`semana_inicio`),
  CONSTRAINT `fk_cierres_productor` FOREIGN KEY (`productor_id`) REFERENCES `productores` (`id`),
  CONSTRAINT `fk_cierres_usuario` FOREIGN KEY (`cerrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_cierres_rango` CHECK (`semana_fin` = `semana_inicio` + interval 6 day),
  CONSTRAINT `ck_cierres_montos` CHECK (`litros` >= 0 and `bruto_usd` >= 0 and `traslado_usd` >= 0 and `abonos_usd` >= 0 and `pagado_usd` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_cierres_inicio_ciclo BEFORE INSERT ON productores_cierres FOR EACH ROW
BEGIN
    IF WEEKDAY(NEW.semana_inicio) <> (SELECT MOD(p.dia_cierre, 7) FROM productores p WHERE p.id = NEW.productor_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cierre no inicia en el dia de apertura del ciclo del productor';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productores_entregas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `productor_id` int(10) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `litros` decimal(12,3) NOT NULL,
  `precio_litro_usd` decimal(12,4) NOT NULL,
  `subtotal_usd` decimal(12,2) GENERATED ALWAYS AS (round(`litros` * `precio_litro_usd`,2)) STORED,
  `traslado_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cuajada_kg` decimal(12,3) NOT NULL DEFAULT 0.000,
  `observaciones` varchar(255) DEFAULT NULL,
  `cierre_id` int(10) unsigned DEFAULT NULL,
  `registrado_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entregas_productor_fecha` (`productor_id`,`fecha`),
  KEY `ix_entregas_fecha` (`fecha`),
  KEY `ix_entregas_cierre` (`cierre_id`),
  KEY `fk_entregas_usuario` (`registrado_por`),
  CONSTRAINT `fk_entregas_cierre` FOREIGN KEY (`cierre_id`) REFERENCES `productores_cierres` (`id`),
  CONSTRAINT `fk_entregas_productor` FOREIGN KEY (`productor_id`) REFERENCES `productores` (`id`),
  CONSTRAINT `fk_entregas_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_entregas_litros` CHECK (`litros` > 0 and `litros` <= 100000),
  CONSTRAINT `ck_entregas_extras` CHECK (`traslado_usd` >= 0 and `cuajada_kg` >= 0),
  CONSTRAINT `ck_entregas_precio` CHECK (`precio_litro_usd` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_entregas_semana_abierta_ins BEFORE INSERT ON productores_entregas FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM productores_cierres c
        WHERE c.productor_id = NEW.productor_id
          AND NEW.fecha BETWEEN c.semana_inicio AND c.semana_fin
          AND c.estado <> 'anulada'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La semana de esta entrega ya esta cerrada';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_entregas_semana_abierta_upd BEFORE UPDATE ON productores_entregas FOR EACH ROW
BEGIN
    IF OLD.cierre_id IS NOT NULL
       AND (SELECT estado FROM productores_cierres WHERE id = OLD.cierre_id) <> 'anulada'
       AND NOT (NEW.cierre_id <=> OLD.cierre_id AND NEW.litros = OLD.litros
                AND NEW.precio_litro_usd = OLD.precio_litro_usd AND NEW.fecha = OLD.fecha
                AND NEW.productor_id = OLD.productor_id AND NEW.traslado_usd = OLD.traslado_usd) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La entrega pertenece a una semana cerrada';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER trg_entregas_semana_abierta_del BEFORE DELETE ON productores_entregas FOR EACH ROW
BEGIN
    IF OLD.cierre_id IS NOT NULL
       AND (SELECT estado FROM productores_cierres WHERE id = OLD.cierre_id) <> 'anulada' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La entrega pertenece a una semana cerrada';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productores_precios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `productor_id` int(10) unsigned NOT NULL,
  `precio_anterior_usd` decimal(12,4) DEFAULT NULL,
  `precio_nuevo_usd` decimal(12,4) NOT NULL,
  `motivo` varchar(160) DEFAULT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_precios_productor` (`productor_id`,`creado_en`),
  KEY `fk_precios_usuario` (`usuario_id`),
  CONSTRAINT `fk_precios_productor` FOREIGN KEY (`productor_id`) REFERENCES `productores` (`id`),
  CONSTRAINT `fk_precios_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_precios_nuevo` CHECK (`precio_nuevo_usd` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) DEFAULT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `unidad` enum('kg','unidad','litro') NOT NULL DEFAULT 'kg',
  `unidad_medida` enum('KG','LTS','UND') NOT NULL DEFAULT 'KG',
  `unidad_secundaria` varchar(20) DEFAULT NULL,
  `precio_usd` decimal(12,2) NOT NULL,
  `stock` decimal(12,3) NOT NULL DEFAULT 0.000,
  `stock_secundario` int(11) NOT NULL DEFAULT 0,
  `umbral_alerta` decimal(12,3) NOT NULL DEFAULT 0.000,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_productos_nombre` (`nombre`),
  UNIQUE KEY `uq_productos_codigo` (`codigo`),
  CONSTRAINT `ck_productos_precio` CHECK (`precio_usd` >= 0),
  CONSTRAINT `ck_productos_stock` CHECK (`stock` >= 0),
  CONSTRAINT `ck_productos_umbral` CHECK (`umbral_alerta` >= 0),
  CONSTRAINT `ck_productos_activo` CHECK (`activo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tasas_cambio` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tasa_bs_por_usd` decimal(14,4) NOT NULL,
  `vigente_desde` datetime NOT NULL,
  `fuente` varchar(40) NOT NULL DEFAULT 'BCV',
  `registrada_por` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_tasas_vigencia` (`vigente_desde`),
  KEY `fk_tasas_usuario` (`registrada_por`),
  CONSTRAINT `fk_tasas_usuario` FOREIGN KEY (`registrada_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_tasas_positiva` CHECK (`tasa_bs_por_usd` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empleado_id` int(10) unsigned DEFAULT NULL,
  `nombre` varchar(80) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expiracion` datetime DEFAULT NULL,
  `rol` enum('maestro','admin','empleado') NOT NULL DEFAULT 'empleado',
  `permisos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`permisos`)),
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `acceso_movil` tinyint(1) NOT NULL DEFAULT 0,
  `eliminado_en` datetime DEFAULT NULL,
  `eliminado_por` int(10) unsigned DEFAULT NULL,
  `ultimo_acceso` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_email` (`email`),
  UNIQUE KEY `uq_usuarios_empleado` (`empleado_id`),
  UNIQUE KEY `uq_usuarios_reset_token` (`reset_token`),
  KEY `fk_usuarios_eliminador` (`eliminado_por`),
  CONSTRAINT `fk_usuarios_eliminador` FOREIGN KEY (`eliminado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_usuarios_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados` (`id`),
  CONSTRAINT `ck_usuarios_email` CHECK (`email` = lcase(`email`) and `email` like '_%@_%._%'),
  CONSTRAINT `ck_usuarios_hash` CHECK (char_length(`password_hash`) >= 60),
  CONSTRAINT `ck_usuarios_nombre` CHECK (char_length(trim(`nombre`)) >= 2),
  CONSTRAINT `ck_usuarios_activo` CHECK (`activo` in (0,1)),
  CONSTRAINT `ck_usuarios_movil` CHECK (`acceso_movil` in (0,1)),
  CONSTRAINT `ck_usuarios_reset` CHECK ((`reset_token` is null) = (`reset_expiracion` is null)),
  CONSTRAINT `ck_usuarios_baja` CHECK (`eliminado_en` is null and `eliminado_por` is null or `eliminado_en` is not null and `activo` = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_gas_cargos_terceros` AS SELECT
 1 AS `linea_id`,
  1 AS `despacho_id`,
  1 AS `fecha_salida`,
  1 AS `semana_inicio`,
  1 AS `cilindro_id`,
  1 AS `serial`,
  1 AS `tipo_tercero`,
  1 AS `empleado_id`,
  1 AS `transportista_id`,
  1 AS `productor_id`,
  1 AS `propietario_nombre`,
  1 AS `subtotal_usd` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_gas_egresos_semana` AS SELECT
 1 AS `semana_inicio`,
  1 AS `semana_fin`,
  1 AS `abonos`,
  1 AS `egreso_usd` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_gas_inversion_semanal` AS SELECT
 1 AS `semana_inicio`,
  1 AS `semana_fin`,
  1 AS `proveedor_id`,
  1 AS `proveedor`,
  1 AS `despachos`,
  1 AS `cilindros`,
  1 AS `invertido_usd`,
  1 AS `pagado_usd`,
  1 AS `saldo_usd` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ven_catalogo_publicidad` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` int(10) unsigned NOT NULL,
  `precio_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `foto_ruta` varchar(180) DEFAULT NULL,
  `mostrar_pantalla` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ven_cat_producto` (`producto_id`),
  CONSTRAINT `fk_ven_cat_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `ck_ven_cat_precio` CHECK (`precio_usd` >= 0),
  CONSTRAINT `ck_ven_cat_tv` CHECK (`mostrar_pantalla` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ven_catalogo_tasa` (
  `id` tinyint(3) unsigned NOT NULL,
  `tasa_bs` decimal(12,4) NOT NULL,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `actualizado_por` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_ven_cat_tasa_user` (`actualizado_por`),
  CONSTRAINT `fk_ven_cat_tasa_user` FOREIGN KEY (`actualizado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ck_ven_cat_tasa_id` CHECK (`id` = 1),
  CONSTRAINT `ck_ven_cat_tasa_valor` CHECK (`tasa_bs` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `venta_id` int(10) unsigned NOT NULL,
  `producto_id` int(10) unsigned NOT NULL,
  `lote_id` int(10) unsigned DEFAULT NULL,
  `cantidad` decimal(12,3) NOT NULL,
  `cantidad_secundaria` int(11) NOT NULL DEFAULT 0,
  `peso_bruto` decimal(12,3) DEFAULT NULL,
  `tara_unitaria` decimal(12,3) NOT NULL DEFAULT 0.000,
  `tara_total` decimal(12,3) NOT NULL DEFAULT 0.000,
  `precio_unit_usd` decimal(12,2) NOT NULL,
  `subtotal_usd` decimal(12,2) GENERATED ALWAYS AS (round(`cantidad` * `precio_unit_usd`,2)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_items_venta_producto_lote` (`venta_id`,`producto_id`,`lote_id`),
  KEY `fk_items_producto` (`producto_id`),
  KEY `fk_items_lote` (`lote_id`),
  CONSTRAINT `fk_items_lote` FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`),
  CONSTRAINT `fk_items_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `fk_items_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `ck_items_cantidad` CHECK (`cantidad` > 0),
  CONSTRAINT `ck_items_precio` CHECK (`precio_unit_usd` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ventas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(20) NOT NULL,
  `cliente_id` int(10) unsigned NOT NULL,
  `ventas_cliente_id` int(10) unsigned DEFAULT NULL,
  `cliente_nombre` varchar(120) DEFAULT NULL,
  `tipo_cliente` enum('generico','frecuente','exclusivo') NOT NULL DEFAULT 'generico',
  `entidad_tipo` enum('productor','empleado','cxc','transportista') DEFAULT NULL,
  `entidad_id` int(10) unsigned DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `moneda_pago` enum('USD','VES') NOT NULL,
  `metodo_pago` enum('efectivo','pago_movil','transferencia','punto','credito','cortesia','mercancia_descuento') NOT NULL DEFAULT 'efectivo',
  `referencia` varchar(40) DEFAULT NULL,
  `descripcion_pago` varchar(255) DEFAULT NULL,
  `semana_aplicacion` date DEFAULT NULL,
  `tasa_id` int(10) unsigned NOT NULL,
  `tasa_bs_por_usd` decimal(14,4) NOT NULL,
  `total_usd` decimal(12,2) NOT NULL,
  `total_bs` decimal(12,2) NOT NULL,
  `estado` enum('emitida','anulada') NOT NULL DEFAULT 'emitida',
  `emitida_por` int(10) unsigned NOT NULL,
  `anulada_por` int(10) unsigned DEFAULT NULL,
  `anulada_en` datetime DEFAULT NULL,
  `motivo_anulacion` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ventas_numero` (`numero`),
  KEY `ix_ventas_fecha` (`fecha`),
  KEY `ix_ventas_cliente` (`cliente_id`,`fecha`),
  KEY `fk_ventas_tasa` (`tasa_id`),
  KEY `fk_ventas_emisor` (`emitida_por`),
  KEY `fk_ventas_anulador` (`anulada_por`),
  KEY `ix_ventas_entidad` (`tipo_cliente`,`entidad_tipo`,`entidad_id`),
  KEY `fk_ventas_vcli` (`ventas_cliente_id`),
  CONSTRAINT `fk_ventas_anulador` FOREIGN KEY (`anulada_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_ventas_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_ventas_emisor` FOREIGN KEY (`emitida_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_ventas_tasa` FOREIGN KEY (`tasa_id`) REFERENCES `tasas_cambio` (`id`),
  CONSTRAINT `fk_ventas_vcli` FOREIGN KEY (`ventas_cliente_id`) REFERENCES `ventas_clientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_ventas_montos` CHECK (`total_usd` >= 0 and `total_bs` >= 0 and `tasa_bs_por_usd` > 0),
  CONSTRAINT `ck_ventas_anulacion` CHECK (`estado` = 'emitida' and `anulada_por` is null and `anulada_en` is null or `estado` = 'anulada' and `anulada_por` is not null and `anulada_en` is not null and `motivo_anulacion` is not null),
  CONSTRAINT `ck_ventas_entidad` CHECK (`tipo_cliente` in ('generico','frecuente') and `entidad_tipo` is null and `entidad_id` is null or `tipo_cliente` = 'exclusivo' and `entidad_tipo` is not null and `entidad_id` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ventas_clientes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `documento` varchar(14) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `direccion` varchar(160) DEFAULT NULL,
  `tipo` enum('frecuente','exclusivo') NOT NULL DEFAULT 'frecuente',
  `cxc_cliente_id` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vcli_documento` (`documento`),
  KEY `ix_vcli_tipo` (`tipo`,`nombre`),
  KEY `fk_vcli_cxc` (`cxc_cliente_id`),
  CONSTRAINT `fk_vcli_cxc` FOREIGN KEY (`cxc_cliente_id`) REFERENCES `cxc_clientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_vcli_nombre` CHECK (char_length(trim(`nombre`)) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
DELIMITER ;;
CREATE FUNCTION `gas_estado_deuda`(p_costo DECIMAL(12,2), p_pagado DECIMAL(12,2)) RETURNS varchar(20) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
    NO SQL
    DETERMINISTIC
BEGIN
    RETURN CASE
        WHEN p_pagado <= 0 THEN 'pendiente'
        WHEN p_pagado < p_costo THEN 'saldo_restante'
        ELSE 'cancelado'
    END;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
DELIMITER ;;
CREATE PROCEDURE `gas_recalcular_despacho`(IN p_despacho_id INT UNSIGNED)
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
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50001 DROP VIEW IF EXISTS `v_gas_cargos_terceros`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `v_gas_cargos_terceros` AS select `dc`.`id` AS `linea_id`,`dc`.`despacho_id` AS `despacho_id`,`d`.`fecha_salida` AS `fecha_salida`,`d`.`semana_inicio` AS `semana_inicio`,`dc`.`cilindro_id` AS `cilindro_id`,`c`.`serial` AS `serial`,`dc`.`tipo_tercero` AS `tipo_tercero`,`dc`.`empleado_id` AS `empleado_id`,`dc`.`transportista_id` AS `transportista_id`,`dc`.`productor_id` AS `productor_id`,`dc`.`propietario_nombre` AS `propietario_nombre`,`dc`.`subtotal_usd` AS `subtotal_usd` from ((`gas_despacho_cilindros` `dc` join `gas_despachos` `d` on(`d`.`id` = `dc`.`despacho_id`)) join `gas_cilindros` `c` on(`c`.`id` = `dc`.`cilindro_id`)) where `dc`.`cargo_estado` = 'pendiente' */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_gas_egresos_semana`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `v_gas_egresos_semana` AS select `g`.`semana_inicio` AS `semana_inicio`,`g`.`semana_inicio` + interval 6 day AS `semana_fin`,count(0) AS `abonos`,sum(`g`.`monto_usd`) AS `egreso_usd` from `gas_pagos` `g` where `g`.`anulado_en` is null group by `g`.`semana_inicio`,`g`.`semana_inicio` + interval 6 day */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_gas_inversion_semanal`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `v_gas_inversion_semanal` AS select `d`.`semana_inicio` AS `semana_inicio`,`d`.`semana_inicio` + interval 6 day AS `semana_fin`,`d`.`proveedor_id` AS `proveedor_id`,`p`.`nombre` AS `proveedor`,count(0) AS `despachos`,sum(`d`.`cantidad`) AS `cilindros`,sum(`d`.`costo_total_usd`) AS `invertido_usd`,sum(`d`.`pagado_usd`) AS `pagado_usd`,sum(`d`.`saldo_usd`) AS `saldo_usd` from (`gas_despachos` `d` join `gas_proveedores` `p` on(`p`.`id` = `d`.`proveedor_id`)) group by `d`.`semana_inicio`,`d`.`proveedor_id`,`p`.`nombre`,`d`.`semana_inicio` + interval 6 day */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- Catálogos de sistema

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

/*!40000 ALTER TABLE `bancos_catalogo` DISABLE KEYS */;
INSERT INTO `bancos_catalogo` (`id`, `nombre_banco`, `creado_en`) VALUES (1,'Banco de Venezuela','2026-11-02 23:27:22'),(2,'Banesco','2026-11-02 23:27:22'),(3,'Banco Provincial','2026-11-02 23:27:22'),(4,'BNC','2026-11-02 23:27:22'),(5,'Bancamiga','2026-11-02 23:27:22'),(6,'Banco del Tesoro','2026-11-02 23:27:22'),(7,'Mercantil','2026-11-02 23:27:22'),(8,'Bancaribe','2026-11-02 23:27:22'),(9,'Banco Exterior','2026-11-02 23:27:22'),(10,'Banco Activo','2026-11-02 23:27:22'),(11,'Banco Plaza','2026-11-02 23:27:22'),(12,'Banco Caroní','2026-11-02 23:27:22'),(13,'Banplus','2026-11-02 23:27:22'),(14,'100% Banco','2026-11-02 23:27:22'),(15,'Banco Venezolano de Crédito','2026-11-02 23:27:22');
/*!40000 ALTER TABLE `bancos_catalogo` ENABLE KEYS */;

/*!40000 ALTER TABLE `gas_correlativos` DISABLE KEYS */;
INSERT INTO `gas_correlativos` (`clave`, `ultimo`) VALUES ('cilindro',0),('despacho',0);
/*!40000 ALTER TABLE `gas_correlativos` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

