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

    /** @return list<array> usuarios no eliminados, sin password_hash */
    public function listar(string $q = ''): array
    {
        $sql = 'SELECT id, nombre, email, rol, activo, acceso_movil, permisos, ultimo_acceso, creado_en, eliminado_en
                FROM usuarios WHERE eliminado_en IS NULL';
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
