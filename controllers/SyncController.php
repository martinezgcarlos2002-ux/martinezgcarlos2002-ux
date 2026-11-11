<?php
declare(strict_types=1);

/**
 * Recibe la cola de LocalStorage cuando el dispositivo recupera red.
 * Cada operación entra en su propia transacción y en api_idempotencia, así un
 * reintento no duplica la recepción. El modo soporte no se reinterpreta: se usa
 * la sesión que está reenviando, y el id del maestro viaja en el contexto de auditoría.
 */
final class SyncController
{
    private const MAX_OPERACIONES = 50;
    private const PATRON_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** tipo de la cola => módulo y acción que ya usa el RBAC */
    private const PERMISOS = [
        'recepcion_leche' => ['productores', 'crear'],
        'gas'             => ['gas', 'crear'],
    ];

    public function recibir(): void
    {
        $usuario = current_user();
        $actor = [
            'usuario_id' => (int) $usuario['id'],
            'soporte_super_admin_id' => Auth::enSoporte() ? Auth::getAuditUserId() : null,
        ];

        $payload = $this->payload();
        $ops = $payload['operaciones'];
        if (count($ops) > self::MAX_OPERACIONES) {
            json_response(422, ['ok' => false, 'errores' => ['operaciones' => 'Máximo ' . self::MAX_OPERACIONES . ' operaciones por envío.']]);
        }

        $aplicadas = [];
        $rechazadas = [];
        $vistas = [];
        foreach ($ops as $i => $op) {
            if (!is_array($op)) {
                $rechazadas[] = ['indice' => $i, 'id' => null, 'codigo' => 'invalida', 'mensaje' => 'Operación mal formada.'];
                continue;
            }
            $id = strtolower(trim((string) ($op['id'] ?? '')));
            if (isset($vistas[$id])) {
                $rechazadas[] = ['indice' => $i, 'id' => $id, 'codigo' => 'duplicada', 'mensaje' => 'Ese id ya venía en este envío.'];
                continue;
            }
            $vistas[$id] = true;
            try {
                $aplicadas[] = $this->una($id, $op, $actor);
            } catch (ValidationException $e) {
                $rechazadas[] = [
                    'indice' => $i,
                    'id' => $id !== '' ? $id : null,
                    'codigo' => 'validacion',
                    'mensaje' => implode(' ', $e->errors),
                ];
            } catch (RuntimeException $e) {
                $rechazadas[] = [
                    'indice' => $i,
                    'id' => $id !== '' ? $id : null,
                    'codigo' => 'no_implementado',
                    'mensaje' => $e->getMessage(),
                ];
            } catch (Throwable $e) {
                error_log('[ADARA][sync] ' . $e);
                $rechazadas[] = [
                    'indice' => $i,
                    'id' => $id !== '' ? $id : null,
                    'codigo' => 'error',
                    'mensaje' => 'No se pudo guardar. La operación no quedó aplicada.',
                ];
            }
        }

        json_response(200, [
            'ok' => $rechazadas === [],
            'aplicadas' => $aplicadas,
            'rechazadas' => $rechazadas,
        ]);
    }

