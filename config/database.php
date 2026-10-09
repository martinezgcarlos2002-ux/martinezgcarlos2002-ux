<?php
declare(strict_types=1);

final class DatabaseException extends RuntimeException
{
}

final class Database
{
    private static ?PDO $pdo = null;

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
        return self::$pdo ??= self::connect();
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

    private static function connect(): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new DatabaseException('La extensión pdo_mysql no está habilitada en PHP.');
        }

        $cfg = self::config();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['port'],
            $cfg['name']
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
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            return $pdo;
        } catch (PDOException $e) {
            // El detalle técnico solo va al log; al usuario nunca se le muestra host, usuario ni SQLSTATE.
            error_log(sprintf(
                '[ADARA][DB] Conexión fallida a %s:%d/%s (%s): %s',
                $cfg['host'],
                $cfg['port'],
                $cfg['name'],
                (string) $e->getCode(),
                $e->getMessage()
            ));
            throw new DatabaseException('No se pudo conectar con la base de datos.');
        }
    }

    /** @return array{host:string, port:int, name:string, user:string, pass:string} */
    private static function config(): array
    {
        $env = static fn (string $key, string $default): string =>
            ($value = getenv($key)) !== false && $value !== '' ? $value : $default;

        return [
            'host' => $env('ADARA_DB_HOST', '127.0.0.1'),
            'port' => (int) $env('ADARA_DB_PORT', '3306'),
            'name' => $env('ADARA_DB_NAME', 'adara'),
            'user' => $env('ADARA_DB_USER', 'root'),
            'pass' => getenv('ADARA_DB_PASS') ?: '',
        ];
    }
}

function get_db(): PDO
{
    return Database::connection();
}
