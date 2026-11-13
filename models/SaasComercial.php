<?php
declare(strict_types=1);

/** Suscripción, aceptación de términos y tickets. Vive en la base maestra. */
final class SaasComercial
{
    public const TIPOS_TICKET = [
        'falla_critica' => 'Falla crítica',
        'asistencia'    => 'Asistencia',
        'sugerencia'    => 'Sugerencia',
    ];

    public const ESTADOS_TICKET = [
        'nuevo'       => 'Nuevo',
        'pendiente'   => 'Pendiente',
        'en_atencion' => 'En atención',
        'cerrado'     => 'Cerrado',
    ];

    public const MODOS_PAGO = [
        'efectivo'      => 'Efectivo',
        'transferencia' => 'Transferencia',
        'pago_movil'    => 'Pago móvil',
        'zelle'         => 'Zelle',
        'binance'       => 'Binance',
        'otros'         => 'Otros',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** Sin la migración 008 no se bloquea el ERP: se trata como ya aceptado. */
    public function contratoAceptado(int $clienteId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT contrato_aceptado, fecha_aceptacion_contrato
                 FROM clientes_saas WHERE id = :id'
            );
            $stmt->execute(['id' => $clienteId]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1054) {
                return true;
            }
            throw $e;
        }
        $row = $stmt->fetch();
        if ($row === false) {
            return true;
        }
        return (int) $row['contrato_aceptado'] === 1 || $row['fecha_aceptacion_contrato'] !== null;
    }

    /** @return array{aceptado:bool, fecha:?string} */
    public function contrato(int $clienteId): array
    {
        $stmt = $this->db->prepare(
            'SELECT contrato_aceptado, fecha_aceptacion_contrato
             FROM clientes_saas WHERE id = :id'
        );
        $stmt->execute(['id' => $clienteId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['aceptado' => false, 'fecha' => null];
        }
        $fecha = $row['fecha_aceptacion_contrato'];
        return [
            'aceptado' => (int) $row['contrato_aceptado'] === 1 || $fecha !== null,
            'fecha'    => is_string($fecha) && $fecha !== '' ? $fecha : null,
        ];
    }

    public function aceptarContrato(int $clienteId): void
    {
        $this->db->prepare(
            'UPDATE clientes_saas
             SET contrato_aceptado = 1,
                 fecha_aceptacion_contrato = COALESCE(fecha_aceptacion_contrato, NOW())
             WHERE id = :id'
        )->execute(['id' => $clienteId]);
    }

    /**
     * Un renglón por empresa. La suscripción vigente es el último pago registrado.
     *
     * @return list<array<string, mixed>>
     */
    public function panelSuscripciones(): array
    {
        $sql = 'SELECT c.id, c.nombre_empresa, c.estado_licencia, c.estado_suscripcion, c.saldo_a_favor,
                       c.contrato_aceptado, c.fecha_aceptacion_contrato,
                       s.tarifa_usd, s.monto_pagado, s.dia_inicio, s.dia_corte, s.modo_pago, s.referencia, s.fecha_pago, s.tasa_bcv_dia
                FROM clientes_saas c
                LEFT JOIN saas_suscripciones s ON s.id = (
                    SELECT s2.id FROM saas_suscripciones s2
                    WHERE s2.id_cliente = c.id
                    ORDER BY s2.id DESC
                    LIMIT 1
                )
                ORDER BY c.nombre_empresa';
        $filas = [];
        foreach ($this->db->query($sql)->fetchAll() as $row) {
            $corte = $row['dia_corte'];
            $dias = is_string($corte) && $corte !== '' ? self::diasRestantes($corte) : null;
            $row['dias'] = $dias;
            $row['color'] = self::colorSuscripcion($dias);
            $filas[] = $row;
        }
        return $filas;
    }

    /** @return list<array<string, mixed>> */
    public function ultimosPagos(int $limite = 12): array
    {
        $limite = max(1, min(50, $limite));
        $sql = 'SELECT s.id, s.id_cliente, s.tarifa_usd, s.monto_pagado, s.dia_inicio, s.dia_corte, s.modo_pago,
                       s.referencia, s.descripcion_pago, s.fecha_pago, s.tasa_bcv_dia, s.creado_en, c.nombre_empresa, c.saldo_a_favor
                FROM saas_suscripciones s
                INNER JOIN clientes_saas c ON c.id = s.id_cliente
                ORDER BY s.id DESC
                LIMIT ' . $limite;
        return $this->db->query($sql)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function suscripcion(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, c.nombre_empresa, c.saldo_a_favor
             FROM saas_suscripciones s
             INNER JOIN clientes_saas c ON c.id = s.id_cliente
             WHERE s.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function saldoAFavor(int $clienteId): string
    {
        $stmt = $this->db->prepare('SELECT saldo_a_favor FROM clientes_saas WHERE id = :id');
        $stmt->execute(['id' => $clienteId]);
        $saldo = $stmt->fetchColumn();
        return $saldo === false ? '0.00' : number_format((float) $saldo, 2, '.', '');
    }

    public function estaSuspendido(int $clienteId): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT estado_suscripcion FROM clientes_saas WHERE id = :id');
            $stmt->execute(['id' => $clienteId]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1054) {
                return false;
            }
            throw $e;
        }
        $estado = $stmt->fetchColumn();
        return $estado === 'Suspendido';
    }

    public function cambiarEstadoSuscripcion(int $clienteId, string $estado): void
    {
        if (!in_array($estado, ['activa', 'Suspendido'], true)) {
            throw new ValidationException(['estado' => 'El estado de suscripción no es válido.']);
        }
        $stmt = $this->db->prepare('UPDATE clientes_saas SET estado_suscripcion = :estado WHERE id = :id');
        $stmt->execute(['estado' => $estado, 'id' => $clienteId]);
        if ($stmt->rowCount() === 0 && !$this->clienteExiste($clienteId)) {
            throw new ValidationException(['id' => 'La empresa no existe.']);
        }
    }

    /**
     * Registra un ciclo. Si el dinero recibido supera la tarifa, el exceso entra al saldo a favor.
     * Con "usar saldo", la tarifa se cubre primero con ese adelanto.
     *
     * @param array<string, string> $datos
     */
    public function registrarPago(int $idMaestro, array $datos): void
    {
        $pago = $this->datosPago($datos, true);
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->db->beginTransaction();
        }
        try {
            $saldo = $this->saldoBloqueado($pago['cliente']);
            $usar = ($datos['usar_saldo'] ?? '') === '1' ? min($saldo, $pago['tarifa']) : 0;
            if ($pago['monto_vacio']) {
                $pago['monto'] = $usar > 0 ? max(0, $pago['tarifa'] - $usar) : $pago['tarifa'];
            }
            if ($pago['monto'] + $usar < $pago['tarifa']) {
                throw new ValidationException([
                    'sus_monto' => 'El pago no cubre la tarifa. Puedes completarlo con el saldo a favor.',
                ]);
            }
            $nuevo = $saldo + $pago['monto'] - $pago['tarifa'];
            $this->fijarSaldo($pago['cliente'], $nuevo);
            $this->db->prepare(
                'INSERT INTO saas_suscripciones
                    (id_cliente, tarifa_usd, monto_pagado, dia_inicio, dia_corte, modo_pago, referencia, descripcion_pago, fecha_pago, tasa_bcv_dia, registrado_por)
                 VALUES (:cliente, :tarifa, :monto, :inicio, :corte, :modo, :referencia, :descripcion, :pago, :tasa, :maestro)'
            )->execute([
                'cliente'     => $pago['cliente'],
                'tarifa'      => self::desdeCentavos($pago['tarifa']),
                'monto'       => self::desdeCentavos($pago['monto']),
                'inicio'      => $pago['inicio'],
                'corte'       => $pago['corte'],
                'modo'        => $pago['modo'],
                'referencia'  => $pago['referencia'],
                'descripcion' => $pago['descripcion'],
                'pago'        => $pago['fecha'],
                'tasa'        => $pago['tasa'],
                'maestro'     => $idMaestro,
            ]);
            if ($propia) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, string> $datos */
    public function actualizarPago(int $id, array $datos): void
    {
        $actual = $this->suscripcion($id);
        if ($actual === null) {
            throw new ValidationException(['sus_cliente' => 'Ese pago no existe.']);
        }
        $pago = $this->datosPago($datos, false);
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->db->beginTransaction();
        }
        try {
            $saldo = $this->saldoBloqueado((int) $actual['id_cliente']);
            $antes = self::centavos((string) $actual['monto_pagado']) - self::centavos((string) $actual['tarifa_usd']);
            $despues = $pago['monto'] - $pago['tarifa'];
            $nuevo = $saldo + ($despues - $antes);
            if ($nuevo < 0) {
                throw new ValidationException([
                    'sus_monto' => 'Ese cambio dejaría el saldo a favor en negativo.',
                ]);
            }
            $this->fijarSaldo((int) $actual['id_cliente'], $nuevo);
            $this->db->prepare(
                'UPDATE saas_suscripciones
                 SET tarifa_usd = :tarifa, monto_pagado = :monto, dia_inicio = :inicio, dia_corte = :corte,
                     modo_pago = :modo, referencia = :referencia, descripcion_pago = :descripcion,
                     fecha_pago = :pago, tasa_bcv_dia = :tasa
                 WHERE id = :id'
            )->execute([
                'tarifa'      => self::desdeCentavos($pago['tarifa']),
                'monto'       => self::desdeCentavos($pago['monto']),
                'inicio'      => $pago['inicio'],
                'corte'       => $pago['corte'],
                'modo'        => $pago['modo'],
                'referencia'  => $pago['referencia'],
                'descripcion' => $pago['descripcion'],
                'pago'        => $pago['fecha'],
                'tasa'        => $pago['tasa'],
                'id'          => $id,
            ]);
            if ($propia) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function eliminarPago(int $id): void
    {
        $actual = $this->suscripcion($id);
        if ($actual === null) {
            throw new ValidationException(['sus_cliente' => 'Ese pago no existe.']);
        }
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->db->beginTransaction();
        }
        try {
            $saldo = $this->saldoBloqueado((int) $actual['id_cliente']);
            $efecto = self::centavos((string) $actual['monto_pagado']) - self::centavos((string) $actual['tarifa_usd']);
            $nuevo = $saldo - $efecto;
            if ($nuevo < 0) {
                throw new ValidationException([
                    'sus_cliente' => 'No se puede borrar: el excedente de ese pago ya se usó en el saldo a favor.',
                ]);
            }
            $this->fijarSaldo((int) $actual['id_cliente'], $nuevo);
            $this->db->prepare('DELETE FROM saas_suscripciones WHERE id = :id')->execute(['id' => $id]);
            if ($propia) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function cortesVigentes(): array
    {
        $sql = 'SELECT c.id, c.nombre_empresa, c.correo_admin_cliente, c.estado_suscripcion, s.dia_corte
                FROM clientes_saas c
                INNER JOIN saas_suscripciones s ON s.id = (
                    SELECT s2.id FROM saas_suscripciones s2
                    WHERE s2.id_cliente = c.id
                    ORDER BY s2.id DESC
                    LIMIT 1
                )';
        return $this->db->query($sql)->fetchAll();
    }

    public function notificarCorte(int $clienteId, string $mensaje, string $fecha): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO cliente_notificaciones (id_cliente, mensaje, leido, fecha)
             VALUES (:cliente, :mensaje, 0, :fecha)'
        );
        $stmt->execute([
            'cliente' => $clienteId,
            'mensaje' => mb_substr($mensaje, 0, 255),
            'fecha'   => $fecha,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function avisosPendientes(int $clienteId, int $limite = 8): array
    {
        $limite = max(1, min(30, $limite));
        $stmt = $this->db->prepare(
            'SELECT id, mensaje, fecha FROM cliente_notificaciones
             WHERE id_cliente = :cliente AND leido = 0
             ORDER BY fecha DESC, id DESC
             LIMIT ' . $limite
        );
        $stmt->execute(['cliente' => $clienteId]);
        return $stmt->fetchAll();
    }

    public function marcarAvisoLeido(int $clienteId, int $id): void
    {
        $this->db->prepare(
            'UPDATE cliente_notificaciones SET leido = 1 WHERE id = :id AND id_cliente = :cliente'
        )->execute(['id' => $id, 'cliente' => $clienteId]);
    }

    public function crearTicket(int $clienteId, int $usuarioId, string $asunto, string $tipo, string $descripcion): int
    {
        $errores = [];
        $asunto = trim($asunto);
        $descripcion = trim($descripcion);
        if (mb_strlen($asunto) < 4 || mb_strlen($asunto) > 140) {
            $errores['asunto'] = 'El asunto debe tener entre 4 y 140 caracteres.';
        }
        if (!isset(self::TIPOS_TICKET[$tipo])) {
            $errores['tipo_solicitud'] = 'Elige el tipo de solicitud.';
        }
        if (mb_strlen($descripcion) < 10 || mb_strlen($descripcion) > 2000) {
            $errores['descripcion'] = 'Describe el caso (entre 10 y 2000 caracteres).';
        }
        if ($errores !== []) {
            throw new ValidationException($errores);
        }
        $this->db->prepare(
            'INSERT INTO saas_tickets_soporte
                (id_cliente, id_usuario_cliente, asunto, tipo_solicitud, descripcion, estado)
             VALUES (:cliente, :usuario, :asunto, :tipo, :descripcion, \'nuevo\')'
        )->execute([
            'cliente'     => $clienteId,
            'usuario'     => $usuarioId,
            'asunto'      => $asunto,
            'tipo'        => $tipo,
            'descripcion' => $descripcion,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function contarAbiertos(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM saas_tickets_soporte WHERE estado IN ('nuevo', 'pendiente')"
        )->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function ticketsAbiertos(int $limite = 8): array
    {
        $limite = max(1, min(30, $limite));
        $sql = 'SELECT t.id, t.id_cliente, t.id_usuario_cliente, t.asunto, t.tipo_solicitud, t.estado, t.fecha_creacion,
                       c.nombre_empresa
                FROM saas_tickets_soporte t
                INNER JOIN clientes_saas c ON c.id = t.id_cliente
                WHERE t.estado IN (\'nuevo\', \'pendiente\')
                ORDER BY t.fecha_creacion DESC, t.id DESC
                LIMIT ' . $limite;
        return $this->db->query($sql)->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function tickets(int $limite = 80): array
    {
        $limite = max(1, min(200, $limite));
        $sql = 'SELECT t.id, t.id_cliente, t.id_usuario_cliente, t.asunto, t.tipo_solicitud, t.descripcion,
                       t.estado, t.fecha_creacion, c.nombre_empresa
                FROM saas_tickets_soporte t
                INNER JOIN clientes_saas c ON c.id = t.id_cliente
                ORDER BY t.fecha_creacion DESC, t.id DESC
                LIMIT ' . $limite;
        return $this->db->query($sql)->fetchAll();
    }

    public function cambiarEstadoTicket(int $id, string $estado): void
    {
        if (!isset(self::ESTADOS_TICKET[$estado])) {
            throw new ValidationException(['estado' => 'El estado no es válido.']);
        }
        $stmt = $this->db->prepare('UPDATE saas_tickets_soporte SET estado = :estado WHERE id = :id');
        $stmt->execute(['estado' => $estado, 'id' => $id]);
        if ($stmt->rowCount() === 0) {
            $existe = $this->db->prepare('SELECT id, estado FROM saas_tickets_soporte WHERE id = :id');
            $existe->execute(['id' => $id]);
            $row = $existe->fetch();
            if ($row === false) {
                throw new ValidationException(['estado' => 'Ese reporte no existe.']);
            }
        }
    }

    public static function diasRestantes(string $diaCorte): int
    {
        $corte = DateTimeImmutable::createFromFormat('!Y-m-d', $diaCorte);
        $hoy = new DateTimeImmutable('today');
        if ($corte === false) {
            return 0;
        }
        return (int) $hoy->diff($corte)->format('%r%a');
    }

    public static function colorSuscripcion(?int $dias): string
    {
        if ($dias === null) {
            return 'secondary';
        }
        if ($dias <= 0) {
            return 'danger';
        }
        if ($dias <= 7) {
            return 'warning';
        }
        return 'success';
    }

    public static function textoDias(?int $dias): string
    {
        if ($dias === null) {
            return 'Sin suscripción';
        }
        if ($dias > 0) {
            return $dias === 1 ? '1 día' : $dias . ' días';
        }
        if ($dias === 0) {
            return 'Vence hoy';
        }
        $atraso = abs($dias);
        return $atraso === 1 ? '1 día de atraso' : $atraso . ' días de atraso';
    }

    /**
     * @param array<string, string> $datos
     * @return array{cliente:int, tarifa:int, monto:int, monto_vacio:bool, inicio:string, corte:string, modo:string, referencia:?string, descripcion:?string, fecha:string, tasa:?string}
     */
    private function datosPago(array $datos, bool $exigirCliente): array
    {
        $errores = [];
        $clienteId = filter_var($datos['id_cliente'] ?? '', FILTER_VALIDATE_INT);
        if ($exigirCliente && ($clienteId === false || (int) $clienteId < 1 || !$this->clienteExiste((int) $clienteId))) {
            $errores['sus_cliente'] = 'Elige una empresa registrada.';
            $clienteId = 0;
        } else {
            $clienteId = (int) $clienteId;
        }

        $tarifa = self::centavos(str_replace(',', '.', trim($datos['tarifa_usd'] ?? '')));
        if ($tarifa === null || $tarifa <= 0) {
            $errores['sus_tarifa'] = 'La tarifa en USD tiene que ser mayor que cero, con hasta 2 decimales.';
            $tarifa = 0;
        }
        $montoRaw = str_replace(',', '.', trim($datos['monto_pagado'] ?? ''));
        $montoVacio = $montoRaw === '';
        if ($montoVacio && !$exigirCliente) {
            $errores['sus_monto'] = 'Indica el monto pagado.';
            $monto = 0;
        } elseif ($montoVacio) {
            $monto = 0;
        } else {
            $monto = self::centavos($montoRaw);
            if ($monto === null) {
                $errores['sus_monto'] = 'El monto pagado tiene que ser un número de hasta 2 decimales.';
                $monto = 0;
            }
        }

        $inicio = self::fecha($datos['dia_inicio'] ?? '', 'sus_inicio', 'El día de inicio', $errores);
        $corte = self::fecha($datos['dia_corte'] ?? '', 'sus_corte', 'El día de corte', $errores);
        $fechaPago = self::fecha($datos['fecha_pago'] ?? '', 'sus_pago', 'La fecha de pago', $errores);
        if ($inicio !== null && $corte !== null && $corte < $inicio) {
            $errores['sus_corte'] = 'El día de corte no puede ser anterior al inicio.';
        }

        $modo = $datos['modo_pago'] ?? '';
        if (!isset(self::MODOS_PAGO[$modo])) {
            $errores['sus_modo'] = 'El modo de pago no es válido.';
        }
        $referencia = trim($datos['referencia'] ?? '');
        $descripcion = trim($datos['descripcion_pago'] ?? '');
        if (mb_strlen($referencia) > 80) {
            $errores['sus_referencia'] = 'La referencia admite hasta 80 caracteres.';
        } elseif ($modo !== '' && !in_array($modo, ['efectivo', 'otros'], true) && $referencia === '') {
            $errores['sus_referencia'] = 'Indica la referencia del pago.';
        }
        if (mb_strlen($descripcion) > 500) {
            $errores['sus_descripcion'] = 'El detalle admite hasta 500 caracteres.';
        } elseif ($modo === 'otros' && $referencia === '' && $descripcion === '') {
            $errores['sus_descripcion'] = 'En Otros, anota la referencia o el detalle de la transacción.';
        }

        $tasaRaw = str_replace(',', '.', trim($datos['tasa_bcv_dia'] ?? ''));
        $tasa = null;
        if ($tasaRaw !== '') {
            if (!preg_match('/^\d{1,7}(\.\d{1,4})?$/', $tasaRaw) || (float) $tasaRaw <= 0) {
                $errores['sus_tasa'] = 'La tasa BCV tiene que ser mayor que cero, con hasta 4 decimales.';
            } else {
                $tasa = $tasaRaw;
            }
        }

        if ($errores !== [] || $inicio === null || $corte === null || $fechaPago === null) {
            throw new ValidationException($errores !== [] ? $errores : ['sus_inicio' => 'Revisa las fechas.']);
        }

        return [
            'cliente'     => $clienteId,
            'tarifa'      => $tarifa,
            'monto'       => $monto,
            'monto_vacio' => $montoVacio,
            'inicio'      => $inicio,
            'corte'       => $corte,
            'modo'        => $modo,
            'referencia'  => $referencia !== '' ? $referencia : null,
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'fecha'       => $fechaPago,
            'tasa'        => $tasa,
        ];
    }

    private function clienteExiste(int $clienteId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM clientes_saas WHERE id = :id');
        $stmt->execute(['id' => $clienteId]);
        return $stmt->fetch() !== false;
    }

    private function saldoBloqueado(int $clienteId): int
    {
        $stmt = $this->db->prepare('SELECT saldo_a_favor FROM clientes_saas WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $clienteId]);
        $saldo = $stmt->fetchColumn();
        if ($saldo === false) {
            throw new ValidationException(['sus_cliente' => 'La empresa no existe.']);
        }
        return self::centavos(number_format((float) $saldo, 2, '.', '')) ?? 0;
    }

    private function fijarSaldo(int $clienteId, int $centavos): void
    {
        $this->db->prepare('UPDATE clientes_saas SET saldo_a_favor = :saldo WHERE id = :id')
            ->execute(['saldo' => self::desdeCentavos($centavos), 'id' => $clienteId]);
    }

    private static function centavos(string $monto): ?int
    {
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $monto)) {
            return null;
        }
        [$entero, $decimal] = array_pad(explode('.', $monto, 2), 2, '0');
        return ((int) $entero * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private static function desdeCentavos(int $centavos): string
    {
        return intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param array<string, string> $errores */
    private static function fecha(string $valor, string $campo, string $etiqueta, array &$errores): ?string
    {
        $valor = trim($valor);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($dt === false || $dt->format('Y-m-d') !== $valor) {
            $errores[$campo] = $etiqueta . ' no es una fecha válida.';
            return null;
        }
        return $valor;
    }
}
