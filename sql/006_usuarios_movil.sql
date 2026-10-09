-- =====================================================================
-- ADARA · Migración 006: acceso móvil y baja lógica de usuarios
-- Un usuario no se borra físicamente: auditoria.usuario_id (FK RESTRICT) debe seguir apuntando a él.
-- =====================================================================
USE adara;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

ALTER TABLE usuarios
    ADD COLUMN acceso_movil TINYINT(1)   NOT NULL DEFAULT 0 AFTER activo,
    ADD COLUMN eliminado_en DATETIME     NULL AFTER acceso_movil,
    ADD COLUMN eliminado_por INT UNSIGNED NULL AFTER eliminado_en,
    ADD CONSTRAINT fk_usuarios_eliminador FOREIGN KEY (eliminado_por) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT ck_usuarios_movil CHECK (acceso_movil IN (0,1)),
    ADD CONSTRAINT ck_usuarios_baja CHECK (
        (eliminado_en IS NULL AND eliminado_por IS NULL) OR (eliminado_en IS NOT NULL AND activo = 0)
    );

-- Consulta de "¿ya se descargó el respaldo hoy?" en cada login de administrador.
ALTER TABLE auditoria
    ADD KEY ix_auditoria_accion (accion, creado_en);
