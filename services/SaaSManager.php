<?php
declare(strict_types=1);

final class AprovisionamientoException extends RuntimeException
{
}

/**
 * Alta de empresas en el modelo Database-per-Tenant.
 *
 * aprovisionar() despliega una base nueva:
 *   0. Reserva la empresa en clientes_saas con licencia 'inactiva' (UNIQUE de slug, correo y base:
 *      dos altas simultáneas no pueden pisarse; nadie puede entrar a una base a medio crear).
 *   1. CREATE DATABASE `adara_…` (sin IF NOT EXISTS: si ya existe, falla y NO es nuestra).
 *   2. Ejecuta sql/adara_esquema_limpio.sql sentencia a sentencia (SqlScript interpreta DELIMITER).
 *   3. Marca de la empresa, almacén inicial y usuario admin (correo_admin_cliente + clave temporal).
 *   4. Activa la licencia.
 * Rollback ante cualquier fallo de 1–3: DROP DATABASE solo si esta misma llamada la creó, y borra la
 * fila de clientes_saas. DDL no es transaccional en MariaDB: el rollback es explícito.
 *
 * vincularExistente() registra una empresa cuya base ya existe (p. ej. la migrada de producción):
 * no crea ni borra nada, solo comprueba que la base y su administrador existen.
 *
 * Privilegios del usuario MySQL de la app: CREATE y DROP sobre `adara\_%`.
 */
final class SaaSManager
{
    public const ESQUEMA = ADARA_ROOT . '/sql/adara_esquema_limpio.sql';
    private const LOGO_ADARA = 'img/ADARA LOGO.jpg';

    public function __construct(private PDO $master, private Saas $saas)
    {
    }

    /**
     * @param array{nombre_empresa:string, dominio_o_slug:string, correo_admin_cliente:string, nombre_db_fisica:string} $datos
     * @return array{cliente_id:int, clave_temporal:string, sentencias:int, segundos:float}
     * @throws ValidationException datos inválidos o base existente (nada se creó)
     * @throws AprovisionamientoException fallo del despliegue (todo se revirtió)
     */
    public function aprovisionar(array $datos, int $superAdminId): array
    {
        $inicio = microtime(true);
        $sentencias = $this->leerEsquema();
        $base = strtolower(trim($datos['nombre_db_fisica']));
        if (preg_match(Saas::PATRON_DB, $base) && $this->baseExiste($base)) {
            throw new ValidationException(['nombre_db_fisica' => 'La base ' . $base
                . ' ya existe en el servidor. Si es una empresa ya operativa, marca "La base ya existe".']);
        }

        $clienteId = $this->saas->registrarCliente($datos, $superAdminId, 'inactiva');
        $cliente = $this->saas->clientePorId($clienteId) ?? throw new LogicException('Empresa recién registrada no encontrada.');
        $base = (string) $cliente['nombre_db_fisica'];
        $creada = false;
        $paso = 'crear la base';
        $pdo = null;

        try {
            $this->master->exec('CREATE DATABASE ' . $this->ident($base) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $creada = true;

            $paso = 'cargar el esquema';
            $pdo = Database::conectarA($base);
            foreach ($sentencias as $n => $sql) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    throw new AprovisionamientoException(sprintf('Sentencia %d de %d: %s', $n + 1, count($sentencias), $e->getMessage()), 0, $e);
                }
            }
            $this->verificarEsquema($pdo);

            $paso = 'crear el administrador';
            $clave = Saas::claveTemporal();
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO empresa_configuracion
                    (id, nombre_empresa, rif_documento, telefono, direccion, color_primario, color_secundario, ruta_logo, ruta_sello)
                 VALUES (1, :nombre, \'\', \'\', \'\', \'#F97316\', \'#1A2E5A\', :logo, NULL)'
            )->execute(['nombre' => $cliente['nombre_empresa'], 'logo' => self::LOGO_ADARA]);
            $pdo->exec("INSERT INTO almacenes (nombre, orden) VALUES ('ALMACÉN PRINCIPAL', 10)");
            $adminId = (new Usuario($pdo))->create(
                mb_substr('Administrador ' . $cliente['nombre_empresa'], 0, 80),
                (string) $cliente['correo_admin_cliente'],
                password_hash($clave, PASSWORD_ALGO, PASSWORD_OPTIONS),
                'admin',
                []
            );
            $pdo->prepare(
                "INSERT INTO auditoria (usuario_id, modulo, accion, entidad, entidad_id, detalle, ip, metodo, ruta)
                 VALUES (:id, 'usuarios', 'crear_admin_saas', 'usuarios', :id2, :detalle, :ip, 'POST', 'master_registrar')"
            )->execute([
                'id' => $adminId, 'id2' => $adminId, 'ip' => client_ip(),
                'detalle' => json_encode(['super_admin_id' => $superAdminId], JSON_THROW_ON_ERROR),
            ]);
            $pdo->commit();
            $pdo = null;

            $this->saas->activarClienteAprovisionado($clienteId);
        } catch (Throwable $e) {
            // Cerrar la conexión de la empresa antes del DROP: una transacción abierta retiene bloqueos
            // de metadatos y el DROP DATABASE esperaría indefinidamente.
            try {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // Conexión ya perdida: MySQL libera sus bloqueos al cerrarla.
            }
            $pdo = null;
            $this->revertir($clienteId, $base, $creada, $superAdminId, $paso, $e);
            throw new AprovisionamientoException(
                'No se pudo ' . $paso . ' de ' . $cliente['nombre_empresa'] . '. Se revirtió todo: '
                . ($creada ? 'la base se eliminó y ' : '') . 'la empresa no quedó registrada.',
                0,
                $e
            );
        }

