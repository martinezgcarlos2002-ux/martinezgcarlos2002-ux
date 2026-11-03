<?php
declare(strict_types=1);

final class Usuario
{
    public const ROLES = ['maestro', 'admin', 'empleado'];

    public function __construct(private PDO $db)
    {
    }

    /** Única consulta que lee password_hash; su resultado nunca debe guardarse en sesión. */
    public function findForLogin(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, password_hash, activo FROM usuarios WHERE email = :email AND eliminado_en IS NULL LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array{id:int, nombre:string, email:string, rol:string, acceso_movil:bool, permisos:array}|null */
    public function findActiveById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, email, rol, acceso_movil, permisos
             FROM usuarios WHERE id = :id AND activo = 1 AND eliminado_en IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id'           => (int) $row['id'],
            'nombre'       => $row['nombre'],
            'email'        => $row['email'],
            'rol'          => $row['rol'],
            'acceso_movil' => (int) $row['acceso_movil'] === 1,
            'permisos'     => permisos_normalizar($row['permisos']),
        ];
    }

    /** Sin password_hash: apto para vistas y auditoría. */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, email, rol, activo, acceso_movil, permisos, ultimo_acceso, creado_en, eliminado_en
             FROM usuarios WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hidratar($row);
    }

    public function findIdByEmail(string $email): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Usuarios no eliminados, sin password_hash.
     * $ocultarMaestros: el admin de la empresa no ve las cuentas técnicas (rol maestro).
     *
     * @return list<array>
     */
    public function listar(string $q = '', bool $ocultarMaestros = false): array
    {
        $sql = 'SELECT id, nombre, email, rol, activo, acceso_movil, permisos, ultimo_acceso, creado_en, eliminado_en
                FROM usuarios WHERE eliminado_en IS NULL';
        if ($ocultarMaestros) {
            $sql .= " AND rol <> 'maestro'";
        }
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $sql .= ' AND (nombre LIKE :q1 OR email LIKE :q2)';
            $params = ['q1' => $like, 'q2' => $like];
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY nombre');
        $stmt->execute($params);
        return array_map([self::class, 'hidratar'], $stmt->fetchAll());
    }

    /** @param array<string, list<string>> $permisos */
    public function create(string $nombre, string $email, string $passwordHash, string $rol, array $permisos, bool $accesoMovil = false, bool $activo = true): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (nombre, email, password_hash, rol, permisos, acceso_movil, activo)
             VALUES (:nombre, :email, :hash, :rol, :permisos, :acceso_movil, :activo)'
        );
        $stmt->execute([
            'nombre'       => $nombre,
            'email'        => $email,
            'hash'         => $passwordHash,
            'rol'          => $rol,
            'permisos'     => self::permisosJson($permisos),
            'acceso_movil' => (int) $accesoMovil,
            'activo'       => (int) $activo,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array{nombre:string, email:string, rol:string, activo:bool, acceso_movil:bool} $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios
             SET nombre = :nombre, email = :email, rol = :rol, activo = :activo, acceso_movil = :acceso_movil
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $stmt->execute([
            'nombre'       => $data['nombre'],
            'email'        => $data['email'],
            'rol'          => $data['rol'],
            'activo'       => (int) $data['activo'],
            'acceso_movil' => (int) $data['acceso_movil'],
            'id'           => $id,
        ]);
    }

    /** @param array<string, list<string>> $permisos */
    public function updatePermisos(int $id, array $permisos): void
    {
        $stmt = $this->db->prepare('UPDATE usuarios SET permisos = :permisos WHERE id = :id AND eliminado_en IS NULL');
        $stmt->execute(['permisos' => self::permisosJson($permisos), 'id' => $id]);
    }

    public function setAccesoMovil(int $id, bool $permitido): void
    {
        $stmt = $this->db->prepare('UPDATE usuarios SET acceso_movil = :movil WHERE id = :id AND eliminado_en IS NULL');
        $stmt->execute(['movil' => (int) $permitido, 'id' => $id]);
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = $this->db->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $passwordHash, 'id' => $id]);
    }

    /** Baja lógica: el registro se conserva porque la auditoría lo referencia. */
    public function eliminar(int $id, int $eliminadoPor): void
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET activo = 0, acceso_movil = 0, eliminado_en = NOW(), eliminado_por = :por
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $stmt->execute(['por' => $eliminadoPor, 'id' => $id]);
    }

    /** Administradores y maestros activos distintos de $excluirId, bloqueados para evitar quedarse sin ninguno por concurrencia. */
    public function contarAdminsActivos(?int $excluirId = null): int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM usuarios
             WHERE rol IN ('maestro', 'admin') AND activo = 1 AND eliminado_en IS NULL AND id <> :excluir
             FOR UPDATE"
        );
        $stmt->execute(['excluir' => $excluirId ?? 0]);
        return count($stmt->fetchAll());
    }

    /** Usuarios maestro activos distintos de $excluirId. */
    public function contarMaestrosActivos(?int $excluirId = null): int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM usuarios
             WHERE rol = 'maestro' AND activo = 1 AND eliminado_en IS NULL AND id <> :excluir
             FOR UPDATE"
        );
        $stmt->execute(['excluir' => $excluirId ?? 0]);
        return count($stmt->fetchAll());
    }

    /** @return array{id:int, nombre:string, email:string}|null */
    public function findActivoPorEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, email FROM usuarios
             WHERE email = :email AND activo = 1 AND eliminado_en IS NULL LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre'], 'email' => (string) $row['email']];
    }

    /**
     * Guarda el SHA-256 del token con 15 minutos de vigencia (reloj de la base).
     * Devuelve false si ya se emitió uno hace menos de $esperaSegundos: evita usar el formulario para inundar el buzón.
     */
    public function guardarResetToken(int $id, string $tokenHash, int $minutos, int $esperaSegundos): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios
             SET reset_token = :hash, reset_expiracion = NOW() + INTERVAL :minutos MINUTE
             WHERE id = :id AND activo = 1 AND eliminado_en IS NULL
               AND (reset_expiracion IS NULL
                    OR reset_expiracion <= NOW() + INTERVAL :minutos2 MINUTE - INTERVAL :espera SECOND)'
        );
        $stmt->execute(['hash' => $tokenHash, 'minutos' => $minutos, 'minutos2' => $minutos, 'espera' => $esperaSegundos, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{id:int, nombre:string, email:string}|null usuario activo con ese token vigente */
    public function findPorResetToken(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, email FROM usuarios
             WHERE reset_token = :hash AND reset_expiracion > NOW() AND activo = 1 AND eliminado_en IS NULL
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre'], 'email' => (string) $row['email']];
    }

    /**
     * Cambia la clave y anula el token en la misma sentencia. La condición repite token y vigencia:
     * dos envíos simultáneos del mismo enlace no pueden aplicar dos claves.
     */
    public function restablecerClave(int $id, string $tokenHash, string $passwordHash): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios
             SET password_hash = :pwd, reset_token = NULL, reset_expiracion = NULL
             WHERE id = :id AND reset_token = :hash AND reset_expiracion > NOW()
               AND activo = 1 AND eliminado_en IS NULL'
        );
        $stmt->execute(['pwd' => $passwordHash, 'id' => $id, 'hash' => $tokenHash]);
        return $stmt->rowCount() === 1;
    }

    public function touchLastLogin(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, PASSWORD_OPTIONS);
    }

    /** @param array<string, list<string>> $permisos */
    private static function permisosJson(array $permisos): string
    {
        return json_encode((object) permisos_normalizar($permisos), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function hidratar(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'nombre'        => $row['nombre'],
            'email'         => $row['email'],
            'rol'           => $row['rol'],
            'activo'        => (int) $row['activo'] === 1,
            'acceso_movil'  => (int) $row['acceso_movil'] === 1,
            'permisos'      => permisos_normalizar($row['permisos']),
            'ultimo_acceso' => $row['ultimo_acceso'],
            'creado_en'     => $row['creado_en'],
            'eliminado_en'  => $row['eliminado_en'],
        ];
    }
}
