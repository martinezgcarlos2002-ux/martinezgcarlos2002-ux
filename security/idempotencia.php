<?php
declare(strict_types=1);

/**
 * Idempotencia de escrituras (cabecera X-Idempotency-Key, enviada por assets/js/offlineSync.js).
 *
 * Solo actúa en rutas POST con 'idempotente' => true en config/routes.php. Requisitos de esas rutas:
 *   - Responder siempre con json_response() (aquí se confirma o revierte la transacción).
 *   - Usar Database::transaction() y nunca beginTransaction()/commit() directos: la operación se une a
 *     la transacción abierta por iniciar(), que ya contiene la reserva de la clave.
 *
 * Garantías:
 *   - Atomicidad: la fila de api_idempotencia y la operación de negocio se confirman en el mismo COMMIT.
 *     Si PHP muere a mitad, MySQL revierte ambas y el reintento ejecuta de cero sin duplicar.
 *   - Concurrencia: un duplicado simultáneo choca con el bloqueo de la PK, espera al primero y, cuando
 *     este confirma, recibe la respuesta guardada. Si el primero revirtió, el duplicado ejecuta.
 *   - Solo se guardan respuestas 2xx. Un 4xx/5xx revierte todo y deja la clave libre.
 *   - La clave está ligada a usuario, endpoint y huella del cuerpo: reutilizarla con otro payload,
 *     otra ruta u otro usuario devuelve 422 y no revela la respuesta original.
 */
final class Idempotencia
{
    private const PATRON_CLAVE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const RETENCION_DIAS = 30;
    private const MYSQL_DUPLICADO = 1062;
    private const MYSQL_ESPERA_BLOQUEO = 1205;
    private const MYSQL_DEADLOCK = 1213;

    /** @var array{clave:string, usuario:int, endpoint:string, huella:string}|null */
    private static ?array $activa = null;
    private static bool $shutdownRegistrado = false;

