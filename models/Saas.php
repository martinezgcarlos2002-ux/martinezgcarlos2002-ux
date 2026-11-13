<?php
declare(strict_types=1);

/** Acceso a la base maestra (adara_master): super admins, empresas suscritas y su bitácora. */
final class Saas
{
    /**
     * `adara` es la base del primer cliente local. Las demás empiezan por adara_.
     * En Hostinger el nombre lo asigna el panel: u980302731_adarabd.
     * Nunca la base maestra (adara_master o la de ADARA_MASTER_DB_NAME).
     */
    public const PATRON_DB = '/^(adara|adara_[a-z0-9_]{1,58}|u[0-9]{6,12}_[a-z0-9_]{1,40})$/';
    public const PATRON_SLUG = '/^[a-z0-9]([a-z0-9.-]{1,98}[a-z0-9])?$/';
    /** Dueño del software: intocable (además protegido por triggers en adara_master). */
    public const PROPIETARIO_ID = 1;
    public const CLAVE_MIN_SUPER_ADMIN = 12;
    /** Venezolano, extranjero, jurídico, gubernamental. */
    public const PREFIJOS_CEDULA = ['V-', 'E-', 'J-', 'G-'];

    /** La base de control no puede registrarse como empresa. */
    public static function baseReservada(string $base): bool
    {
        if ($base === 'adara_master') {
            return true;
        }
        $master = getenv('ADARA_MASTER_DB_NAME');
        return is_string($master) && $master !== '' && hash_equals($master, $base);
    }

