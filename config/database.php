<?php
declare(strict_types=1);

class DatabaseException extends RuntimeException
{
}

/** Modo multiempresa sin empresa elegida: ninguna consulta de datos puede caer en una base por defecto. */
final class SinEmpresaException extends DatabaseException
{
}

/**
 * Conexiones PDO.
 *
 * Una empresa (por defecto): ADARA_DB_NAME, igual que siempre.
 * Multiempresa (Database-per-Tenant): se activa al definir ADARA_MASTER_DB_NAME.
 *   - master()     → base de control (super_admins, clientes_saas).
 *   - connection() → base física de la empresa elegida con usarEmpresa(). Sin empresa, lanza
 *                    SinEmpresaException en lugar de usar una base cualquiera (fuga entre empresas).
 * get_db() sigue devolviendo connection(), así que modelos y servicios no cambian.
 */
final class Database
{
    /** Nombres de base que pueden entrar al DSN. */
    private const PATRON_BASE = '/^[a-z0-9_]{1,64}$/';

    private static ?PDO $pdo = null;
    private static ?PDO $master = null;
    private static ?string $baseEmpresa = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public function __wakeup(): void
    {
        throw new LogicException('Database no puede deserializarse.');
    }

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (self::multiempresa()) {
            if (self::$baseEmpresa === null) {
                throw new SinEmpresaException('No hay una empresa seleccionada para esta sesión.');
            }
            return self::$pdo = self::connect(self::$baseEmpresa);
        }
        return self::$pdo = self::connect(self::config()['name']);
    }

    public static function master(): PDO
    {
        $nombre = self::nombreMaster() ?? throw new LogicException('ADARA_MASTER_DB_NAME no está configurada.');
        return self::$master ??= self::connect($nombre);
    }

    public static function multiempresa(): bool
    {
        return self::nombreMaster() !== null;
    }

    /**
     * Apunta connection() a la base física de una empresa. La conexión anterior se descarta, así que
     * no puede quedar abierta una transacción ni un objeto PDO de otra empresa en uso.
     */
    public static function usarEmpresa(string $base): void
    {
        if (!preg_match(self::PATRON_BASE, $base) || $base === self::nombreMaster()) {
            throw new DatabaseException('Nombre de base de empresa inválido.');
        }
        if (self::$baseEmpresa === $base) {
            return;
        }
        if (self::$pdo !== null && self::$pdo->inTransaction()) {
            throw new LogicException('No se puede cambiar de empresa con una transacción abierta.');
        }
        self::$pdo = null;
        self::$baseEmpresa = $base;
    }

    public static function empresaActiva(): ?string
    {
        return self::$baseEmpresa;
    }

    /**
     * Conexión independiente a una base de empresa (aprovisionamiento). No reemplaza connection()
     * ni comparte transacción con la sesión. El llamador valida que la base sea suya.
     */
    public static function conectarA(string $base): PDO
    {
        if (!preg_match(self::PATRON_BASE, $base) || $base === self::nombreMaster()) {
            throw new DatabaseException('Nombre de base de empresa inválido.');
        }
        return self::connect($base);
    }

    /**
     * Ejecuta $work dentro de una transacción. Si ya hay una abierta, se une a ella
     * para que operaciones compuestas (venta + stock + caja + auditoría) sean atómicas.
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $work($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function nombreMaster(): ?string
    {
        $nombre = getenv('ADARA_MASTER_DB_NAME');
        return is_string($nombre) && $nombre !== '' ? $nombre : null;
    }

    private static function connect(string $base): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new DatabaseException('La extensión pdo_mysql no está habilitada en PHP.');
        }
        if (!preg_match(self::PATRON_BASE, $base)) {
            throw new DatabaseException('Nombre de base inválido.');
        }

        $cfg = self::config();
        $usuario = $cfg['user'];
        $clave = $cfg['pass'];
        // En Hostinger cada base tiene su propio usuario. La maestra no entra con el de la empresa.
        if ($base === self::nombreMaster()) {
            $usuario = $cfg['master_user'];
            $clave = $cfg['master_pass'];
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['port'],
            $base
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => 5,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION time_zone = '-04:00', "
                . "sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'",
        ];

        try {
            $pdo = new PDO($dsn, $usuario, $clave, $options);
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            return $pdo;
        } catch (PDOException $e) {
            // El detalle técnico solo va al log; al usuario nunca se le muestra host, usuario ni SQLSTATE.
            error_log(sprintf(
                '[ADARA][DB] Conexión fallida a %s:%d/%s (%s): %s',
                $cfg['host'],
                $cfg['port'],
                $base,
                (string) $e->getCode(),
                $e->getMessage()
            ));
            throw new DatabaseException('No se pudo conectar con la base de datos.');
        }
    }

    /** @return array{host:string, port:int, name:string, user:string, pass:string, master_user:string, master_pass:string} */
    private static function config(): array
    {
        $env = static fn (string $key, string $default): string =>
            ($value = getenv($key)) !== false && $value !== '' ? $value : $default;

        $user = $env('ADARA_DB_USER', 'root');
        $pass = getenv('ADARA_DB_PASS') ?: '';

        return [
            // En Linux, "localhost" usa el socket y se salta el TCP de 127.0.0.1.
            // En Windows el socket no existe: se queda en TCP.
            'host' => $env('ADARA_DB_HOST', PHP_OS_FAMILY === 'Windows' ? '127.0.0.1' : 'localhost'),
            'port' => (int) $env('ADARA_DB_PORT', '3306'),
            'name' => $env('ADARA_DB_NAME', 'adara'),
            'user' => $user,
            'pass' => $pass,
            'master_user' => $env('ADARA_MASTER_DB_USER', $user),
            'master_pass' => $env('ADARA_MASTER_DB_PASS', $pass),
        ];
    }
}

function get_db(): PDO
{
    return Database::connection();
}