    /**
     * @param array<string, mixed> $op
     * @param array{usuario_id:int, soporte_super_admin_id:?int} $actor
     * @return array{id:string, tipo:string, replay:bool, resultado:array<string, mixed>}
     */
    private function una(string $id, array $op, array $actor): array
    {
        if (!preg_match(self::PATRON_UUID, $id)) {
            throw new ValidationException(['id' => 'El id de la operación debe ser un UUID.']);
        }
        $tipo = (string) ($op['tipo'] ?? '');
        if (!isset(self::PERMISOS[$tipo])) {
            throw new ValidationException(['tipo' => 'Tipo de operación no admitido.']);
        }
        [$modulo, $accion] = self::PERMISOS[$tipo];
        if (!has_permiso(current_user(), $modulo, $accion)) {
            throw new ValidationException(['tipo' => 'No tienes permiso para sincronizar ' . $tipo . '.']);
        }
        $datos = $op['datos'] ?? null;
        if (!is_array($datos)) {
            throw new ValidationException(['datos' => 'Cada operación trae un objeto datos.']);
        }
        $capturado = (string) ($op['capturado_en'] ?? '');
        if ($capturado !== '' && strtotime($capturado) === false) {
            throw new ValidationException(['capturado_en' => 'Fecha de captura inválida.']);
        }
        $huella = hash('sha256', $tipo . '|' . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return Database::transaction(function () use ($id, $tipo, $datos, $capturado, $huella, $actor): array {
            $previa = $this->buscarClave($id);
            if ($previa !== null) {
                if ((int) $previa['usuario_id'] !== $actor['usuario_id'] || $previa['endpoint'] !== 'sync:' . $tipo || $previa['huella_peticion'] !== $huella) {
                    throw new ValidationException(['id' => 'Esa operación ya se usó con otro usuario o con otros datos.']);
                }
                $guardada = json_decode((string) $previa['respuesta_json'], true, 8, JSON_THROW_ON_ERROR);
                return ['id' => $id, 'tipo' => $tipo, 'replay' => true, 'resultado' => is_array($guardada) ? $guardada : []];
            }

            $resultado = $this->aplicar($tipo, $datos, $actor, $capturado);
            $this->guardarClave($id, $actor['usuario_id'], $tipo, $huella, $resultado);
            return ['id' => $id, 'tipo' => $tipo, 'replay' => false, 'resultado' => $resultado];
        });
    }

    /**
     * @param array<string, mixed> $datos
     * @param array{usuario_id:int, soporte_super_admin_id:?int} $actor
     * @return array<string, mixed>
     */
    private function aplicar(string $tipo, array $datos, array $actor, string $capturado): array
    {
        return match ($tipo) {
            'recepcion_leche' => $this->recepcionLeche($datos, $actor, $capturado),
            'gas' => $this->gas($datos, $actor, $capturado),
            default => throw new ValidationException(['tipo' => 'Tipo de operación no admitido.']),
        };
    }

    /**
     * Enganche de Recepción de Leche. Mientras no llame al modelo, la transacción se revierte
     * y la clave queda libre para cuando el módulo esté conectado.
     *
     * @param array<string, mixed> $datos
     * @param array{usuario_id:int, soporte_super_admin_id:?int} $actor
     * @return array<string, mixed>
     */
    private function recepcionLeche(array $datos, array $actor, string $capturado): array
    {
        unset($datos, $actor, $capturado);
        throw new RuntimeException('Recepción de leche: falta conectar el modelo. La cola puede reintentar.');
    }

    /**
     * @param array<string, mixed> $datos
     * @param array{usuario_id:int, soporte_super_admin_id:?int} $actor
     * @return array<string, mixed>
     */
    private function gas(array $datos, array $actor, string $capturado): array
    {
        unset($datos, $actor, $capturado);
        throw new RuntimeException('Gestión de gas: falta conectar el modelo. La cola puede reintentar.');
    }

    /** @return array<string, mixed>|null */
    private function buscarClave(string $id): ?array
    {
        $stmt = get_db()->prepare(
            'SELECT usuario_id, endpoint, huella_peticion, respuesta_json
             FROM api_idempotencia WHERE clave = :clave FOR UPDATE'
        );
        $stmt->execute(['clave' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $resultado */
    private function guardarClave(int|string $id, int $usuarioId, string $tipo, string $huella, array $resultado): void
    {
        get_db()->prepare(
            'INSERT INTO api_idempotencia (clave, usuario_id, endpoint, huella_peticion, codigo_http, respuesta_json)
             VALUES (:clave, :usuario, :endpoint, :huella, 200, :respuesta)'
        )->execute([
            'clave' => $id,
            'usuario' => $usuarioId,
            'endpoint' => 'sync:' . $tipo,
            'huella' => $huella,
            'respuesta' => json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array{operaciones:list<mixed>} */
    private function payload(): array
    {
        // index.php ya leyó php://input y dejó el arreglo en $_POST['operaciones'] como JSON.
        $raw = $_POST['operaciones'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            $body = file_get_contents('php://input');
            if (is_string($body) && trim($body) !== '') {
                try {
                    $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    json_response(422, ['ok' => false, 'errores' => ['general' => 'JSON inválido.']]);
                }
                $raw = is_array($data) ? json_encode($data['operaciones'] ?? null) : '';
            }
        }
        if (!is_string($raw) || trim($raw) === '') {
            json_response(422, ['ok' => false, 'errores' => ['operaciones' => 'Se espera {"operaciones": [...]}.']]);
        }
        try {
            $ops = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            json_response(422, ['ok' => false, 'errores' => ['operaciones' => 'El listado de operaciones no es JSON válido.']]);
        }
        if (!is_array($ops) || !array_is_list($ops)) {
            json_response(422, ['ok' => false, 'errores' => ['operaciones' => 'Se espera {"operaciones": [...]}.']]);
        }
        return ['operaciones' => $ops];
    }
}