    /** Clave temporal legible (sin 0/O, 1/l/I) con 16 caracteres de random_int: ~93 bits. */
    public static function claveTemporal(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alfabeto) - 1;
        $clave = '';
        for ($i = 0; $i < 16; $i++) {
            $clave .= $alfabeto[random_int(0, $max)];
        }
        return substr($clave, 0, 4) . '-' . substr($clave, 4, 4) . '-' . substr($clave, 8, 4) . '-' . substr($clave, 12, 4);
    }

    public function __construct(private PDO $db)
    {
    }

    /** @return array{id:int, nombre:string, correo:string, password_hash:string, activo:int}|null */
    public function superAdminPorCorreo(string $correo): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, correo, password_hash, activo FROM super_admins WHERE correo = :correo LIMIT 1'
        );
        $stmt->execute(['correo' => $correo]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array{id:int, nombre:string, correo:string, cedula:string, telefono:string, direccion:string, debe_cambiar_clave:bool}|null */
    public function superAdminActivo(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, correo, cedula, telefono, direccion, debe_cambiar_clave FROM super_admins
             WHERE id = :id AND activo = 1 AND revocado_en IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'correo' => (string) $row['correo'],
            'cedula' => (string) ($row['cedula'] ?? ''),
            'telefono' => (string) ($row['telefono'] ?? ''),
            'direccion' => (string) ($row['direccion'] ?? ''),
            'debe_cambiar_clave' => (int) $row['debe_cambiar_clave'] === 1,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function superAdmins(): array
    {
        return $this->db->query(
            'SELECT s.id, s.nombre, s.correo, s.cedula, s.telefono, s.direccion, s.activo, s.debe_cambiar_clave,
                    s.revocado_en, s.ultimo_acceso, s.creado_en, c.nombre AS creado_por_nombre
             FROM super_admins s
             LEFT JOIN super_admins c ON c.id = s.creado_por
             ORDER BY s.id'
        )->fetchAll();
    }

    /**
     * Nuevo super admin con clave temporal (debe cambiarla en su primer acceso).
     *
     * @return array{id:int, clave:string}
     * @throws ValidationException
     */
    public function crearSuperAdmin(string $nombre, string $correo, string $cedula, string $telefono, string $direccion, int $actorId): array
    {
        $this->exigirPropietario($actorId);
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
        $correo = mb_strtolower(trim($correo));
        $cedula = strtoupper(trim(preg_replace('/\s+/u', ' ', $cedula) ?? ''));
        $telefono = trim(preg_replace('/\s+/u', ' ', $telefono) ?? '');
        $direccion = trim(preg_replace('/\s+/u', ' ', $direccion) ?? '');
        $errores = [];
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) {
            $errores['sa_nombre'] = 'Nombre inválido (2 a 80 caracteres).';
        }
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false || mb_strlen($correo) > 100) {
            $errores['sa_correo'] = 'Correo inválido.';
        } elseif ($this->clientePorCorreoAdmin($correo) !== null) {
            $errores['sa_correo'] = 'Ese correo administra una empresa cliente; usa otro.';
        } elseif ($this->superAdminPorCorreo($correo) !== null) {
            $errores['sa_correo'] = 'Ya existe un super admin con ese correo (los revocados no se reutilizan).';
        }
        if (!in_array(substr($cedula, 0, 2), self::PREFIJOS_CEDULA, true) || preg_match('/^[VEJG]-[1-9][0-9]{4,9}$/', $cedula) !== 1) {
            $errores['sa_cedula'] = 'Cédula inválida: elige V-, E-, J- o G- y escribe de 5 a 10 dígitos, sin puntos.';
        }
        if ($telefono === '' || mb_strlen($telefono) > 30 || preg_match('/^[0-9+\-() ]{7,30}$/', $telefono) !== 1) {
            $errores['sa_telefono'] = 'Teléfono inválido (7 a 30 dígitos, se admite + - y paréntesis).';
        }
        if (mb_strlen($direccion) < 8 || mb_strlen($direccion) > 500) {
            $errores['sa_direccion'] = 'Dirección inválida (8 a 500 caracteres).';
        }
        if ($errores !== []) {
            throw new ValidationException($errores);
        }
        $clave = self::claveTemporal();
        $this->db->prepare(
            'INSERT INTO super_admins (nombre, correo, cedula, telefono, direccion, password_hash, debe_cambiar_clave, creado_por)
             VALUES (:nombre, :correo, :cedula, :telefono, :direccion, :hash, 1, :actor)'
        )->execute([
            'nombre'    => $nombre,
            'correo'    => $correo,
            'cedula'    => $cedula,
            'telefono'  => $telefono,
            'direccion' => $direccion,
            'hash'      => password_hash($clave, PASSWORD_ALGO, PASSWORD_OPTIONS),
            'actor'     => $actorId,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->auditar($actorId, 'crear_super_admin', null, [
            'nuevo_id' => $id,
            'correo'   => $correo,
            'cedula'   => $cedula,
        ]);
        return ['id' => $id, 'clave' => $clave];
    }

    /** Suspender (activo = 0) o reactivar un super admin. Nunca el propietario ni a uno mismo. */
    public function cambiarEstadoSuperAdmin(int $id, bool $activo, int $actorId): void
    {
        $objetivo = $this->exigirGestionable($id, $actorId);
        if ($objetivo['revocado_en'] !== null) {
            throw new ValidationException(['id' => 'Ese acceso está revocado. Usa Reactivar para devolverle una clave temporal.']);
        }
        $this->db->prepare('UPDATE super_admins SET activo = :activo WHERE id = :id')
            ->execute(['activo' => (int) $activo, 'id' => $id]);
        $this->auditar($actorId, $activo ? 'reactivar_super_admin' : 'suspender_super_admin', null, ['objetivo_id' => $id]);
    }

    /** Revoca el acceso y anula la clave. El propietario puede reactivarlo después. La fila no se borra. */
    public function revocarSuperAdmin(int $id, int $actorId): void
    {
        $objetivo = $this->exigirGestionable($id, $actorId);
        if ($objetivo['revocado_en'] !== null) {
            throw new ValidationException(['id' => 'Ese acceso ya estaba revocado.']);
        }
        $this->db->prepare(
            'UPDATE super_admins SET activo = 0, revocado_en = NOW(), password_hash = :hash WHERE id = :id'
        )->execute(['hash' => password_hash(self::claveTemporal(), PASSWORD_ALGO, PASSWORD_OPTIONS), 'id' => $id]);
        $this->auditar($actorId, 'revocar_super_admin', null, ['objetivo_id' => $id, 'correo' => $objetivo['correo']]);
    }

    /**
     * Devuelve un acceso revocado a operación, solo el propietario.
     * La clave anterior queda inservible: se entrega una temporal que debe cambiarse al entrar.
     *
     * @return array{id:int, correo:string, clave:string}
     * @throws ValidationException
     */
    public function reactivarSuperAdmin(int $id, int $actorId): array
    {
        $objetivo = $this->exigirGestionable($id, $actorId);
        if ($objetivo['revocado_en'] === null) {
            throw new ValidationException(['id' => 'Ese acceso no está revocado.']);
        }
        $clave = self::claveTemporal();
        $stmt = $this->db->prepare(
            'UPDATE super_admins
             SET activo = 1, revocado_en = NULL, debe_cambiar_clave = 1, password_hash = :hash
             WHERE id = :id AND revocado_en IS NOT NULL'
        );
        $stmt->execute([
            'hash' => password_hash($clave, PASSWORD_ALGO, PASSWORD_OPTIONS),
            'id'   => $id,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new ValidationException(['id' => 'No se pudo reactivar ese acceso.']);
        }
        $this->auditar($actorId, 'reactivar_super_admin_revocado', null, [
            'objetivo_id' => $id,
            'correo'      => $objetivo['correo'],
        ]);
        return ['id' => $id, 'correo' => $objetivo['correo'], 'clave' => $clave];
    }

    /** Cada super admin cambia solo su propia clave (incluido el propietario). */
    public function cambiarClavePropia(int $id, string $actual, string $nueva, string $confirmacion): void
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM super_admins WHERE id = :id AND activo = 1 AND revocado_en IS NULL');
        $stmt->execute(['id' => $id]);
        $hash = $stmt->fetchColumn();
        $errores = [];
        if (!is_string($hash) || !password_verify($actual, $hash)) {
            $errores['actual'] = 'La clave actual no es correcta.';
        }
        if (mb_strlen($nueva) < self::CLAVE_MIN_SUPER_ADMIN || strlen($nueva) > PASSWORD_MAX_BYTES) {
            $errores['nueva'] = sprintf('La nueva clave debe tener entre %d caracteres y %d bytes.', self::CLAVE_MIN_SUPER_ADMIN, PASSWORD_MAX_BYTES);
        } elseif (is_string($hash) && password_verify($nueva, $hash)) {
            $errores['nueva'] = 'La nueva clave tiene que ser distinta de la actual.';
        } elseif (!hash_equals($nueva, $confirmacion)) {
            $errores['confirmacion'] = 'La confirmación no coincide.';
        }
        if ($errores !== []) {
            throw new ValidationException($errores);
        }
        $this->db->prepare('UPDATE super_admins SET password_hash = :hash, debe_cambiar_clave = 0 WHERE id = :id')
            ->execute(['hash' => password_hash($nueva, PASSWORD_ALGO, PASSWORD_OPTIONS), 'id' => $id]);
        $this->auditar($id, 'cambiar_clave_propia', null, null);
    }

    /** La gestión de accesos es exclusiva del propietario. */
    private function exigirPropietario(int $actorId): void
    {
        if ($actorId !== self::PROPIETARIO_ID) {
            $this->auditar($actorId, 'acceso_denegado', null, ['accion' => 'gestion_accesos']);
            throw new ValidationException(['general' => 'Solo el propietario de ADARA gestiona los accesos de super admin.']);
        }
    }

    /** @return array{id:int, correo:string, revocado_en:?string} */
    private function exigirGestionable(int $id, int $actorId): array
    {
        $this->exigirPropietario($actorId);
        if ($id === self::PROPIETARIO_ID) {
            $this->auditar($actorId, 'acceso_denegado', null, ['accion' => 'tocar_propietario']);
            throw new ValidationException(['id' => 'El super admin propietario es intocable.']);
        }
        if ($id === $actorId) {
            throw new ValidationException(['id' => 'No puedes suspender ni revocar tu propio acceso.']);
        }
        $stmt = $this->db->prepare('SELECT id, correo, revocado_en FROM super_admins WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['id' => 'Ese super admin no existe.']);
        }
        return ['id' => (int) $row['id'], 'correo' => (string) $row['correo'], 'revocado_en' => $row['revocado_en']];
    }

    /**
     * Firmas del acuerdo. Solo las consulta el propietario.
     *
     * @return list<array{creado_en:string, ip:string, nombre:string, correo:string, texto:?string}>
     */
    public function firmasAcuerdo(): array
    {
        return $this->db->query(
            "SELECT a.creado_en, a.ip, s.nombre, s.correo, JSON_VALUE(a.detalle, '$.texto') AS texto
             FROM master_auditoria a
             JOIN super_admins s ON s.id = a.super_admin_id
             WHERE a.accion = 'firma_acuerdo'
             ORDER BY a.id DESC"
        )->fetchAll();
    }

    public function actualizarHashSuperAdmin(int $id, string $hash): void
    {
        $this->db->prepare('UPDATE super_admins SET password_hash = :hash WHERE id = :id')->execute(['hash' => $hash, 'id' => $id]);
    }

    public function marcarAccesoSuperAdmin(int $id): void
    {
        $this->db->prepare('UPDATE super_admins SET ultimo_acceso = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function clientePorId(int $id): ?array
    {
        return $this->uno('SELECT * FROM clientes_saas WHERE id = :v', $id);
    }

    /** @return array<string, mixed>|null */
    public function clientePorCorreoAdmin(string $correo): ?array
    {
        return $this->uno('SELECT * FROM clientes_saas WHERE correo_admin_cliente = :v', $correo);
    }

    /**
     * Empresa por el Host de la petición: coincidencia exacta (dominio propio, p. ej. erp.castillo.com)
     * o primer subdominio (castillo.adara.app → castillo).
     *
     * @return array<string, mixed>|null
     */
    public function clientePorHost(string $host): ?array
    {
        $host = strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? '');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')) {
            return null;
        }
        $exacto = $this->uno('SELECT * FROM clientes_saas WHERE dominio_o_slug = :v', $host);
        if ($exacto !== null) {
            return $exacto;
        }
        $etiqueta = explode('.', $host)[0];
        return $etiqueta === 'www' ? null : $this->uno('SELECT * FROM clientes_saas WHERE dominio_o_slug = :v', $etiqueta);
    }

    /** Slug o dominio exacto, para el login local (?empresa=) cuando el host no identifica al cliente. */
    public function clientePorSlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match(self::PATRON_SLUG, $slug)) {
            return null;
        }
        return $this->uno('SELECT * FROM clientes_saas WHERE dominio_o_slug = :v', $slug);
    }

    /**
     * Empresa del login público: primero el host, y si no alcanza (localhost) el parámetro empresa.
     */
    public function clienteDePeticionPublica(): ?array
    {
        $cliente = $this->clientePorHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($cliente !== null) {
            return $cliente;
        }
        return $this->clientePorSlug((string) ($_GET['empresa'] ?? ''));
    }

    /**
     * Empresas donde buscar un empleado cuando la dirección no identifica a una sola
     * (localhost, o un dominio compartido por varias).
     *
     * @return list<array{id:int, nombre_empresa:string, nombre_db_fisica:string, estado_licencia:string}>
     */
    public function empresasParaLogin(): array
    {
        $rows = $this->db->query(
            'SELECT id, nombre_empresa, nombre_db_fisica, estado_licencia FROM clientes_saas ORDER BY id'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'nombre_empresa' => (string) $row['nombre_empresa'],
                'nombre_db_fisica' => (string) $row['nombre_db_fisica'],
                'estado_licencia' => (string) $row['estado_licencia'],
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function clientes(): array
    {
        $rows = $this->db->query(
            'SELECT c.*, s.nombre AS registrado_por_nombre,
                    (SELECT COUNT(*) FROM information_schema.SCHEMATA x WHERE x.SCHEMA_NAME = c.nombre_db_fisica) AS base_existe
             FROM clientes_saas c
             JOIN super_admins s ON s.id = c.registrado_por
             ORDER BY c.nombre_empresa'
        )->fetchAll();
        foreach ($rows as &$row) {
            if ((int) $row['base_existe'] === 1) {
                continue;
            }
            // En Hostinger el usuario de la base maestra no lista las demás bases, aunque existan.
            $base = (string) $row['nombre_db_fisica'];
            if (!preg_match(self::PATRON_DB, $base) || self::baseReservada($base)) {
                continue;
            }
            try {
                Database::conectarA($base)->query('SELECT 1');
                $row['base_existe'] = 1;
            } catch (Throwable) {
                $row['base_existe'] = 0;
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * @param array{nombre_empresa:string, dominio_o_slug:string, correo_admin_cliente:string, nombre_db_fisica:string} $datos
     * @param 'activa'|'inactiva' $estado inactiva mientras se aprovisiona (nadie entra a una base a medio crear)
     * @throws ValidationException
     */
    public function registrarCliente(array $datos, int $superAdminId, string $estado = 'activa'): int
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $datos['nombre_empresa']) ?? '');
        $slug = strtolower(trim($datos['dominio_o_slug']));
        $correo = mb_strtolower(trim($datos['correo_admin_cliente']));
        $base = strtolower(trim($datos['nombre_db_fisica']));

        $errores = [];
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
            $errores['nombre_empresa'] = 'Indica el nombre de la empresa (2 a 120 caracteres).';
        }
        if (!preg_match(self::PATRON_SLUG, $slug)) {
            $errores['dominio_o_slug'] = 'Slug o dominio inválido: minúsculas, números, punto y guion. Ej.: castillo o erp.castillo.com';
        }
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false || mb_strlen($correo) > 100) {
            $errores['correo_admin_cliente'] = 'Correo del administrador inválido.';
        } elseif ($this->superAdminPorCorreo($correo) !== null) {
            $errores['correo_admin_cliente'] = 'Ese correo pertenece a un super admin; usa otro para la empresa.';
        }
        if (!preg_match(self::PATRON_DB, $base) || self::baseReservada($base)) {
            $errores['nombre_db_fisica'] = 'La base debe llamarse adara, empezar por adara_ o por el prefijo de Hostinger (u y números). Ej.: adara_castillo o u980302731_adarabd';
        }
        if ($errores !== []) {
            throw new ValidationException($errores);
        }

        try {
            $this->db->prepare(
                'INSERT INTO clientes_saas (nombre_empresa, dominio_o_slug, correo_admin_cliente, nombre_db_fisica, estado_licencia, registrado_por)
                 VALUES (:nombre, :slug, :correo, :base, :estado, :admin)'
            )->execute([
                'nombre' => $nombre, 'slug' => $slug, 'correo' => $correo, 'base' => $base,
                'estado' => $estado === 'inactiva' ? 'inactiva' : 'activa', 'admin' => $superAdminId,
            ]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $msg = (string) $e->getMessage();
            throw new ValidationException(match (true) {
                str_contains($msg, 'uq_clientes_slug')   => ['dominio_o_slug' => 'Ese slug o dominio ya está registrado.'],
                str_contains($msg, 'uq_clientes_correo') => ['correo_admin_cliente' => 'Ese correo ya administra otra empresa.'],
                str_contains($msg, 'uq_clientes_db')     => ['nombre_db_fisica' => 'Esa base física ya está asignada a otra empresa.'],
                default                                  => ['general' => 'La empresa ya existe.'],
            });
        }
        $id = (int) $this->db->lastInsertId();
        $this->auditar($superAdminId, 'registrar_empresa', $id, ['slug' => $slug, 'base' => $base, 'correo' => $correo]);
        return $id;
    }

    /** Rollback de aprovisionamiento: solo se usa para una empresa recién insertada que no llegó a activarse. */
    public function eliminarClienteNoActivado(int $id): void
    {
        $this->db->prepare("DELETE FROM clientes_saas WHERE id = :id AND estado_licencia = 'inactiva'")->execute(['id' => $id]);
    }

    public function activarClienteAprovisionado(int $id): void
    {
        $this->db->prepare("UPDATE clientes_saas SET estado_licencia = 'activa' WHERE id = :id")->execute(['id' => $id]);
    }

    /** @param 'activa'|'inactiva' $estado */
    public function cambiarLicencia(int $clienteId, string $estado, int $superAdminId): void
    {
        if (!in_array($estado, ['activa', 'inactiva'], true)) {
            throw new ValidationException(['estado_licencia' => 'Estado de licencia inválido.']);
        }
        $stmt = $this->db->prepare('UPDATE clientes_saas SET estado_licencia = :estado WHERE id = :id');
        $stmt->execute(['estado' => $estado, 'id' => $clienteId]);
        if ($stmt->rowCount() === 0 && $this->clientePorId($clienteId) === null) {
            throw new ValidationException(['id' => 'La empresa no existe.']);
        }
        $this->auditar($superAdminId, $estado === 'activa' ? 'activar_licencia' : 'suspender_licencia', $clienteId, null);
    }

    /** @param array<string, mixed>|null $detalle */
    public function auditar(?int $superAdminId, string $accion, ?int $clienteId, ?array $detalle): void
    {
        $this->db->prepare(
            'INSERT INTO master_auditoria (super_admin_id, accion, cliente_id, detalle, ip)
             VALUES (:admin, :accion, :cliente, :detalle, :ip)'
        )->execute([
            'admin'   => $superAdminId,
            'accion'  => mb_substr($accion, 0, 40),
            'cliente' => $clienteId,
            'detalle' => $detalle === null ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip'      => client_ip(),
        ]);
        if ($superAdminId === null || $superAdminId < 1) {
            return;
        }
        $texto = $accion;
        if (is_array($detalle)) {
            $extra = $detalle['texto'] ?? null;
            if (is_string($extra) && $extra !== '') {
                $texto = $extra;
            } else {
                $texto .= ' ' . json_encode($detalle, JSON_UNESCAPED_UNICODE);
            }
        }
        try {
            $this->registrarAuditoria($superAdminId, $clienteId, $texto);
        } catch (Throwable $e) {
            error_log('[ADARA][soporte_auditoria] ' . $e->getMessage());
        }
    }

    /** Registro inmutable de lo que hace cada Usuario Maestro. La tabla rechaza UPDATE y DELETE. */
    public function registrarAuditoria(int $idMaestro, ?int $idCliente, string $accion): void
    {
        $accion = trim($accion);
        if ($idMaestro < 1 || $accion === '') {
            return;
        }
        $this->db->prepare(
            'INSERT INTO soporte_auditoria (id_maestro, id_cliente, accion_realizada, direccion_ip)
             VALUES (:maestro, :cliente, :accion, :ip)'
        )->execute([
            'maestro' => $idMaestro,
            'cliente' => $idCliente,
            'accion'  => mb_substr($accion, 0, 500),
            'ip'      => client_ip(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function auditoriaSoporte(int $limite = 400): array
    {
        $limite = max(1, min(400, $limite));
        $sql = 'SELECT a.id, a.id_maestro, a.id_cliente, a.accion_realizada, a.fecha_hora, a.direccion_ip,
                       s.nombre AS maestro, s.correo, c.nombre_empresa
                FROM soporte_auditoria a
                INNER JOIN super_admins s ON s.id = a.id_maestro
                LEFT JOIN clientes_saas c ON c.id = a.id_cliente
                ORDER BY a.fecha_hora DESC, a.id DESC
                LIMIT ' . $limite;
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bitacora(string $fecha): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.id, b.id_maestro, b.id_cliente, b.descripcion_tarea, b.estado_tarea, b.fecha,
                    s.nombre AS maestro, c.nombre_empresa
             FROM soporte_bitacora b
             INNER JOIN super_admins s ON s.id = b.id_maestro
             INNER JOIN clientes_saas c ON c.id = b.id_cliente
             WHERE b.fecha = :fecha
             ORDER BY s.nombre, b.id'
        );
        $stmt->execute(['fecha' => $fecha]);
        return $stmt->fetchAll();
    }

    public function registrarBitacora(int $idMaestro, int $idCliente, string $descripcion, string $estado): void
    {
        if (!in_array($estado, ['en_curso', 'resuelta', 'en_espera'], true)) {
            throw new ValidationException(['estado_tarea' => 'El estado no es válido.']);
        }
        $descripcion = trim($descripcion);
        if (mb_strlen($descripcion) < 8 || mb_strlen($descripcion) > 500) {
            throw new ValidationException(['descripcion_tarea' => 'Describe la tarea (entre 8 y 500 caracteres).']);
        }
        if ($this->clientePorId($idCliente) === null) {
            throw new ValidationException(['id_cliente' => 'Esa empresa no está registrada.']);
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO soporte_bitacora (id_maestro, id_cliente, descripcion_tarea, estado_tarea, fecha)
                 VALUES (:maestro, :cliente, :descripcion, :estado, CURDATE())'
            )->execute([
                'maestro'     => $idMaestro,
                'cliente'     => $idCliente,
                'descripcion' => $descripcion,
                'estado'      => $estado,
            ]);
            $this->registrarAuditoria($idMaestro, $idCliente, 'Bitácora del día: ' . $descripcion);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function uno(string $sql, int|string $valor): ?array
    {
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute(['v' => $valor]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