        $segundos = round(microtime(true) - $inicio, 2);
        $this->saas->auditar($superAdminId, 'aprovisionar_empresa', $clienteId, [
            'base' => $base, 'sentencias' => count($sentencias), 'segundos' => $segundos,
        ]);
        return ['cliente_id' => $clienteId, 'clave_temporal' => $clave, 'sentencias' => count($sentencias), 'segundos' => $segundos];
    }

    /**
     * Registra una empresa cuya base ya existe (no crea ni borra nada).
     *
     * @param array{nombre_empresa:string, dominio_o_slug:string, correo_admin_cliente:string, nombre_db_fisica:string} $datos
     * @throws ValidationException
     */
    public function vincularExistente(array $datos, int $superAdminId): int
    {
        $base = strtolower(trim($datos['nombre_db_fisica']));
        $correo = mb_strtolower(trim($datos['correo_admin_cliente']));
        if (preg_match(Saas::PATRON_DB, $base)) {
            if (!$this->baseExiste($base)) {
                throw new ValidationException(['nombre_db_fisica' => 'La base ' . $base . ' no existe en el servidor. Desmarca "La base ya existe" para crearla.']);
            }
            try {
                $stmt = Database::conectarA($base)->prepare('SELECT activo FROM usuarios WHERE email = :e AND eliminado_en IS NULL');
                $stmt->execute(['e' => $correo]);
                $activo = $stmt->fetchColumn();
            } catch (PDOException) {
                throw new ValidationException(['nombre_db_fisica' => 'La base ' . $base . ' no tiene el esquema de ADARA (falta la tabla usuarios).']);
            }
            if ((int) $activo !== 1) {
                throw new ValidationException(['correo_admin_cliente' => 'Ese correo no es un usuario activo en ' . $base . '.']);
            }
        }
        $id = $this->saas->registrarCliente($datos, $superAdminId, 'activa');
        $this->saas->auditar($superAdminId, 'vincular_empresa_existente', $id, ['base' => $base]);
        return $id;
    }

    /** @return list<string> */
    private function leerEsquema(): array
    {
        $sql = is_file(self::ESQUEMA) ? file_get_contents(self::ESQUEMA) : false;
        if ($sql === false || $sql === '') {
            throw new AprovisionamientoException('Falta sql/adara_esquema_limpio.sql. Genéralo con bin/generar_esquema_limpio.php.');
        }
        return SqlScript::sentencias($sql);
    }

    private function verificarEsquema(PDO $pdo): void
    {
        $faltan = [];
        foreach (['usuarios', 'auditoria', 'empresa_configuracion', 'almacenes', 'cxc_facturas', 'api_idempotencia'] as $tabla) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $stmt->execute(['t' => $tabla]);
            if ((int) $stmt->fetchColumn() !== 1) {
                $faltan[] = $tabla;
            }
        }
        if ($faltan !== []) {
            throw new AprovisionamientoException('El esquema quedó incompleto; faltan: ' . implode(', ', $faltan) . '.');
        }
    }

    private function revertir(int $clienteId, string $base, bool $creada, int $superAdminId, string $paso, Throwable $causa): void
    {
        error_log(sprintf('[ADARA][SaaS] Aprovisionamiento de %s falló al %s: %s', $base, $paso, $causa));
        $borrada = false;
        if ($creada) {
            try {
                $this->master->exec('DROP DATABASE ' . $this->ident($base));
                $borrada = true;
            } catch (Throwable $e) {
                error_log('[ADARA][SaaS] ROLLBACK INCOMPLETO: no se pudo borrar ' . $base . ': ' . $e->getMessage());
            }
        }
        $this->saas->eliminarClienteNoActivado($clienteId);
        $this->saas->auditar($superAdminId, 'aprovisionamiento_fallido', $clienteId, [
            'base' => $base,
            'paso' => $paso,
            'base_creada' => $creada,
            'base_borrada' => $borrada,
            'error' => mb_substr($causa->getMessage(), 0, 300),
        ]);
    }

    private function baseExiste(string $base): bool
    {
        try {
            $stmt = $this->master->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :b');
            $stmt->execute(['b' => $base]);
            if ((int) $stmt->fetchColumn() > 0) {
                return true;
            }
        } catch (Throwable) {
            // El usuario de la base maestra en un hosting compartido no siempre lista los demás esquemas.
        }
        try {
            Database::conectarA($base)->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Identificador entre backticks; el nombre ya cumple Saas::PATRON_DB (y el CHECK de clientes_saas). */
    private function ident(string $base): string
    {
        if (!preg_match(Saas::PATRON_DB, $base) || Saas::baseReservada($base)) {
            throw new LogicException('Nombre de base inválido.');
        }
        return '`' . $base . '`';
    }
}