    /**
     * Llamar después de autenticación, CSRF y RBAC, justo antes del controlador.
     * Si la clave ya fue procesada, responde con lo guardado y termina la petición.
     */
    public static function iniciar(string $endpoint, int $usuarioId): void
    {
        $raw = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $clave = strtolower(trim($raw));
        if (!preg_match(self::PATRON_CLAVE, $clave)) {
            json_response(400, [
                'ok' => false,
                'codigo' => 'idempotencia_clave_invalida',
                'errores' => ['general' => 'La cabecera X-Idempotency-Key debe ser un UUID.'],
            ]);
        }
        $huella = self::huella($endpoint);

        if (random_int(1, 200) === 1) {
            self::purgar();
        }

        $db = get_db();
        $db->beginTransaction();
        try {
            // Valores provisionales: nadie más los ve hasta el COMMIT, y para entonces ya son los reales.
            $db->prepare(
                'INSERT INTO api_idempotencia (clave, usuario_id, endpoint, huella_peticion, codigo_http, respuesta_json)
                 VALUES (:clave, :usuario, :endpoint, :huella, 202, \'{}\')'
            )->execute([
                'clave' => $clave,
                'usuario' => $usuarioId,
                'endpoint' => $endpoint,
                'huella' => $huella,
            ]);
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $codigo = (int) ($e->errorInfo[1] ?? 0);
            if ($codigo === self::MYSQL_DUPLICADO) {
                self::repetir($clave, $usuarioId, $endpoint, $huella);
            }
            if ($codigo === self::MYSQL_ESPERA_BLOQUEO || $codigo === self::MYSQL_DEADLOCK) {
                header('Retry-After: 5');
                json_response(503, [
                    'ok' => false,
                    'codigo' => 'idempotencia_ocupada',
                    'errores' => ['general' => 'La misma operación se está procesando. Se reintentará en unos segundos.'],
                ]);
            }
            throw $e;
        }

        self::$activa = ['clave' => $clave, 'usuario' => $usuarioId, 'endpoint' => $endpoint, 'huella' => $huella];
        if (!self::$shutdownRegistrado) {
            self::$shutdownRegistrado = true;
            register_shutdown_function([self::class, 'alTerminar']);
        }
    }

    /**
     * Gancho de json_response(): confirma (2xx) o revierte (resto) antes de enviar la respuesta.
     * Si el COMMIT falla, la respuesta se convierte en 500 para no anunciar un éxito que no se guardó.
     *
     * @return array{0:int, 1:string} [status, cuerpo] definitivos
     */
    public static function alResponder(int $status, string $cuerpo): array
    {
        $ctx = self::$activa;
        if ($ctx === null) {
            return [$status, $cuerpo];
        }
        self::$activa = null;
        $db = get_db();

        if ($status < 200 || $status >= 300) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return [$status, $cuerpo];
        }

        try {
            if (!$db->inTransaction()) {
                throw new LogicException('La ruta ' . $ctx['endpoint'] . ' cerró la transacción de idempotencia por su cuenta.');
            }
            $db->prepare(
                'UPDATE api_idempotencia SET codigo_http = :codigo, respuesta_json = :respuesta WHERE clave = :clave'
            )->execute(['codigo' => $status, 'respuesta' => $cuerpo, 'clave' => $ctx['clave']]);

            if (($_SERVER['HTTP_X_ADARA_OFFLINE_REPLAY'] ?? '') === '1') {
                audit_log('sync', 'offline_replay', [
                    'endpoint' => $ctx['endpoint'],
                    'clave' => $ctx['clave'],
                    'huella' => $ctx['huella'],
                    'capturado_en' => self::fechaCliente($_SERVER['HTTP_X_ADARA_CAPTURADO_EN'] ?? ''),
                ], 'api_idempotencia', null, $ctx['usuario']);
            }
            $db->commit();
            return [$status, $cuerpo];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[ADARA][idempotencia] ' . $e);
            return [500, json_encode([
                'ok' => false,
                'errores' => ['general' => 'Error interno al confirmar la operación. No se aplicaron cambios.'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        }
    }

    /** Red de seguridad: la ruta terminó sin json_response() (excepción no capturada, exit, redirect). */
    public static function alTerminar(): void
    {
        if (self::$activa === null) {
            return;
        }
        $endpoint = self::$activa['endpoint'];
        self::$activa = null;
        try {
            $db = get_db();
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Throwable) {
            // La conexión ya no existe: MySQL revierte la transacción abierta al cerrarla.
        }
        error_log('[ADARA][idempotencia] ' . $endpoint . ' terminó sin json_response(); se revirtió la operación.');
    }

    public static function purgar(): void
    {
        try {
            get_db()->exec(
                'DELETE FROM api_idempotencia WHERE creado_en < NOW() - INTERVAL ' . self::RETENCION_DIAS . ' DAY LIMIT 1000'
            );
        } catch (Throwable $e) {
            error_log('[ADARA][idempotencia] purga ' . $e->getMessage());
        }
    }

    private static function repetir(string $clave, int $usuarioId, string $endpoint, string $huella): never
    {
        $stmt = get_db()->prepare(
            'SELECT usuario_id, endpoint, huella_peticion, codigo_http, respuesta_json
             FROM api_idempotencia WHERE clave = :clave'
        );
        $stmt->execute(['clave' => $clave]);
        $row = $stmt->fetch();

        if ($row === false) {
            // Purgada entre el INSERT y el SELECT: el cliente puede reintentar con normalidad.
            header('Retry-After: 2');
            json_response(503, ['ok' => false, 'codigo' => 'idempotencia_ocupada', 'errores' => ['general' => 'Reintenta en unos segundos.']]);
        }
        $mismaPeticion = (int) $row['usuario_id'] === $usuarioId
            && hash_equals((string) $row['endpoint'], $endpoint)
            && hash_equals((string) $row['huella_peticion'], $huella);
        if (!$mismaPeticion) {
            audit_log('seguridad', 'idempotencia_conflicto', ['endpoint' => $endpoint, 'clave' => $clave]);
            json_response(422, [
                'ok' => false,
                'codigo' => 'idempotencia_conflicto',
                'errores' => ['general' => 'Esta operación ya se registró con otros datos. No se aplicaron cambios.'],
            ]);
        }

        http_response_code((int) $row['codigo_http']);
        header('Content-Type: application/json; charset=UTF-8');
        header('Idempotent-Replayed: true');
        echo (string) $row['respuesta_json'];
        exit;
    }

    /** SHA-256 del cuerpo normalizado: método, ruta, campos POST (sin _csrf) y contenido de los archivos. */
    private static function huella(string $endpoint): string
    {
        $campos = $_POST;
        unset($campos['_csrf']);
        self::ordenar($campos);

        $archivos = [];
        foreach ($_FILES as $nombre => $f) {
            $tmp = is_array($f) ? ($f['tmp_name'] ?? null) : null;
            if (is_string($tmp) && $tmp !== '' && is_uploaded_file($tmp)) {
                $archivos[$nombre] = hash_file('sha256', $tmp);
            }
        }
        ksort($archivos);

        return hash('sha256', json_encode(
            ['POST', $endpoint, $campos, $archivos],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    /** @param array<mixed> $datos */
    private static function ordenar(array &$datos): void
    {
        ksort($datos);
        foreach ($datos as &$v) {
            if (is_array($v)) {
                self::ordenar($v);
            }
        }
    }

    /** X-Adara-Capturado-En lo escribe el cliente: solo se guarda si es una fecha ISO bien formada. */
    private static function fechaCliente(mixed $raw): ?string
    {
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $raw)) {
            return null;
        }
        return $raw;
    }
}
