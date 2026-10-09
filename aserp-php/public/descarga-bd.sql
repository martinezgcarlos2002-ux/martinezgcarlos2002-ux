--
-- PostgreSQL database dump
--

\restrict amy7p7g9k6D2ePCButegDhn8f7BDZmfsuDRvZDJN7LXsqA9neRk2NCHJampNoez

-- Dumped from database version 16.10
-- Dumped by pg_dump version 16.10

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

ALTER TABLE IF EXISTS ONLY public.vehiculos DROP CONSTRAINT IF EXISTS vehiculos_conductor_actual_id_fkey;
ALTER TABLE IF EXISTS ONLY public.transporte DROP CONSTRAINT IF EXISTS transporte_conductor_actual_id_empleados_id_fk;
ALTER TABLE IF EXISTS ONLY public.transporte_asignaciones DROP CONSTRAINT IF EXISTS transporte_asignaciones_transporte_id_transporte_id_fk;
ALTER TABLE IF EXISTS ONLY public.transporte_asignaciones DROP CONSTRAINT IF EXISTS transporte_asignaciones_empleado_id_empleados_id_fk;
ALTER TABLE IF EXISTS ONLY public.proveedores_pagos DROP CONSTRAINT IF EXISTS proveedores_pagos_proveedor_id_fkey;
ALTER TABLE IF EXISTS ONLY public.proveedores_entregas DROP CONSTRAINT IF EXISTS proveedores_entregas_proveedor_id_fkey;
ALTER TABLE IF EXISTS ONLY public.productores_precios_historial DROP CONSTRAINT IF EXISTS productores_precios_historial_usuario_id_usuarios_id_fk;
ALTER TABLE IF EXISTS ONLY public.productores_precios_historial DROP CONSTRAINT IF EXISTS productores_precios_historial_productor_id_productores_id_fk;
ALTER TABLE IF EXISTS ONLY public.productores_entregas DROP CONSTRAINT IF EXISTS productores_entregas_productor_id_productores_id_fk;
ALTER TABLE IF EXISTS ONLY public.productores_descuentos DROP CONSTRAINT IF EXISTS productores_descuentos_productor_id_productores_id_fk;
ALTER TABLE IF EXISTS ONLY public.productores_abonos DROP CONSTRAINT IF EXISTS productores_abonos_productor_id_productores_id_fk;
ALTER TABLE IF EXISTS ONLY public.lotes DROP CONSTRAINT IF EXISTS lotes_producto_id_productos_id_fk;
ALTER TABLE IF EXISTS ONLY public.detalle_factura DROP CONSTRAINT IF EXISTS detalle_factura_producto_id_productos_id_fk;
ALTER TABLE IF EXISTS ONLY public.detalle_factura DROP CONSTRAINT IF EXISTS detalle_factura_factura_id_facturas_id_fk;
ALTER TABLE IF EXISTS ONLY public.compras DROP CONSTRAINT IF EXISTS compras_proveedor_id_proveedores_id_fk;
ALTER TABLE IF EXISTS ONLY public.vehiculos DROP CONSTRAINT IF EXISTS vehiculos_pkey;
ALTER TABLE IF EXISTS ONLY public.usuarios DROP CONSTRAINT IF EXISTS usuarios_pkey;
ALTER TABLE IF EXISTS ONLY public.usuarios DROP CONSTRAINT IF EXISTS usuarios_email_unique;
ALTER TABLE IF EXISTS ONLY public.transporte DROP CONSTRAINT IF EXISTS transporte_pkey;
ALTER TABLE IF EXISTS ONLY public.transporte_asignaciones DROP CONSTRAINT IF EXISTS transporte_asignaciones_pkey;
ALTER TABLE IF EXISTS ONLY public.tasa_cambio DROP CONSTRAINT IF EXISTS tasa_cambio_pkey;
ALTER TABLE IF EXISTS ONLY public.proveedores DROP CONSTRAINT IF EXISTS proveedores_pkey;
ALTER TABLE IF EXISTS ONLY public.proveedores_pagos DROP CONSTRAINT IF EXISTS proveedores_pagos_pkey;
ALTER TABLE IF EXISTS ONLY public.proveedores_entregas DROP CONSTRAINT IF EXISTS proveedores_entregas_pkey;
ALTER TABLE IF EXISTS ONLY public.productos DROP CONSTRAINT IF EXISTS productos_pkey;
ALTER TABLE IF EXISTS ONLY public.productos DROP CONSTRAINT IF EXISTS productos_codigo_unique;
ALTER TABLE IF EXISTS ONLY public.productores_precios_historial DROP CONSTRAINT IF EXISTS productores_precios_historial_pkey;
ALTER TABLE IF EXISTS ONLY public.productores DROP CONSTRAINT IF EXISTS productores_pkey;
ALTER TABLE IF EXISTS ONLY public.productores_entregas DROP CONSTRAINT IF EXISTS productores_entregas_pkey;
ALTER TABLE IF EXISTS ONLY public.productores_descuentos DROP CONSTRAINT IF EXISTS productores_descuentos_pkey;
ALTER TABLE IF EXISTS ONLY public.productores_abonos DROP CONSTRAINT IF EXISTS productores_abonos_pkey;
ALTER TABLE IF EXISTS ONLY public.movimiento_caja DROP CONSTRAINT IF EXISTS movimiento_caja_pkey;
ALTER TABLE IF EXISTS ONLY public.lotes DROP CONSTRAINT IF EXISTS lotes_pkey;
ALTER TABLE IF EXISTS ONLY public.facturas DROP CONSTRAINT IF EXISTS facturas_pkey;
ALTER TABLE IF EXISTS ONLY public.empleados DROP CONSTRAINT IF EXISTS empleados_pkey;
ALTER TABLE IF EXISTS ONLY public.detalle_factura DROP CONSTRAINT IF EXISTS detalle_factura_pkey;
ALTER TABLE IF EXISTS ONLY public.compras DROP CONSTRAINT IF EXISTS compras_pkey;
ALTER TABLE IF EXISTS ONLY public.bitacora_sesiones DROP CONSTRAINT IF EXISTS bitacora_sesiones_pkey;
ALTER TABLE IF EXISTS ONLY public.bancos DROP CONSTRAINT IF EXISTS bancos_pkey;
ALTER TABLE IF EXISTS ONLY public.auditoria_sistema DROP CONSTRAINT IF EXISTS auditoria_sistema_pkey;
ALTER TABLE IF EXISTS public.vehiculos ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.usuarios ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.transporte_asignaciones ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.transporte ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.tasa_cambio ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.proveedores_pagos ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.proveedores_entregas ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.proveedores ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.productos ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.productores_precios_historial ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.productores_entregas ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.productores_descuentos ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.productores_abonos ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.productores ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.movimiento_caja ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.lotes ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.facturas ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.empleados ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.detalle_factura ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.compras ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.bitacora_sesiones ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.bancos ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.auditoria_sistema ALTER COLUMN id DROP DEFAULT;
DROP SEQUENCE IF EXISTS public.vehiculos_id_seq;
DROP TABLE IF EXISTS public.vehiculos;
DROP SEQUENCE IF EXISTS public.usuarios_id_seq;
DROP TABLE IF EXISTS public.usuarios;
DROP SEQUENCE IF EXISTS public.transporte_id_seq;
DROP SEQUENCE IF EXISTS public.transporte_asignaciones_id_seq;
DROP TABLE IF EXISTS public.transporte_asignaciones;
DROP TABLE IF EXISTS public.transporte;
DROP SEQUENCE IF EXISTS public.tasa_cambio_id_seq;
DROP TABLE IF EXISTS public.tasa_cambio;
DROP SEQUENCE IF EXISTS public.proveedores_pagos_id_seq;
DROP TABLE IF EXISTS public.proveedores_pagos;
DROP SEQUENCE IF EXISTS public.proveedores_id_seq;
DROP SEQUENCE IF EXISTS public.proveedores_entregas_id_seq;
DROP TABLE IF EXISTS public.proveedores_entregas;
DROP TABLE IF EXISTS public.proveedores;
DROP SEQUENCE IF EXISTS public.productos_id_seq;
DROP TABLE IF EXISTS public.productos;
DROP SEQUENCE IF EXISTS public.productores_precios_historial_id_seq;
DROP TABLE IF EXISTS public.productores_precios_historial;
DROP SEQUENCE IF EXISTS public.productores_id_seq;
DROP SEQUENCE IF EXISTS public.productores_entregas_id_seq;
DROP TABLE IF EXISTS public.productores_entregas;
DROP SEQUENCE IF EXISTS public.productores_descuentos_id_seq;
DROP TABLE IF EXISTS public.productores_descuentos;
DROP SEQUENCE IF EXISTS public.productores_abonos_id_seq;
DROP TABLE IF EXISTS public.productores_abonos;
DROP TABLE IF EXISTS public.productores;
DROP SEQUENCE IF EXISTS public.movimiento_caja_id_seq;
DROP TABLE IF EXISTS public.movimiento_caja;
DROP SEQUENCE IF EXISTS public.lotes_id_seq;
DROP TABLE IF EXISTS public.lotes;
DROP SEQUENCE IF EXISTS public.facturas_id_seq;
DROP TABLE IF EXISTS public.facturas;
DROP SEQUENCE IF EXISTS public.empleados_id_seq;
DROP TABLE IF EXISTS public.empleados;
DROP SEQUENCE IF EXISTS public.detalle_factura_id_seq;
DROP TABLE IF EXISTS public.detalle_factura;
DROP SEQUENCE IF EXISTS public.compras_id_seq;
DROP TABLE IF EXISTS public.compras;
DROP SEQUENCE IF EXISTS public.bitacora_sesiones_id_seq;
DROP TABLE IF EXISTS public.bitacora_sesiones;
DROP SEQUENCE IF EXISTS public.bancos_id_seq;
DROP TABLE IF EXISTS public.bancos;
DROP SEQUENCE IF EXISTS public.auditoria_sistema_id_seq;
DROP TABLE IF EXISTS public.auditoria_sistema;
SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: auditoria_sistema; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.auditoria_sistema (
    id integer NOT NULL,
    usuario_id integer,
    bitacora_sesion_id integer,
    modulo character varying(100) NOT NULL,
    accion character varying(150) NOT NULL,
    detalle_json text,
    metodo_http character varying(10),
    ruta character varying(255),
    ip character varying(45),
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.auditoria_sistema OWNER TO postgres;

--
-- Name: auditoria_sistema_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.auditoria_sistema_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.auditoria_sistema_id_seq OWNER TO postgres;

--
-- Name: auditoria_sistema_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.auditoria_sistema_id_seq OWNED BY public.auditoria_sistema.id;


--
-- Name: bancos; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.bancos (
    id integer NOT NULL,
    nombre character varying(150) NOT NULL,
    numero_cuenta character varying(50),
    saldo numeric(10,2) DEFAULT '0'::numeric,
    estado boolean DEFAULT true NOT NULL,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.bancos OWNER TO postgres;

--
-- Name: bancos_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.bancos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bancos_id_seq OWNER TO postgres;

--
-- Name: bancos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.bancos_id_seq OWNED BY public.bancos.id;


--
-- Name: bitacora_sesiones; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.bitacora_sesiones (
    id integer NOT NULL,
    usuario_id integer,
    nombre_usuario character varying(150),
    email character varying(150),
    rol character varying(50),
    session_token character varying(128) NOT NULL,
    ip character varying(45),
    user_agent text,
    inicio_sesion timestamp without time zone DEFAULT now() NOT NULL,
    fin_sesion timestamp without time zone,
    ultima_actividad timestamp without time zone DEFAULT now() NOT NULL,
    estado character varying(20) DEFAULT 'activa'::character varying NOT NULL
);


ALTER TABLE public.bitacora_sesiones OWNER TO postgres;

--
-- Name: bitacora_sesiones_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.bitacora_sesiones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bitacora_sesiones_id_seq OWNER TO postgres;

--
-- Name: bitacora_sesiones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.bitacora_sesiones_id_seq OWNED BY public.bitacora_sesiones.id;


--
-- Name: compras; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.compras (
    id integer NOT NULL,
    proveedor_id integer NOT NULL,
    monto numeric(10,2) NOT NULL,
    descripcion text,
    fecha timestamp without time zone DEFAULT now() NOT NULL,
    moneda character varying(10) DEFAULT 'USD'::character varying,
    estado boolean DEFAULT true NOT NULL
);


ALTER TABLE public.compras OWNER TO postgres;

--
-- Name: compras_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.compras_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.compras_id_seq OWNER TO postgres;

--
-- Name: compras_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.compras_id_seq OWNED BY public.compras.id;


--
-- Name: detalle_factura; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.detalle_factura (
    id integer NOT NULL,
    factura_id integer NOT NULL,
    producto_id integer NOT NULL,
    cantidad numeric(12,3) NOT NULL,
    precio numeric(12,2) NOT NULL
);


ALTER TABLE public.detalle_factura OWNER TO postgres;

--
-- Name: detalle_factura_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.detalle_factura_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.detalle_factura_id_seq OWNER TO postgres;

--
-- Name: detalle_factura_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.detalle_factura_id_seq OWNED BY public.detalle_factura.id;


--
-- Name: empleados; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.empleados (
    id integer NOT NULL,
    nombre character varying(150) NOT NULL,
    cargo character varying(100),
    telefono character varying(50),
    email character varying(100),
    fecha_ingreso date,
    estado boolean DEFAULT true NOT NULL,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.empleados OWNER TO postgres;

--
-- Name: empleados_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.empleados_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.empleados_id_seq OWNER TO postgres;

--
-- Name: empleados_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.empleados_id_seq OWNED BY public.empleados.id;


--
-- Name: facturas; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.facturas (
    id integer NOT NULL,
    cliente character varying(255) NOT NULL,
    total numeric(12,2) NOT NULL,
    fecha timestamp without time zone DEFAULT now() NOT NULL,
    moneda character varying(3) DEFAULT 'BS'::character varying NOT NULL,
    tasa_cambio numeric(12,2)
);


ALTER TABLE public.facturas OWNER TO postgres;

--
-- Name: facturas_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.facturas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.facturas_id_seq OWNER TO postgres;

--
-- Name: facturas_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.facturas_id_seq OWNED BY public.facturas.id;


--
-- Name: lotes; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.lotes (
    id integer NOT NULL,
    producto_id integer NOT NULL,
    cantidad numeric(12,3) NOT NULL,
    fecha_vencimiento date,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.lotes OWNER TO postgres;

--
-- Name: lotes_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.lotes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.lotes_id_seq OWNER TO postgres;

--
-- Name: lotes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.lotes_id_seq OWNED BY public.lotes.id;


--
-- Name: movimiento_caja; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.movimiento_caja (
    id integer NOT NULL,
    tipo character varying(20) NOT NULL,
    monto numeric(10,2) NOT NULL,
    descripcion text,
    fecha timestamp without time zone DEFAULT now() NOT NULL,
    moneda character varying(10) DEFAULT 'USD'::character varying,
    estado boolean DEFAULT true NOT NULL,
    origen_tipo character varying(20) DEFAULT 'manual'::character varying NOT NULL,
    factura_id integer,
    compra_id integer
);


ALTER TABLE public.movimiento_caja OWNER TO postgres;

--
-- Name: movimiento_caja_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.movimiento_caja_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.movimiento_caja_id_seq OWNER TO postgres;

--
-- Name: movimiento_caja_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.movimiento_caja_id_seq OWNED BY public.movimiento_caja.id;


--
-- Name: productores; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.productores (
    id integer NOT NULL,
    nombre character varying(160) NOT NULL,
    direccion character varying(255),
    documento character varying(40),
    telefono character varying(40),
    correo character varying(150),
    precio_litro_usd numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    credito_dias integer DEFAULT 7 NOT NULL,
    estado boolean DEFAULT true NOT NULL,
    creado_en timestamp without time zone DEFAULT now() NOT NULL,
    actualizado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.productores OWNER TO postgres;

--
-- Name: productores_abonos; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.productores_abonos (
    id integer NOT NULL,
    productor_id integer NOT NULL,
    fecha date NOT NULL,
    tipo character varying(60) DEFAULT 'efectivo'::character varying NOT NULL,
    monto_usd numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    descripcion character varying(255),
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.productores_abonos OWNER TO postgres;

--
-- Name: productores_abonos_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.productores_abonos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.productores_abonos_id_seq OWNER TO postgres;

--
-- Name: productores_abonos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.productores_abonos_id_seq OWNED BY public.productores_abonos.id;


--
-- Name: productores_descuentos; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.productores_descuentos (
    id integer NOT NULL,
    productor_id integer NOT NULL,
    fecha date NOT NULL,
    tipo character varying(60) DEFAULT 'mercancia'::character varying NOT NULL,
    descripcion character varying(255),
    monto_usd numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.productores_descuentos OWNER TO postgres;

--
-- Name: productores_descuentos_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.productores_descuentos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.productores_descuentos_id_seq OWNER TO postgres;

--
-- Name: productores_descuentos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.productores_descuentos_id_seq OWNED BY public.productores_descuentos.id;


--
-- Name: productores_entregas; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.productores_entregas (
    id integer NOT NULL,
    productor_id integer NOT NULL,
    fecha date NOT NULL,
    litros numeric(12,3) DEFAULT '0'::numeric NOT NULL,
    precio_litro_usd numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    subtotal_usd numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    gasto_traslado_usd numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    cuajada_kg numeric(12,3) DEFAULT '0'::numeric NOT NULL,
    observaciones text,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.productores_entregas OWNER TO postgres;

--
-- Name: productores_entregas_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.productores_entregas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.productores_entregas_id_seq OWNER TO postgres;

--
-- Name: productores_entregas_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.productores_entregas_id_seq OWNED BY public.productores_entregas.id;


--
-- Name: productores_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.productores_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.productores_id_seq OWNER TO postgres;

--
-- Name: productores_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.productores_id_seq OWNED BY public.productores.id;


--
-- Name: productores_precios_historial; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.productores_precios_historial (
    id integer NOT NULL,
    productor_id integer NOT NULL,
    precio_anterior_usd numeric(12,4),
    precio_nuevo_usd numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    origen character varying(80) DEFAULT 'actualizacion'::character varying NOT NULL,
    observacion character varying(255),
    usuario_id integer,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.productores_precios_historial OWNER TO postgres;

--
-- Name: productores_precios_historial_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.productores_precios_historial_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.productores_precios_historial_id_seq OWNER TO postgres;

--
-- Name: productores_precios_historial_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.productores_precios_historial_id_seq OWNED BY public.productores_precios_historial.id;


--
-- Name: productos; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.productos (
    id integer NOT NULL,
    codigo character varying(80),
    nombre character varying(150) NOT NULL,
    descripcion text,
    precio numeric(10,2) NOT NULL,
    stock numeric(12,3) DEFAULT '0'::numeric NOT NULL,
    umbral_alerta numeric(12,3) DEFAULT '5'::numeric NOT NULL,
    moneda character varying(10) DEFAULT 'USD'::character varying,
    unidad_medida character varying(50) DEFAULT 'unidad'::character varying NOT NULL,
    presentacion character varying(120),
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.productos OWNER TO postgres;

--
-- Name: productos_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.productos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.productos_id_seq OWNER TO postgres;

--
-- Name: productos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.productos_id_seq OWNED BY public.productos.id;


--
-- Name: proveedores; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.proveedores (
    id integer NOT NULL,
    nombre character varying(150) NOT NULL,
    contacto character varying(100),
    telefono character varying(50),
    email character varying(100),
    direccion text,
    creado_en timestamp without time zone DEFAULT now() NOT NULL,
    precio_litro_usd numeric(12,4) DEFAULT 0 NOT NULL
);


ALTER TABLE public.proveedores OWNER TO postgres;

--
-- Name: proveedores_entregas; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.proveedores_entregas (
    id integer NOT NULL,
    proveedor_id integer NOT NULL,
    fecha date NOT NULL,
    descripcion character varying(200),
    cantidad numeric(12,3) DEFAULT 0 NOT NULL,
    precio_usd numeric(12,4) DEFAULT 0 NOT NULL,
    subtotal_usd numeric(12,4) DEFAULT 0 NOT NULL,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.proveedores_entregas OWNER TO postgres;

--
-- Name: proveedores_entregas_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.proveedores_entregas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.proveedores_entregas_id_seq OWNER TO postgres;

--
-- Name: proveedores_entregas_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.proveedores_entregas_id_seq OWNED BY public.proveedores_entregas.id;


--
-- Name: proveedores_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.proveedores_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.proveedores_id_seq OWNER TO postgres;

--
-- Name: proveedores_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.proveedores_id_seq OWNED BY public.proveedores.id;


--
-- Name: proveedores_pagos; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.proveedores_pagos (
    id integer NOT NULL,
    proveedor_id integer NOT NULL,
    fecha date NOT NULL,
    tipo character varying(60) DEFAULT 'efectivo'::character varying NOT NULL,
    monto_usd numeric(12,4) DEFAULT 0 NOT NULL,
    descripcion character varying(255),
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.proveedores_pagos OWNER TO postgres;

--
-- Name: proveedores_pagos_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.proveedores_pagos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.proveedores_pagos_id_seq OWNER TO postgres;

--
-- Name: proveedores_pagos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.proveedores_pagos_id_seq OWNED BY public.proveedores_pagos.id;


--
-- Name: tasa_cambio; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.tasa_cambio (
    id integer NOT NULL,
    tasa numeric(10,2),
    fecha timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.tasa_cambio OWNER TO postgres;

--
-- Name: tasa_cambio_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.tasa_cambio_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.tasa_cambio_id_seq OWNER TO postgres;

--
-- Name: tasa_cambio_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.tasa_cambio_id_seq OWNED BY public.tasa_cambio.id;


--
-- Name: transporte; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.transporte (
    id integer NOT NULL,
    nombre_vehiculo character varying(100),
    placa character varying(50),
    capacidad integer,
    estado boolean DEFAULT true NOT NULL,
    conductor_actual_id integer,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.transporte OWNER TO postgres;

--
-- Name: transporte_asignaciones; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.transporte_asignaciones (
    id integer NOT NULL,
    transporte_id integer NOT NULL,
    empleado_id integer NOT NULL,
    fecha_asignacion timestamp without time zone DEFAULT now() NOT NULL,
    fecha_desasignacion timestamp without time zone,
    observaciones character varying(255),
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.transporte_asignaciones OWNER TO postgres;

--
-- Name: transporte_asignaciones_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.transporte_asignaciones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.transporte_asignaciones_id_seq OWNER TO postgres;

--
-- Name: transporte_asignaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.transporte_asignaciones_id_seq OWNED BY public.transporte_asignaciones.id;


--
-- Name: transporte_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.transporte_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.transporte_id_seq OWNER TO postgres;

--
-- Name: transporte_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.transporte_id_seq OWNED BY public.transporte.id;


--
-- Name: usuarios; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.usuarios (
    id integer NOT NULL,
    empleado_id integer,
    nombre character varying(100) NOT NULL,
    email character varying(100) NOT NULL,
    password character varying(255) NOT NULL,
    rol character varying(50) DEFAULT 'empleado'::character varying NOT NULL,
    permisos_json text,
    estado boolean DEFAULT true NOT NULL,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.usuarios OWNER TO postgres;

--
-- Name: usuarios_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.usuarios_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.usuarios_id_seq OWNER TO postgres;

--
-- Name: usuarios_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.usuarios_id_seq OWNED BY public.usuarios.id;


--
-- Name: vehiculos; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vehiculos (
    id integer NOT NULL,
    nombre_vehiculo character varying(120) NOT NULL,
    placa character varying(30),
    capacidad numeric(10,2) DEFAULT 0,
    estado character varying(30) DEFAULT 'disponible'::character varying NOT NULL,
    conductor_actual_id integer,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.vehiculos OWNER TO postgres;

--
-- Name: vehiculos_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vehiculos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehiculos_id_seq OWNER TO postgres;

--
-- Name: vehiculos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vehiculos_id_seq OWNED BY public.vehiculos.id;


--
-- Name: auditoria_sistema id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.auditoria_sistema ALTER COLUMN id SET DEFAULT nextval('public.auditoria_sistema_id_seq'::regclass);


--
-- Name: bancos id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bancos ALTER COLUMN id SET DEFAULT nextval('public.bancos_id_seq'::regclass);


--
-- Name: bitacora_sesiones id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bitacora_sesiones ALTER COLUMN id SET DEFAULT nextval('public.bitacora_sesiones_id_seq'::regclass);


--
-- Name: compras id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compras ALTER COLUMN id SET DEFAULT nextval('public.compras_id_seq'::regclass);


--
-- Name: detalle_factura id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.detalle_factura ALTER COLUMN id SET DEFAULT nextval('public.detalle_factura_id_seq'::regclass);


--
-- Name: empleados id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.empleados ALTER COLUMN id SET DEFAULT nextval('public.empleados_id_seq'::regclass);


--
-- Name: facturas id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.facturas ALTER COLUMN id SET DEFAULT nextval('public.facturas_id_seq'::regclass);


--
-- Name: lotes id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.lotes ALTER COLUMN id SET DEFAULT nextval('public.lotes_id_seq'::regclass);


--
-- Name: movimiento_caja id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.movimiento_caja ALTER COLUMN id SET DEFAULT nextval('public.movimiento_caja_id_seq'::regclass);


--
-- Name: productores id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores ALTER COLUMN id SET DEFAULT nextval('public.productores_id_seq'::regclass);


--
-- Name: productores_abonos id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_abonos ALTER COLUMN id SET DEFAULT nextval('public.productores_abonos_id_seq'::regclass);


--
-- Name: productores_descuentos id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_descuentos ALTER COLUMN id SET DEFAULT nextval('public.productores_descuentos_id_seq'::regclass);


--
-- Name: productores_entregas id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_entregas ALTER COLUMN id SET DEFAULT nextval('public.productores_entregas_id_seq'::regclass);


--
-- Name: productores_precios_historial id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_precios_historial ALTER COLUMN id SET DEFAULT nextval('public.productores_precios_historial_id_seq'::regclass);


--
-- Name: productos id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productos ALTER COLUMN id SET DEFAULT nextval('public.productos_id_seq'::regclass);


--
-- Name: proveedores id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.proveedores ALTER COLUMN id SET DEFAULT nextval('public.proveedores_id_seq'::regclass);


--
-- Name: proveedores_entregas id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.proveedores_entregas ALTER COLUMN id SET DEFAULT nextval('public.proveedores_entregas_id_seq'::regclass);


--
-- Name: proveedores_pagos id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.proveedores_pagos ALTER COLUMN id SET DEFAULT nextval('public.proveedores_pagos_id_seq'::regclass);


--
-- Name: tasa_cambio id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.tasa_cambio ALTER COLUMN id SET DEFAULT nextval('public.tasa_cambio_id_seq'::regclass);


--
-- Name: transporte id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transporte ALTER COLUMN id SET DEFAULT nextval('public.transporte_id_seq'::regclass);


--
-- Name: transporte_asignaciones id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transporte_asignaciones ALTER COLUMN id SET DEFAULT nextval('public.transporte_asignaciones_id_seq'::regclass);


--
-- Name: usuarios id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.usuarios ALTER COLUMN id SET DEFAULT nextval('public.usuarios_id_seq'::regclass);


--
-- Name: vehiculos id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehiculos ALTER COLUMN id SET DEFAULT nextval('public.vehiculos_id_seq'::regclass);


--
-- Data for Name: auditoria_sistema; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.auditoria_sistema (id, usuario_id, bitacora_sesion_id, modulo, accion, detalle_json, metodo_http, ruta, ip, creado_en) FROM stdin;
95	1	49	auth	login_exitoso	{"usuario_id":1,"rol":"admin"}	POST	login	::1	2026-05-04 01:29:52.104874
\.


--
-- Data for Name: bancos; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.bancos (id, nombre, numero_cuenta, saldo, estado, creado_en) FROM stdin;
1	Banco de Venezuela	0102-0000-00-0000000001	15000.00	t	2026-05-03 16:37:31.90634
2	Banesco	0134-0000-00-0000000002	8500.00	t	2026-05-03 16:37:31.949062
3	Provincial BBVA	0108-0000-00-0000000003	3200.00	t	2026-05-03 16:37:31.992762
\.


--
-- Data for Name: bitacora_sesiones; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.bitacora_sesiones (id, usuario_id, nombre_usuario, email, rol, session_token, ip, user_agent, inicio_sesion, fin_sesion, ultima_actividad, estado) FROM stdin;
1	1	Administrador	admin@aserp.com	admin	ced509ec09ad9f0f61e83337e6eef4db	::ffff:127.0.0.1	curl/8.14.1	2026-05-03 16:48:43.36503	\N	2026-05-03 16:48:43.36503	activa
2	1	Administrador	admin@aserp.com	admin	37ba98257c39a2b9375a4cdc7cabc3bd	::ffff:127.0.0.1	curl/8.14.1	2026-05-03 16:48:48.772479	\N	2026-05-03 16:48:48.772479	activa
3	1	Administrador	admin@aserp.com	admin	78b3d2cb061aec6f37639884c0ef55ce	::ffff:127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	2026-05-03 16:49:39.755395	2026-05-03 16:50:16.002	2026-05-03 16:49:39.755395	cerrada
4	1	Administrador	admin@aserp.com	admin	a25f7e129a7f32828fd670d9ae0e8693	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0	2026-05-03 17:09:52.353691	2026-05-03 17:12:43.448	2026-05-03 17:09:52.353691	cerrada
5	1	Administrador	admin@aserp.com	admin	1dd45bbbfdf42e9b2d975b908886a4d3	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0	2026-05-03 17:12:51.570646	2026-05-03 17:36:09.895	2026-05-03 17:12:51.570646	cerrada
7	1	Administrador	admin@aserp.com	admin	22c68eb50cf453e93b5e1c16217124e4	::ffff:127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	2026-05-03 17:44:57.968461	2026-05-03 17:46:29.085	2026-05-03 17:44:57.968461	cerrada
6	1	Administrador	admin@aserp.com	admin	69853f7e21d69754d7a2a7ed26656b44	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0	2026-05-03 17:36:13.345657	2026-05-03 17:58:00.885	2026-05-03 17:36:13.345657	cerrada
8	1	Administrador	admin@aserp.com	admin	e8294b89c1fc70948f1d00f58e8370b2	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0	2026-05-03 17:58:03.473143	2026-05-03 18:24:34.834	2026-05-03 17:58:03.473143	cerrada
9	1	Administrador	admin@aserp.com	admin	0060d78c7d75551d2283bb14991e2abd	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0	2026-05-03 18:24:44.023645	\N	2026-05-03 18:24:44.023645	activa
10	1	Administrador	admin@aserp.com	admin	3d1378ae234eda5b446088b1cdfeca98	::ffff:127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	2026-05-03 18:31:03.610912	\N	2026-05-03 18:31:03.610912	activa
13	1	Administrador	admin@aserp.com	admin	43ed6225c9165ea410ea94d7fc4342d3	::1	curl/8.14.1	2026-05-03 18:53:40.791777	\N	2026-05-03 18:53:40.791777	activa
14	1	Administrador	admin@aserp.com	admin	93ef63a4e7f39e06d2c2e2b681b99876	::1	\N	2026-05-03 18:54:00.763449	\N	2026-05-03 18:54:00.763449	activa
12	3	Yexon Ovalles	oyexon870@gmail.com	admin	77e134ae1126f062e0b188c4245e3c86	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36	2026-05-03 18:39:47.232878	2026-05-03 18:54:22.641	2026-05-03 18:39:47.232878	cerrada
16	3	Yexon Ovalles	oyexon870@gmail.com	admin	aa1a4bfa3c2add807f3aeabf958a36f9	::ffff:127.0.0.1	Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36	2026-05-03 19:02:40.755154	\N	2026-05-03 19:02:40.755154	activa
17	1	Administrador	admin@aserp.com	admin	36dd67ad02171eea7cad7046a7e65e69	::1	\N	2026-05-03 19:02:45.401212	\N	2026-05-03 19:02:45.401212	activa
18	1	Administrador	admin@aserp.com	admin	a426818c1bb78840850970905c5b5095	::1	\N	2026-05-03 19:03:11.285534	\N	2026-05-03 19:03:11.285534	activa
19	1	Administrador	admin@aserp.com	admin	110cea44dbf6f2bd24f681f7ee6dd60d	::1	\N	2026-05-03 19:03:25.216835	\N	2026-05-03 19:03:25.216835	activa
20	1	Administrador	admin@aserp.com	admin	42669f5613c2e3ab53534272f305318a	::1	\N	2026-05-03 19:03:52.726791	\N	2026-05-03 19:03:52.726791	activa
21	1	Administrador	admin@aserp.com	admin	1c0a285971a61de6694fe464c3cf660d	::1	\N	2026-05-03 19:04:22.418317	\N	2026-05-03 19:04:22.418317	activa
22	1	Administrador	admin@aserp.com	admin	f85d4673a64223094edb8469f8acf68b	::1	\N	2026-05-03 19:08:05.445087	\N	2026-05-03 19:08:05.445087	activa
23	1	Administrador	admin@aserp.com	admin	66db66e193c80214f63d9fa6d3fc7080	::1	\N	2026-05-03 19:08:51.336136	\N	2026-05-03 19:08:51.336136	activa
24	1	Administrador	admin@aserp.com	admin	9e875875fc7ca6c00d2931b917c2286c	::1	\N	2026-05-03 19:09:32.350283	\N	2026-05-03 19:09:32.350283	activa
25	1	Administrador	admin@aserp.com	admin	1ffce109e776ca99d054c43ba2e7e668	::1	\N	2026-05-03 19:09:35.247197	\N	2026-05-03 19:09:35.247197	activa
15	4	gggg	oyexon@gmail.com	empleado	b5dac0610515bfe1be2e0d22e7114631	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36	2026-05-03 18:55:05.974861	2026-05-03 19:09:51.586	2026-05-03 18:55:05.974861	cerrada
26	4	gggg	oyexon@gmail.com	empleado	a6ab9f3db851d232528df4c9cb5a3604	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36	2026-05-03 19:10:18.392145	2026-05-03 19:15:47.667	2026-05-03 19:10:18.392145	cerrada
27	1	Administrador	admin@aserp.com	admin	b033dd6508169f34021fee95db4b741f	::1	\N	2026-05-03 19:18:05.011448	\N	2026-05-03 19:18:05.011448	activa
28	4	gggg	oyexon@gmail.com	empleado	ede1bdbcea2fc9a3fa407a3c479f8d7e	::1	\N	2026-05-03 19:18:10.142043	\N	2026-05-03 19:18:10.142043	activa
30	1	Administrador	admin@aserp.com	admin	99616ba2ca360ef6b96bd6b36cae6923	::1	\N	2026-05-03 19:27:21.157171	\N	2026-05-03 19:27:21.157171	activa
31	1	Administrador	admin@aserp.com	admin	b098bfc6c9401db82819feb60d8a6011	::1	\N	2026-05-03 19:27:30.86678	\N	2026-05-03 19:27:30.86678	activa
32	2	Empleado Demo	empleado@aserp.com	empleado	e18a265ebf07e4bba81e3a2dbe0fe33f	::1	\N	2026-05-03 19:27:33.267157	\N	2026-05-03 19:27:33.267157	activa
33	4	gggg	oyexon@gmail.com	empleado	17b244f59851e5c69d609441447fd969	::1	\N	2026-05-03 19:29:48.114313	\N	2026-05-03 19:29:48.114313	activa
34	4	gggg	oyexon@gmail.com	empleado	0a0fe4d602a3df24bc67ac7f5face240	::1	curl/8.14.1	2026-05-03 19:29:49.982906	\N	2026-05-03 19:29:49.982906	activa
35	4	gggg	oyexon@gmail.com	empleado	b2f735b3bffa677da6c70858488fccff	::1	\N	2026-05-03 19:30:39.729859	\N	2026-05-03 19:30:39.729859	activa
29	4	gggg	oyexon@gmail.com	empleado	023a08e6ccb910ade9994403527a182e	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36	2026-05-03 19:19:44.339965	2026-05-03 19:32:49.778	2026-05-03 19:19:44.339965	cerrada
37	4	gggg	oyexon@gmail.com	empleado	53c71eb74d2e8fb6d928651cf188bc5b	::1	\N	2026-05-03 19:37:03.589768	\N	2026-05-03 19:37:03.589768	activa
38	1	Administrador	admin@aserp.com	admin	50beb49ba340039d00b2779a01a40f43	::1	\N	2026-05-03 19:37:05.34065	\N	2026-05-03 19:37:05.34065	activa
39	1	Administrador	admin@aserp.com	admin	96ec04ccf7f3bca9fdc2edc767f49d2f	::1	\N	2026-05-03 19:44:01.097758	\N	2026-05-03 19:44:01.097758	activa
40	4	gggg	oyexon@gmail.com	empleado	f297d1bc717cc3df5e54287633e4e527	::1	\N	2026-05-03 19:44:03.929487	\N	2026-05-03 19:44:03.929487	activa
41	1	Administrador	admin@aserp.com	admin	d028e15ae1e3e6ad4b41a85da1e50962	::1	\N	2026-05-03 19:50:04.021491	\N	2026-05-03 19:50:04.021491	activa
36	4	gggg	oyexon@gmail.com	empleado	6377142e194f9cc194e2276f441ba0ef	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36	2026-05-03 19:33:17.285455	2026-05-03 19:53:12.977	2026-05-03 19:33:17.285455	cerrada
11	1	Administrador	admin@aserp.com	admin	77b6c52fdfacf02023da0e1d2df0cca7	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0	2026-05-03 18:37:40.391649	2026-05-03 19:50:41.443	2026-05-03 18:37:40.391649	cerrada
42	1	Administrador	admin@aserp.com	admin	1066d335f7ff4bafba6d096afce0b088	::1	\N	2026-05-03 19:50:59.79345	\N	2026-05-03 19:50:59.79345	activa
43	1	Administrador	admin@aserp.com	admin	9d83652d189bfef98a9e5f87f580800d	::ffff:127.0.0.1	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0	2026-05-03 19:55:13.441449	\N	2026-05-03 19:55:13.441449	activa
44	3	Yexon Ovalles	oyexon870@gmail.com	empleado	daf368e5b5669499078353887dd747ac	::1	\N	2026-05-03 20:03:06.270599	\N	2026-05-03 20:03:06.270599	activa
45	5	yexon	oyexon870@gmail.com	empleado	d2b190b1b68cf95b885319a9f241d495	::1	\N	2026-05-03 20:09:03.199261	\N	2026-05-03 20:09:03.199261	activa
46	1	Administrador	admin@aserp.com	admin	4f3a9a4aec2a8addc9f792f93ecf6a2e	::1	\N	2026-05-03 20:11:15.848316	\N	2026-05-03 20:11:15.848316	activa
47	1	Administrador	admin@aserp.com	admin	15091dee53b9bca962f3ed3e055c8e2b	::1	\N	2026-05-03 20:12:58.795283	\N	2026-05-03 20:12:58.795283	activa
48	6	Carlos Martines	Carlosmanel@gmail.com	admin	2ccc1584ece0c50585d9eb70ccadb1f2	::1	\N	2026-05-03 20:21:32.330648	\N	2026-05-03 20:21:32.330648	activa
49	1	Administrador	admin@aserp.com	admin	69003c3a2e5dbad487d118b6983d385d	::1	\N	2026-05-04 01:29:51.759512	\N	2026-05-04 01:29:51.759512	activa
\.


--
-- Data for Name: compras; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.compras (id, proveedor_id, monto, descripcion, fecha, moneda, estado) FROM stdin;
\.


--
-- Data for Name: detalle_factura; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.detalle_factura (id, factura_id, producto_id, cantidad, precio) FROM stdin;
1	1	1	10.000	8.50
2	1	2	5.000	5.00
3	2	3	8.000	3.50
4	2	4	4.000	6.00
5	3	1	20.000	8.50
6	3	5	10.000	4.00
7	4	2	1.000	5.00
8	5	6	5.000	2.50
9	5	2	1.000	5.00
10	6	6	1.000	2.50
11	6	4	1.000	6.00
12	6	4	1.000	6.00
13	6	1	1.000	8.50
14	6	4	1.000	6.00
15	8	2	13.000	5.00
16	8	6	3.000	2.50
17	8	1	7.000	8.50
18	9	4	10.000	6.00
19	10	4	1.000	6.00
20	10	1	50.000	8.50
\.


--
-- Data for Name: empleados; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.empleados (id, nombre, cargo, telefono, email, fecha_ingreso, estado, creado_en) FROM stdin;
3	Pedro Rojas	Repartidor	0412-9876543	\N	2022-05-01	t	2026-05-03 16:37:31.488868
4	Ana Pérez	Producción	0424-1122334	\N	2021-07-20	t	2026-05-03 16:37:31.532529
1	Carlos Mora	Administrador	0414-1234567	admin@castilloqueso.com	2020-01-15	t	2026-05-03 16:37:31.394661
2	Maria González	Vendedora	0416-7654321	mgonzalez@castilloqueso.com	2021-03-10	t	2026-05-03 16:37:31.442666
\.


--
-- Data for Name: facturas; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.facturas (id, cliente, total, fecha, moneda, tasa_cambio) FROM stdin;
1	Supermercado El Éxito	110.00	2026-05-03 16:37:32.531	USD	42.85
2	Restaurante La Montaña	52.00	2026-05-03 16:37:32.588	USD	42.85
3	Distribuidora Andina	210.00	2026-05-03 16:37:32.642	USD	42.85
4	yexon	5.00	2026-05-03 17:14:24.831	USD	42.85
5	yexon	17.50	2026-05-03 17:55:26.107	USD	500.00
6	kjjj	29.00	2026-05-03 19:09:02.437	BS	500.00
7	carrlos	100.00	2026-05-04 00:00:00	VES	500.00
8	Yexon	132.00	2026-05-04 00:00:00	VES	500.00
9	Yexon	60.00	2026-05-04 00:00:00	USD	500.00
10	yexon	431.00	2026-05-04 00:00:00	USD	520.00
\.


--
-- Data for Name: lotes; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.lotes (id, producto_id, cantidad, fecha_vencimiento, creado_en) FROM stdin;
1	5	150.000	2026-05-30	2026-05-04 15:11:26.719275
\.


--
-- Data for Name: movimiento_caja; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.movimiento_caja (id, tipo, monto, descripcion, fecha, moneda, estado, origen_tipo, factura_id, compra_id) FROM stdin;
1	ingreso	450.00	Ventas semana 1 - quesos varios	2026-05-03 16:37:32.351903	USD	t	manual	\N	\N
2	egreso	120.00	Compra insumos empaque	2026-05-03 16:37:32.394477	USD	t	manual	\N	\N
3	ingreso	18500.00	Ventas en bolívares semana 1	2026-05-03 16:37:32.440679	BS	t	manual	\N	\N
4	egreso	5000.00	Pago salarios semana 1	2026-05-03 16:37:32.491321	BS	t	manual	\N	\N
5	ingreso	110.00	Ingreso automatico por venta factura #1 - Cliente: Supermercado El Éxito	2026-05-03 16:37:32.548605	USD	t	venta	1	\N
6	ingreso	52.00	Ingreso automatico por venta factura #2 - Cliente: Restaurante La Montaña	2026-05-03 16:37:32.602122	USD	t	venta	2	\N
7	ingreso	210.00	Ingreso automatico por venta factura #3 - Cliente: Distribuidora Andina	2026-05-03 16:37:32.658365	USD	t	venta	3	\N
8	ingreso	5.00	Ingreso automatico por venta factura #4 - Cliente: yexon	2026-05-03 17:14:24.870818	USD	t	venta	4	\N
9	ingreso	17.50	Ingreso automatico por venta factura #5 - Cliente: yexon	2026-05-03 17:55:26.167662	USD	t	venta	5	\N
10	ingreso	29.00	Ingreso automatico por venta factura #6 - Cliente: kjjj	2026-05-03 19:09:02.471008	BS	t	venta	6	\N
11	ingreso	10000.00		2026-05-04 00:00:00	VES	t	venta	\N	\N
\.


--
-- Data for Name: productores; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.productores (id, nombre, direccion, documento, telefono, correo, precio_litro_usd, credito_dias, estado, creado_en, actualizado_en) FROM stdin;
6	yexon ovalles 1		30703203	04243814698	oyexon870@gmail.com	0.7500	0	t	2026-05-04 15:01:14.023483	2026-05-04 15:01:14.023483
\.


--
-- Data for Name: productores_abonos; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.productores_abonos (id, productor_id, fecha, tipo, monto_usd, descripcion, creado_en) FROM stdin;
\.


--
-- Data for Name: productores_descuentos; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.productores_descuentos (id, productor_id, fecha, tipo, descripcion, monto_usd, creado_en) FROM stdin;
\.


--
-- Data for Name: productores_entregas; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.productores_entregas (id, productor_id, fecha, litros, precio_litro_usd, subtotal_usd, gasto_traslado_usd, cuajada_kg, observaciones, creado_en) FROM stdin;
1	6	2026-05-04	120.200	0.7500	90.1500	0.0000	0.000	\N	2026-05-04 15:05:09.917447
\.


--
-- Data for Name: productores_precios_historial; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.productores_precios_historial (id, productor_id, precio_anterior_usd, precio_nuevo_usd, origen, observacion, usuario_id, creado_en) FROM stdin;
\.


--
-- Data for Name: productos; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.productos (id, codigo, nombre, descripcion, precio, stock, umbral_alerta, moneda, unidad_medida, presentacion, creado_en) FROM stdin;
3	QT-001	Queso Telita 250g	\N	3.50	52.000	10.000	USD	unidad	Bolsa 250 g	2026-05-03 16:37:31.105653
5	MT-001	Mantequilla 250g	\N	4.00	25.000	8.000	USD	unidad	Tarro 250 g	2026-05-03 16:37:31.201163
2	QP-001	Queso Paisa 500g	\N	5.00	60.000	15.000	USD	kg	Pieza 500 g	2026-05-03 16:37:31.05727
6	SL-001	Suero de Leche 1L	\N	2.50	101.000	20.000	USD	litro	Botella 1 L	2026-05-03 16:37:31.246585
4	CF-001	Cuajada Fresca 1kg	\N	6.00	27.000	10.000	USD	kg	Bolsa 1 kg	2026-05-03 16:37:31.150935
1	QBD-001	Queso Blanco Duro 1kg	\N	8.50	62.000	20.000	USD	kg	Rueda de 1 kg	2026-05-03 16:37:31.001931
\.


--
-- Data for Name: proveedores; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.proveedores (id, nombre, contacto, telefono, email, direccion, creado_en, precio_litro_usd) FROM stdin;
6	Yexon Ovalles		04243814698	bitcambio870@gmail.com		2026-05-04 13:41:46.867889	0.7500
\.


--
-- Data for Name: proveedores_entregas; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.proveedores_entregas (id, proveedor_id, fecha, descripcion, cantidad, precio_usd, subtotal_usd, creado_en) FROM stdin;
3	6	2026-05-04	Entrega semanal	120.150	0.7500	90.1125	2026-05-04 13:42:19.976347
5	6	2026-05-04	Entrega semanal	120.120	0.7500	90.0900	2026-05-04 14:49:41.308937
\.


--
-- Data for Name: proveedores_pagos; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.proveedores_pagos (id, proveedor_id, fecha, tipo, monto_usd, descripcion, creado_en) FROM stdin;
\.


--
-- Data for Name: tasa_cambio; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.tasa_cambio (id, tasa, fecha) FROM stdin;
1	42.85	2026-05-03 16:37:32.03528
2	500.00	2026-05-03 17:17:29.223622
\.


--
-- Data for Name: transporte; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.transporte (id, nombre_vehiculo, placa, capacidad, estado, conductor_actual_id, creado_en) FROM stdin;
1	Camioneta Toyota Hiace	ABC-123	500	t	\N	2026-05-03 16:37:32.076236
2	Furgoneta Ford Transit	XYZ-789	800	t	\N	2026-05-03 16:37:32.123547
3	Moto Honda CB150	M-456	50	t	\N	2026-05-03 16:37:32.164931
\.


--
-- Data for Name: transporte_asignaciones; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.transporte_asignaciones (id, transporte_id, empleado_id, fecha_asignacion, fecha_desasignacion, observaciones, creado_en) FROM stdin;
\.


--
-- Data for Name: usuarios; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.usuarios (id, empleado_id, nombre, email, password, rol, permisos_json, estado, creado_en) FROM stdin;
7	\N	Yexon Ovalles	oyexon870@gmail.com	$2y$10$WWP5XahgqkTE5GoTgCp9mOW7GBsq81VMO4W.yp/hnuGATm67WAC1a	admin	{"dashboard":true,"inventario":true,"lotes":true,"ventas":true,"empleados":true,"usuarios":true,"transporte":true,"proveedores":true,"productores":true,"finanzas":true,"bancos":true,"auditoria":true}	t	2026-05-04 12:52:13.575725
6	\N	Carlos Martines	Carlosmanel@gmail.com	$2y$10$pD.9lh4qN2uVuPg8E540UeHwCMl0Wa0UXjfQg6uS1ridRnWwz8FaW	admin	\N	t	2026-05-03 20:20:05.852537
1	\N	Administrador	admin@aserp.com	$2y$10$zR1N4QFViJPPM89ZuugQZ.MLViynRrouYgMgIHcCzMLkQnOt0xrNC	admin	\N	t	2026-05-03 16:37:31.630905
\.


--
-- Data for Name: vehiculos; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.vehiculos (id, nombre_vehiculo, placa, capacidad, estado, conductor_actual_id, creado_en) FROM stdin;
1	moto	a55d5d5e	150.00	en_ruta	3	2026-05-04 15:09:32.545135
\.


--
-- Name: auditoria_sistema_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.auditoria_sistema_id_seq', 95, true);


--
-- Name: bancos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.bancos_id_seq', 3, true);


--
-- Name: bitacora_sesiones_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.bitacora_sesiones_id_seq', 49, true);


--
-- Name: compras_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.compras_id_seq', 1, false);


--
-- Name: detalle_factura_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.detalle_factura_id_seq', 20, true);


--
-- Name: empleados_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.empleados_id_seq', 4, true);


--
-- Name: facturas_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.facturas_id_seq', 10, true);


--
-- Name: lotes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.lotes_id_seq', 1, true);


--
-- Name: movimiento_caja_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.movimiento_caja_id_seq', 11, true);


--
-- Name: productores_abonos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.productores_abonos_id_seq', 1, false);


--
-- Name: productores_descuentos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.productores_descuentos_id_seq', 1, false);


--
-- Name: productores_entregas_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.productores_entregas_id_seq', 1, true);


--
-- Name: productores_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.productores_id_seq', 6, true);


--
-- Name: productores_precios_historial_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.productores_precios_historial_id_seq', 3, true);


--
-- Name: productos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.productos_id_seq', 8, true);


--
-- Name: proveedores_entregas_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.proveedores_entregas_id_seq', 5, true);


--
-- Name: proveedores_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.proveedores_id_seq', 6, true);


--
-- Name: proveedores_pagos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.proveedores_pagos_id_seq', 1, true);


--
-- Name: tasa_cambio_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.tasa_cambio_id_seq', 2, true);


--
-- Name: transporte_asignaciones_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.transporte_asignaciones_id_seq', 1, false);


--
-- Name: transporte_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.transporte_id_seq', 3, true);


--
-- Name: usuarios_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.usuarios_id_seq', 7, true);


--
-- Name: vehiculos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.vehiculos_id_seq', 1, true);


--
-- Name: auditoria_sistema auditoria_sistema_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.auditoria_sistema
    ADD CONSTRAINT auditoria_sistema_pkey PRIMARY KEY (id);


--
-- Name: bancos bancos_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bancos
    ADD CONSTRAINT bancos_pkey PRIMARY KEY (id);


--
-- Name: bitacora_sesiones bitacora_sesiones_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bitacora_sesiones
    ADD CONSTRAINT bitacora_sesiones_pkey PRIMARY KEY (id);


--
-- Name: compras compras_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compras
    ADD CONSTRAINT compras_pkey PRIMARY KEY (id);


--
-- Name: detalle_factura detalle_factura_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.detalle_factura
    ADD CONSTRAINT detalle_factura_pkey PRIMARY KEY (id);


--
-- Name: empleados empleados_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.empleados
    ADD CONSTRAINT empleados_pkey PRIMARY KEY (id);


--
-- Name: facturas facturas_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.facturas
    ADD CONSTRAINT facturas_pkey PRIMARY KEY (id);


--
-- Name: lotes lotes_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.lotes
    ADD CONSTRAINT lotes_pkey PRIMARY KEY (id);


--
-- Name: movimiento_caja movimiento_caja_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.movimiento_caja
    ADD CONSTRAINT movimiento_caja_pkey PRIMARY KEY (id);


--
-- Name: productores_abonos productores_abonos_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_abonos
    ADD CONSTRAINT productores_abonos_pkey PRIMARY KEY (id);


--
-- Name: productores_descuentos productores_descuentos_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_descuentos
    ADD CONSTRAINT productores_descuentos_pkey PRIMARY KEY (id);


--
-- Name: productores_entregas productores_entregas_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_entregas
    ADD CONSTRAINT productores_entregas_pkey PRIMARY KEY (id);


--
-- Name: productores productores_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores
    ADD CONSTRAINT productores_pkey PRIMARY KEY (id);


--
-- Name: productores_precios_historial productores_precios_historial_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_precios_historial
    ADD CONSTRAINT productores_precios_historial_pkey PRIMARY KEY (id);


--
-- Name: productos productos_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productos
    ADD CONSTRAINT productos_codigo_unique UNIQUE (codigo);


--
-- Name: productos productos_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productos
    ADD CONSTRAINT productos_pkey PRIMARY KEY (id);


--
-- Name: proveedores_entregas proveedores_entregas_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.proveedores_entregas
    ADD CONSTRAINT proveedores_entregas_pkey PRIMARY KEY (id);


--
-- Name: proveedores_pagos proveedores_pagos_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.proveedores_pagos
    ADD CONSTRAINT proveedores_pagos_pkey PRIMARY KEY (id);


--
-- Name: proveedores proveedores_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.proveedores
    ADD CONSTRAINT proveedores_pkey PRIMARY KEY (id);


--
-- Name: tasa_cambio tasa_cambio_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.tasa_cambio
    ADD CONSTRAINT tasa_cambio_pkey PRIMARY KEY (id);


--
-- Name: transporte_asignaciones transporte_asignaciones_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transporte_asignaciones
    ADD CONSTRAINT transporte_asignaciones_pkey PRIMARY KEY (id);


--
-- Name: transporte transporte_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transporte
    ADD CONSTRAINT transporte_pkey PRIMARY KEY (id);


--
-- Name: usuarios usuarios_email_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_email_unique UNIQUE (email);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id);


--
-- Name: vehiculos vehiculos_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehiculos
    ADD CONSTRAINT vehiculos_pkey PRIMARY KEY (id);


--
-- Name: compras compras_proveedor_id_proveedores_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compras
    ADD CONSTRAINT compras_proveedor_id_proveedores_id_fk FOREIGN KEY (proveedor_id) REFERENCES public.proveedores(id);


--
-- Name: detalle_factura detalle_factura_factura_id_facturas_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.detalle_factura
    ADD CONSTRAINT detalle_factura_factura_id_facturas_id_fk FOREIGN KEY (factura_id) REFERENCES public.facturas(id) ON DELETE CASCADE;


--
-- Name: detalle_factura detalle_factura_producto_id_productos_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.detalle_factura
    ADD CONSTRAINT detalle_factura_producto_id_productos_id_fk FOREIGN KEY (producto_id) REFERENCES public.productos(id);


--
-- Name: lotes lotes_producto_id_productos_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.lotes
    ADD CONSTRAINT lotes_producto_id_productos_id_fk FOREIGN KEY (producto_id) REFERENCES public.productos(id) ON DELETE CASCADE;


--
-- Name: productores_abonos productores_abonos_productor_id_productores_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_abonos
    ADD CONSTRAINT productores_abonos_productor_id_productores_id_fk FOREIGN KEY (productor_id) REFERENCES public.productores(id) ON DELETE CASCADE;


--
-- Name: productores_descuentos productores_descuentos_productor_id_productores_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_descuentos
    ADD CONSTRAINT productores_descuentos_productor_id_productores_id_fk FOREIGN KEY (productor_id) REFERENCES public.productores(id) ON DELETE CASCADE;


--
-- Name: productores_entregas productores_entregas_productor_id_productores_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_entregas
    ADD CONSTRAINT productores_entregas_productor_id_productores_id_fk FOREIGN KEY (productor_id) REFERENCES public.productores(id) ON DELETE CASCADE;


--
-- Name: productores_precios_historial productores_precios_historial_productor_id_productores_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_precios_historial
    ADD CONSTRAINT productores_precios_historial_productor_id_productores_id_fk FOREIGN KEY (productor_id) REFERENCES public.productores(id) ON DELETE CASCADE;


--
-- Name: productores_precios_historial productores_precios_historial_usuario_id_usuarios_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.productores_precios_historial
    ADD CONSTRAINT productores_precios_historial_usuario_id_usuarios_id_fk FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: proveedores_entregas proveedores_entregas_proveedor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.proveedores_entregas
    ADD CONSTRAINT proveedores_entregas_proveedor_id_fkey FOREIGN KEY (proveedor_id) REFERENCES public.proveedores(id) ON DELETE CASCADE;


--
-- Name: proveedores_pagos proveedores_pagos_proveedor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.proveedores_pagos
    ADD CONSTRAINT proveedores_pagos_proveedor_id_fkey FOREIGN KEY (proveedor_id) REFERENCES public.proveedores(id) ON DELETE CASCADE;


--
-- Name: transporte_asignaciones transporte_asignaciones_empleado_id_empleados_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transporte_asignaciones
    ADD CONSTRAINT transporte_asignaciones_empleado_id_empleados_id_fk FOREIGN KEY (empleado_id) REFERENCES public.empleados(id) ON DELETE CASCADE;


--
-- Name: transporte_asignaciones transporte_asignaciones_transporte_id_transporte_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transporte_asignaciones
    ADD CONSTRAINT transporte_asignaciones_transporte_id_transporte_id_fk FOREIGN KEY (transporte_id) REFERENCES public.transporte(id) ON DELETE CASCADE;


--
-- Name: transporte transporte_conductor_actual_id_empleados_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transporte
    ADD CONSTRAINT transporte_conductor_actual_id_empleados_id_fk FOREIGN KEY (conductor_actual_id) REFERENCES public.empleados(id) ON DELETE SET NULL;


--
-- Name: vehiculos vehiculos_conductor_actual_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehiculos
    ADD CONSTRAINT vehiculos_conductor_actual_id_fkey FOREIGN KEY (conductor_actual_id) REFERENCES public.empleados(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict amy7p7g9k6D2ePCButegDhn8f7BDZmfsuDRvZDJN7LXsqA9neRk2NCHJampNoez

