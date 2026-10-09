<?php
declare(strict_types=1);

/**
 * Cuentas por cobrar de facturas de talonario.
 *
 * Reglas:
 *   - Todo importe se opera en centavos enteros; a MySQL viaja como string con 2 decimales.
 *   - Cada operación que mueve saldos corre en una transacción (Database::transaction se une a una abierta).
 *   - Orden de bloqueo fijo: cliente (FOR UPDATE) → facturas por id ascendente (FOR UPDATE). Evita interbloqueos
 *     entre abonos, pagos y devoluciones concurrentes del mismo cliente.
 *   - Validaciones de negocio lanzan ValidationException (campo => mensaje); el controlador responde 422.
 *   - El estado de la factura es derivado (saldo y fechas); se recalcula tras cada escritura y en lote antes de leer.
 */
final class CxCService
{
    public const METODOS = [
        'efectivo'      => 'Efectivo',
        'punto'         => 'Punto',
        'transferencia' => 'Transferencia',
        'pago_movil'    => 'Pago Móvil',
        'saldo_favor'   => 'Saldo a favor',
        'otro'          => 'Otro',
    ];
    /** Métodos que puede elegir el usuario; 'saldo_favor' solo lo genera el sistema. */
    public const METODOS_MANUALES = ['efectivo', 'punto', 'transferencia', 'pago_movil', 'otro'];
    public const DESTINOS = ['origen', 'otra_factura', 'credito_pendiente'];
    public const DIAS_POR_VENCER = 3;
    private const MAX_RENGLONES = 200;
    /** DECIMAL(12,2) y DECIMAL(10,2) expresados en centésimas. */
    private const MAX_CENTAVOS = 999999999999;
    private const MAX_CANTIDAD_CENTESIMAS = 9999999999;
    private const INTENTOS_NUMERO = 3;

    /** Estado a partir de saldo y vencimiento. Las anuladas no se recalculan. CURDATE() usa -04:00. */
    private const SQL_ESTADO = "CASE
            WHEN estado = 'anulada' THEN 'anulada'
            WHEN saldo_pendiente <= 0 THEN 'pagada'
            WHEN CURDATE() > fecha_vencimiento THEN 'vencida'
            WHEN DATEDIFF(fecha_vencimiento, CURDATE()) <= " . self::DIAS_POR_VENCER . " THEN 'por_vencer'
            ELSE 'vigente' END";

    public function __construct(private PDO $db, private CorrelativoService $correlativo)
    {
    }

    // ----------------------------------------------------------------------
    // Clientes
    // ----------------------------------------------------------------------

    /**
     * @param array{nombre:string, documento:?string, telefono:?string, domicilio:?string} $data ya normalizado
     * @return int id del cliente
     */
    public function guardarCliente(array $data, ?int $id, int $userId): int
    {
        return Database::transaction(function () use ($data, $id, $userId): int {
            try {
                if ($id === null) {
                    $stmt = $this->db->prepare(
                        'INSERT INTO cxc_clientes (nombre, documento, telefono, domicilio, registrado_por)
                         VALUES (:nombre, :documento, :telefono, :domicilio, :usuario)'
                    );
                    $stmt->execute($data + ['usuario' => $userId]);
                    $id = (int) $this->db->lastInsertId();
                    audit_log('facturas', 'crear_cliente', $data, 'cxc_clientes', $id);
                    return $id;
                }
                $this->bloquearCliente($id);
                $stmt = $this->db->prepare(
                    'UPDATE cxc_clientes SET nombre = :nombre, documento = :documento, telefono = :telefono, domicilio = :domicilio
                     WHERE id = :id'
                );
                $stmt->execute($data + ['id' => $id]);
                audit_log('facturas', 'editar_cliente', $data, 'cxc_clientes', $id);
                return $id;
            } catch (PDOException $e) {
                if (duplicate_key($e) === 'uq_cxc_clientes_documento') {
                    throw new ValidationException(['documento' => 'Ya existe un cliente con ese documento.']);
                }
                throw $e;
            }
        });
    }

    /** Solo se elimina un cliente sin facturas, devoluciones ni saldo a favor: el historial de CxC no se borra. */
    public function eliminarCliente(int $id): void
    {
        Database::transaction(function () use ($id): void {
            $c = $this->bloquearCliente($id);
            if (Caja::aCentavos($c['saldo_favor']) > 0) {
                throw new ValidationException(['id' => 'El cliente tiene saldo a favor: aplícalo antes de eliminarlo.']);
            }
            $stmt = $this->db->prepare(
                'SELECT (SELECT COUNT(*) FROM cxc_facturas WHERE cliente_id = :a) + (SELECT COUNT(*) FROM cxc_devoluciones WHERE cliente_id = :b)'
            );
            $stmt->execute(['a' => $id, 'b' => $id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new ValidationException(['id' => 'El cliente tiene facturas o devoluciones registradas y no puede eliminarse.']);
            }
            $this->db->prepare('DELETE FROM cxc_clientes WHERE id = :id')->execute(['id' => $id]);
            audit_log('facturas', 'eliminar_cliente', ['nombre' => $c['nombre']], 'cxc_clientes', $id);
        });
    }

    // ----------------------------------------------------------------------
    // Facturas
    // ----------------------------------------------------------------------

    /**
     * @param array{cliente_id:int, numero:?string, es_aleatorio:bool, domicilio:?string, observaciones:?string,
     *              fecha_emision:string, fecha_vencimiento:string, monto_total:string|float|null} $data
     * @param list<array{cantidad:string|float, precio:string|float, descripcion:?string}> $detalles
     *        Con renglones, monto_total se ignora y el total es la suma de subtotales.
     * @return array{factura_id:int, numero:string, credito_aplicado:string, pago_id:?int}
     */
    public function registrarFactura(array $data, array $detalles, bool $usarSaldoFavor, int $userId): array
    {
        if ($data['fecha_vencimiento'] < $data['fecha_emision']) {
            throw new ValidationException(['vence' => 'El vencimiento no puede ser anterior a la emisión.']);
        }
        $renglones = $this->renglones($detalles);
        $totalC = $renglones !== []
            ? array_sum(array_column($renglones, 'subtotal_c'))
            : self::centavos($data['monto_total'] ?? null, 'monto');
        if ($totalC <= 0 || $totalC > self::MAX_CENTAVOS) {
            throw new ValidationException(['monto' => 'El monto total debe ser mayor que 0.']);
        }

        $numeroManual = null;
        if (!$data['es_aleatorio'] || ($data['numero'] ?? '') !== '') {
            $numeroManual = CorrelativoService::normalizar((string) ($data['numero'] ?? ''));
            if ($numeroManual === null) {
                throw new ValidationException(['numero' => 'Número de factura inválido (letras, números y guion, hasta 20).']);
            }
        }

        return Database::transaction(function () use ($data, $renglones, $totalC, $usarSaldoFavor, $userId, $numeroManual): array {
            $cliente = $this->bloquearCliente($data['cliente_id']);

            $insert = $this->db->prepare(
                'INSERT INTO cxc_facturas
                     (cliente_id, numero_factura, es_aleatorio, domicilio, observaciones, fecha_emision, fecha_vencimiento,
                      monto_total, total_pagado, total_devuelto, saldo_pendiente, registrado_por)
                 VALUES
                     (:cliente_id, :numero, :aleatorio, :domicilio, :observaciones, :emision, :vence,
                      :total, 0, 0, :saldo, :usuario)'
            );
            $numero = $numeroManual;
            for ($intento = 1; ; $intento++) {
                $numero ??= $this->correlativo->generarAleatorio();
                try {
                    $insert->execute([
                        'cliente_id'    => $cliente['id'],
                        'numero'        => $numero,
                        'aleatorio'     => $data['es_aleatorio'] ? 1 : 0,
                        'domicilio'     => self::nulo($data['domicilio'] ?? null) ?? self::nulo($cliente['domicilio']),
                        'observaciones' => self::nulo($data['observaciones'] ?? null),
                        'emision'       => $data['fecha_emision'],
                        'vence'         => $data['fecha_vencimiento'],
                        'total'         => Caja::desdeCentavos($totalC),
                        'saldo'         => Caja::desdeCentavos($totalC),
                        'usuario'       => $userId,
                    ]);
                    break;
                } catch (PDOException $e) {
                    if (duplicate_key($e) !== 'uq_cxc_facturas_numero') {
                        throw $e;
                    }
                    if ($numeroManual !== null || $intento >= self::INTENTOS_NUMERO) {
                        throw new ValidationException(['numero' => "La factura $numero ya está registrada."]);
                    }
                    $numero = null;
                }
            }
            $facturaId = (int) $this->db->lastInsertId();

            $det = $this->db->prepare(
                'INSERT INTO cxc_factura_detalles (factura_id, renglon, cantidad, precio_unitario, subtotal, descripcion)
                 VALUES (:factura, :renglon, :cantidad, :precio, :subtotal, :descripcion)'
            );
            foreach ($renglones as $i => $r) {
                $det->execute([
                    'factura'     => $facturaId,
                    'renglon'     => $i + 1,
                    'cantidad'    => $r['cantidad'],
                    'precio'      => $r['precio'],
                    'subtotal'    => Caja::desdeCentavos($r['subtotal_c']),
                    'descripcion' => $r['descripcion'],
                ]);
            }

            $creditoC = 0;
            $pagoId = null;
            $saldoFavorC = Caja::aCentavos($cliente['saldo_favor']);
            if ($usarSaldoFavor && $saldoFavorC > 0) {
                $creditoC = min($saldoFavorC, $totalC);
                $factura = $this->bloquearFactura($facturaId);
                $pagoId = $this->aplicarPago($factura, $creditoC, $data['fecha_emision'], 'saldo_favor', null, 'Saldo a favor aplicado', null, $userId);
                $this->moverSaldoFavor($cliente['id'], -$creditoC, 'aplicado_factura', $data['fecha_emision'], $userId, factura: $facturaId, pago: $pagoId);
                $this->consumirDevolucionesPendientesFifo((int) $cliente['id'], $creditoC);
            }
            $this->actualizarEstadoFactura($facturaId);

            audit_log('facturas', 'crear_factura', [
                'numero'           => $numero,
                'cliente_id'       => $cliente['id'],
                'total'            => Caja::desdeCentavos($totalC),
                'renglones'        => count($renglones),
                'credito_aplicado' => Caja::desdeCentavos($creditoC),
            ], 'cxc_facturas', $facturaId);

            return ['factura_id' => $facturaId, 'numero' => $numero, 'credito_aplicado' => Caja::desdeCentavos($creditoC), 'pago_id' => $pagoId];
        });
    }

    /**
     * Pago a una factura. No se admite pagar más que el saldo (el excedente se registra como abono a varias).
     * @return array{pago_id:int, factura_id:int}
     */
    public function registrarPagoIndividual(int $facturaId, string|float $monto, string $fecha, string $metodo, ?string $ref, ?string $notas, int $userId, ?string $tasaCambio = null, ?string $montoBs = null): array
    {
        $montoC = self::centavos($monto, 'monto');
        self::validarMetodo($metodo);
        [$tasaCambio, $montoBs] = self::normalizarTasa($tasaCambio, $montoBs, $montoC);

        return Database::transaction(function () use ($facturaId, $montoC, $fecha, $metodo, $ref, $notas, $userId, $tasaCambio, $montoBs): array {
            $clienteId = $this->clienteDeFactura($facturaId);
            $cliente = $this->bloquearCliente($clienteId);
            $factura = $this->bloquearFactura($facturaId);
            if ($factura['estado'] === 'anulada') {
                throw new ValidationException(['factura_id' => 'La factura está anulada.']);
            }
            $saldoC = Caja::aCentavos($factura['saldo_pendiente']);
            if ($saldoC <= 0) {
                throw new ValidationException(['monto' => 'La factura ' . $factura['numero_factura'] . ' ya está pagada.']);
            }
            if ($montoC > $saldoC) {
                throw new ValidationException(['monto' => sprintf('El pago (%s) supera el saldo de la factura (%s). Usa "Abono a varias" para repartir el excedente.',
                    num(Caja::desdeCentavos($montoC)), num(Caja::desdeCentavos($saldoC)))]);
            }
            if ($metodo === 'saldo_favor') {
                $favorC = Caja::aCentavos($cliente['saldo_favor']);
                if ($montoC > $favorC) {
                    throw new ValidationException(['monto' => sprintf(
                        'El cliente solo tiene $%s de saldo a favor.',
                        num(Caja::desdeCentavos($favorC))
                    )]);
                }
            }
            $pagoId = $this->aplicarPago($factura, $montoC, $fecha, $metodo, $ref, $notas, null, $userId, $tasaCambio, $montoBs);
            if ($metodo === 'saldo_favor') {
                $this->moverSaldoFavor($clienteId, -$montoC, 'aplicado_factura', $fecha, $userId, factura: $facturaId, pago: $pagoId);
                $this->consumirDevolucionesPendientesFifo($clienteId, $montoC);
            }
            $this->actualizarEstadoFactura($facturaId);
            audit_log('facturas', 'pago_factura', [
                'factura' => $factura['numero_factura'],
                'monto'   => Caja::desdeCentavos($montoC),
                'metodo'  => $metodo,
            ], 'cxc_pagos', $pagoId);
            return ['pago_id' => $pagoId, 'factura_id' => $facturaId, 'cliente_id' => $clienteId];
        });
    }

    /**
     * Abono FIFO: cancela desde la factura con vencimiento más antiguo (fecha_vencimiento ASC, id ASC).
     * Crea un pago por factura afectada (mismo lote). El remanente sobre la deuda total pasa a saldo_favor.
     *
     * @return array{lote:string, pagos:list<array{pago_id:int, factura_id:int, monto:string}>, excedente:string, saldo_favor:string}
     */
    public function abonarAVarias(int $clienteId, string|float $montoTotalAbono, string $fecha, string $metodo, ?string $ref, ?string $notas, int $userId, ?string $tasaCambio = null, ?string $montoBs = null): array
    {
        $restoC = self::centavos($montoTotalAbono, 'monto');
        self::validarMetodo($metodo);
        [$tasaCambio, $montoBs] = self::normalizarTasa($tasaCambio, $montoBs, $restoC);

        return Database::transaction(function () use ($clienteId, $restoC, $fecha, $metodo, $ref, $notas, $userId, $tasaCambio, $montoBs): array {
            $cliente = $this->bloquearCliente($clienteId);
            if ($metodo === 'saldo_favor') {
                $favorC = Caja::aCentavos($cliente['saldo_favor']);
                if ($restoC > $favorC) {
                    throw new ValidationException(['monto' => sprintf(
                        'El cliente solo tiene $%s de saldo a favor.',
                        num(Caja::desdeCentavos($favorC))
                    )]);
                }
            }
            $stmt = $this->db->prepare(
                'SELECT * FROM cxc_facturas
                 WHERE cliente_id = :cliente AND saldo_pendiente > 0
                 ORDER BY id ASC
                 FOR UPDATE'
            );
            $stmt->execute(['cliente' => $clienteId]);
            $facturas = $stmt->fetchAll();
            usort($facturas, static fn (array $a, array $b): int
                => [$a['fecha_vencimiento'], (int) $a['id']] <=> [$b['fecha_vencimiento'], (int) $b['id']]);

            $lote = bin2hex(random_bytes(8));
            $totalC = $restoC;
            $pagos = [];
            foreach ($facturas as $f) {
                if ($restoC <= 0) {
                    break;
                }
                $aplicaC = min($restoC, Caja::aCentavos($f['saldo_pendiente']));
                $parteBs = $tasaCambio !== null
                    ? number_format(round($aplicaC * (float) $tasaCambio / 100, 2), 2, '.', '')
                    : null;
                $pagoId = $this->aplicarPago($f, $aplicaC, $fecha, $metodo, $ref, $notas, $lote, $userId, $tasaCambio, $parteBs);
                if ($metodo === 'saldo_favor') {
                    $this->moverSaldoFavor($clienteId, -$aplicaC, 'aplicado_factura', $fecha, $userId, factura: (int) $f['id'], pago: $pagoId, lote: $lote);
                    $this->consumirDevolucionesPendientesFifo($clienteId, $aplicaC);
                }
                $this->actualizarEstadoFactura((int) $f['id']);
                $pagos[] = ['pago_id' => $pagoId, 'factura_id' => (int) $f['id'], 'monto' => Caja::desdeCentavos($aplicaC)];
                $restoC -= $aplicaC;
            }
            if ($pagos === [] && $metodo === 'saldo_favor') {
                throw new ValidationException(['cliente_id' => 'Este cliente no tiene facturas pendientes para aplicar el saldo a favor.']);
            }

            $saldoFavor = null;
            if ($restoC > 0 && $metodo !== 'saldo_favor') {
                $saldoFavor = $this->moverSaldoFavor($clienteId, $restoC, 'abono_excedente', $fecha, $userId, lote: $lote);
            }
            audit_log('facturas', 'abono_varias', [
                'cliente_id' => $clienteId,
                'monto'      => Caja::desdeCentavos($totalC),
                'metodo'     => $metodo,
                'lote'       => $lote,
                'facturas'   => array_column($pagos, 'monto', 'factura_id'),
                'excedente'  => Caja::desdeCentavos($restoC),
            ], 'cxc_pagos');

            return [
                'lote'        => $lote,
                'pagos'       => $pagos,
                'excedente'   => $metodo === 'saldo_favor' ? '0.00' : Caja::desdeCentavos($restoC),
                'saldo_favor' => $saldoFavor ?? (string) $this->bloquearCliente($clienteId)['saldo_favor'],
            ];
        });
    }

    /**
     * @param array{cliente_id:int, destino:string, factura_origen_id:?int, factura_aplicada_id:?int,
     *              monto:string|float|null, fecha:string, motivo:?string} $data
     * @param list<array{cantidad:string|float, precio:string|float, descripcion:?string}> $items con renglones el monto es su suma
     * @return array{devolucion_id:int, factura_aplicada_id:?int, saldo_favor:string}
     */
    public function registrarDevolucion(array $data, array $items, int $userId): array
    {
        if (!in_array($data['destino'], self::DESTINOS, true)) {
            throw new ValidationException(['destino' => 'Indica qué hacer con el dinero de la devolución.']);
        }
        $renglones = $this->renglones($items);
        $montoC = $renglones !== [] ? array_sum(array_column($renglones, 'subtotal_c')) : self::centavos($data['monto'] ?? null, 'monto');
        $origenId = $data['factura_origen_id'] ?? null;
        $destinoId = match ($data['destino']) {
            'origen'       => $origenId ?? throw new ValidationException(['factura_id' => 'Para descontar de la factura de origen, selecciónala.']),
            'otra_factura' => $data['factura_aplicada_id'] ?? throw new ValidationException(['factura_destino' => 'Selecciona la factura vigente a descontar.']),
            default        => null,
        };

        return Database::transaction(function () use ($data, $renglones, $montoC, $origenId, $destinoId, $userId): array {
            $cliente = $this->bloquearCliente($data['cliente_id']);

            $ids = array_values(array_unique(array_filter([$origenId, $destinoId])));
            sort($ids);
            $facturas = [];
            foreach ($ids as $id) {
                $f = $this->bloquearFactura($id);
                if ($f['estado'] === 'anulada') {
                    throw new ValidationException([$id === $origenId ? 'factura_id' : 'factura_destino' => 'La factura está anulada.']);
                }
                if ((int) $f['cliente_id'] !== (int) $cliente['id']) {
                    throw new ValidationException([$id === $origenId ? 'factura_id' : 'factura_destino' => 'La factura no pertenece a este cliente.']);
                }
                $facturas[$id] = $f;
            }
            if ($destinoId !== null) {
                $saldoC = Caja::aCentavos($facturas[$destinoId]['saldo_pendiente']);
                if ($montoC > $saldoC) {
                    $campo = $data['destino'] === 'origen' ? 'factura_id' : 'factura_destino';
                    throw new ValidationException([$campo => sprintf('La devolución (%s) supera el saldo de la factura %s (%s). Déjala como crédito pendiente.',
                        num(Caja::desdeCentavos($montoC)), $facturas[$destinoId]['numero_factura'], num(Caja::desdeCentavos($saldoC)))]);
                }
            }

            $stmt = $this->db->prepare(
                'INSERT INTO cxc_devoluciones (cliente_id, factura_origen_id, factura_aplicada_id, monto, fecha, motivo, destino, estado, registrado_por)
                 VALUES (:cliente, :origen, :aplicada, :monto, :fecha, :motivo, :destino, :estado, :usuario)'
            );
            $stmt->execute([
                'cliente'  => $cliente['id'],
                'origen'   => $origenId,
                'aplicada' => $destinoId,
                'monto'    => Caja::desdeCentavos($montoC),
                'fecha'    => $data['fecha'],
                'motivo'   => self::nulo($data['motivo'] ?? null),
                'destino'  => $data['destino'],
                'estado'   => $destinoId !== null ? 'aplicada' : 'pendiente',
                'usuario'  => $userId,
            ]);
            $devId = (int) $this->db->lastInsertId();

            $det = $this->db->prepare(
                'INSERT INTO cxc_devolucion_detalles (devolucion_id, renglon, cantidad, precio_unitario, subtotal, descripcion)
                 VALUES (:dev, :renglon, :cantidad, :precio, :subtotal, :descripcion)'
            );
            foreach ($renglones as $i => $r) {
                $det->execute([
                    'dev'         => $devId,
                    'renglon'     => $i + 1,
                    'cantidad'    => $r['cantidad'],
                    'precio'      => $r['precio'],
                    'subtotal'    => Caja::desdeCentavos($r['subtotal_c']),
                    'descripcion' => $r['descripcion'],
                ]);
            }

            if ($destinoId !== null) {
                $upd = $this->db->prepare(
                    'UPDATE cxc_facturas
                     SET total_devuelto = total_devuelto + :m1, saldo_pendiente = saldo_pendiente - :m2
                     WHERE id = :id'
                );
                $m = Caja::desdeCentavos($montoC);
                $upd->execute(['m1' => $m, 'm2' => $m, 'id' => $destinoId]);
                $this->actualizarEstadoFactura($destinoId);
                $saldoFavor = (string) $cliente['saldo_favor'];
            } else {
                $saldoFavor = $this->moverSaldoFavor((int) $cliente['id'], $montoC, 'devolucion', $data['fecha'], $userId, devolucion: $devId);
            }

            audit_log('facturas', 'devolucion', [
                'cliente_id' => $cliente['id'],
                'monto'      => Caja::desdeCentavos($montoC),
                'destino'    => $data['destino'],
                'origen'     => $origenId !== null ? $facturas[$origenId]['numero_factura'] : null,
                'aplicada'   => $destinoId !== null ? $facturas[$destinoId]['numero_factura'] : null,
            ], 'cxc_devoluciones', $devId);

            return ['devolucion_id' => $devId, 'factura_aplicada_id' => $destinoId, 'saldo_favor' => $saldoFavor];
        });
    }

    /**
     * Anula un pago (no lo borra). Restaura saldo de la factura; si fue saldo a favor, lo devuelve al cliente.
     * @return array{pago_id:int, factura_id:int, cliente_id:int}
     */
    public function anularPago(int $pagoId, int $userId): array
    {
        return Database::transaction(function () use ($pagoId, $userId): array {
            $prev = $this->db->prepare('SELECT factura_id, cliente_id FROM cxc_pagos WHERE id = :id');
            $prev->execute(['id' => $pagoId]);
            $ref = $prev->fetch();
            if ($ref === false) {
                throw new ValidationException(['id' => 'El pago no existe.']);
            }
            $this->bloquearCliente((int) $ref['cliente_id']);
            $factura = $this->bloquearFactura((int) $ref['factura_id']);
            if ($factura['estado'] === 'anulada') {
                throw new ValidationException(['id' => 'No se puede anular un pago de una factura anulada.']);
            }
            $pago = $this->bloquearPago($pagoId);
            if ($pago['estado'] === 'anulado') {
                throw new ValidationException(['id' => 'Ese pago ya está anulado.']);
            }
            $this->db->prepare("UPDATE cxc_pagos SET estado = 'anulado' WHERE id = :id")->execute(['id' => $pagoId]);
            $m = $pago['monto'];
            $this->db->prepare(
                'UPDATE cxc_facturas SET total_pagado = total_pagado - :m1, saldo_pendiente = saldo_pendiente + :m2 WHERE id = :id'
            )->execute(['m1' => $m, 'm2' => $m, 'id' => $factura['id']]);
            if ($pago['metodo'] === 'saldo_favor') {
                $this->moverSaldoFavor(
                    (int) $pago['cliente_id'],
                    Caja::aCentavos($m),
                    'reverso',
                    date('Y-m-d'),
                    $userId,
                    factura: (int) $factura['id'],
                    pago: $pagoId
                );
            }
            $this->actualizarEstadoFactura((int) $factura['id']);
            audit_log('facturas', 'anular_pago', [
                'factura' => $factura['numero_factura'],
                'monto'   => $m,
                'metodo'  => $pago['metodo'],
            ], 'cxc_pagos', $pagoId);
            return ['pago_id' => $pagoId, 'factura_id' => (int) $factura['id'], 'cliente_id' => (int) $pago['cliente_id']];
        });
    }

    /**
     * Anula una devolución. Si descontó de una factura, restaura su saldo; si era crédito, lo quita del cliente.
     * @return array{devolucion_id:int, factura_aplicada_id:?int, cliente_id:int}
     */
    public function anularDevolucion(int $devolucionId, int $userId): array
    {
        return Database::transaction(function () use ($devolucionId, $userId): array {
            $prev = $this->db->prepare('SELECT cliente_id, factura_origen_id, factura_aplicada_id FROM cxc_devoluciones WHERE id = :id');
            $prev->execute(['id' => $devolucionId]);
            $ref = $prev->fetch();
            if ($ref === false) {
                throw new ValidationException(['id' => 'La devolución no existe.']);
            }
            $this->bloquearCliente((int) $ref['cliente_id']);
            $ids = array_values(array_unique(array_filter([
                $ref['factura_origen_id'] !== null ? (int) $ref['factura_origen_id'] : null,
                $ref['factura_aplicada_id'] !== null ? (int) $ref['factura_aplicada_id'] : null,
            ])));
            sort($ids);
            foreach ($ids as $fid) {
                $this->bloquearFactura($fid);
            }
            $dev = $this->bloquearDevolucion($devolucionId);
            if ($dev['estado'] === 'anulada') {
                throw new ValidationException(['id' => 'Esa devolución ya está anulada.']);
            }
            if ($dev['destino'] === 'credito_pendiente' && $dev['estado'] === 'aplicada') {
                throw new ValidationException(['id' => 'Esta devolución ya se consumió como saldo a favor. Anula el pago que la aplicó.']);
            }
            $this->db->prepare("UPDATE cxc_devoluciones SET estado = 'anulada' WHERE id = :id")->execute(['id' => $devolucionId]);
            $aplicadaId = $dev['factura_aplicada_id'] !== null ? (int) $dev['factura_aplicada_id'] : null;
            if ($aplicadaId !== null) {
                $m = $dev['monto'];
                $this->db->prepare(
                    'UPDATE cxc_facturas SET total_devuelto = total_devuelto - :m1, saldo_pendiente = saldo_pendiente + :m2 WHERE id = :id'
                )->execute(['m1' => $m, 'm2' => $m, 'id' => $aplicadaId]);
                $this->actualizarEstadoFactura($aplicadaId);
            } else {
                $this->moverSaldoFavor(
                    (int) $dev['cliente_id'],
                    -Caja::aCentavos($dev['monto']),
                    'reverso',
                    date('Y-m-d'),
                    $userId,
                    devolucion: $devolucionId
                );
            }
            audit_log('facturas', 'anular_devolucion', [
                'monto'   => $dev['monto'],
                'destino' => $dev['destino'],
            ], 'cxc_devoluciones', $devolucionId);
            return [
                'devolucion_id'       => $devolucionId,
                'factura_aplicada_id' => $aplicadaId,
                'cliente_id'          => (int) $dev['cliente_id'],
            ];
        });
    }

    /**
     * Anula una factura sin pagos ni devoluciones aplicadas. Saldo a 0, estado anulada.
     * @return array{factura_id:int, cliente_id:int}
     */
    public function anularFactura(int $facturaId, int $userId): array
    {
        return Database::transaction(function () use ($facturaId, $userId): array {
            $clienteId = $this->clienteDeFactura($facturaId);
            $this->bloquearCliente($clienteId);
            $factura = $this->bloquearFactura($facturaId);
            if ($factura['estado'] === 'anulada') {
                throw new ValidationException(['id' => 'La factura ya está anulada.']);
            }
            $pagos = $this->db->prepare("SELECT COUNT(*) FROM cxc_pagos WHERE factura_id = :id AND estado = 'aplicado'");
            $pagos->execute(['id' => $facturaId]);
            if ((int) $pagos->fetchColumn() > 0) {
                throw new ValidationException(['id' => 'Anula primero los pagos de esta factura.']);
            }
            $devs = $this->db->prepare(
                "SELECT COUNT(*) FROM cxc_devoluciones
                 WHERE estado <> 'anulada' AND factura_aplicada_id = :id"
            );
            $devs->execute(['id' => $facturaId]);
            if ((int) $devs->fetchColumn() > 0) {
                throw new ValidationException(['id' => 'Anula primero las devoluciones aplicadas a esta factura.']);
            }
            $this->db->prepare(
                "UPDATE cxc_facturas SET saldo_pendiente = 0, estado = 'anulada' WHERE id = :id"
            )->execute(['id' => $facturaId]);
            audit_log('facturas', 'anular_factura', [
                'numero' => $factura['numero_factura'],
                'monto'  => $factura['monto_total'],
            ], 'cxc_facturas', $facturaId);
            return ['factura_id' => $facturaId, 'cliente_id' => $clienteId];
        });
    }

    /**
     * Renglones y pagos de una factura (incluye anulados para el historial).
     * @return array{factura:array<string, mixed>, renglones:list<array<string, mixed>>, pagos:list<array<string, mixed>>}
     */
    public function obtenerDetalleFactura(int $facturaId): array
    {
        $lista = $this->facturas([$facturaId]);
        if ($lista === []) {
            throw new ValidationException(['factura_id' => 'La factura no existe.']);
        }
        $renglones = $this->db->prepare(
            'SELECT id, renglon, cantidad, precio_unitario, subtotal, descripcion
             FROM cxc_factura_detalles WHERE factura_id = :id ORDER BY renglon'
        );
        $renglones->execute(['id' => $facturaId]);
        return [
            'factura'   => $lista[0],
            'renglones' => $renglones->fetchAll(),
            'pagos'     => $this->pagosDeFactura($facturaId),
        ];
    }

    /**
     * Cabecera de una devolución y renglones con el precio tomado de la factura de origen (si existe).
     * @return array{devolucion:array<string, mixed>, renglones:list<array<string, mixed>>}
     */
    public function obtenerDetalleDevolucion(int $devolucionId): array
    {
        $lista = $this->devoluciones([$devolucionId]);
        if ($lista === []) {
            throw new ValidationException(['id' => 'La devolución no existe.']);
        }
        $dev = $lista[0];
        $origenId = $dev['factura_origen_id'] !== null ? (int) $dev['factura_origen_id'] : 0;
        $stmt = $this->db->prepare(
            'SELECT d.id, d.renglon, d.cantidad, d.precio_unitario, d.subtotal, d.descripcion,
                    (
                        SELECT f.precio_unitario FROM cxc_factura_detalles f
                        WHERE f.factura_id = :origen
                          AND (f.renglon = d.renglon OR (f.descripcion IS NOT NULL AND f.descripcion = d.descripcion))
                        ORDER BY f.renglon = d.renglon DESC, f.renglon
                        LIMIT 1
                    ) AS precio_origen
             FROM cxc_devolucion_detalles d
             WHERE d.devolucion_id = :id
             ORDER BY d.renglon'
        );
        $stmt->execute(['id' => $devolucionId, 'origen' => $origenId]);
        return [
            'devolucion' => $dev,
            'renglones'  => $stmt->fetchAll(),
        ];
    }

    /**
     * Libro mayor cronológico del cliente (débito aumenta lo que debe).
     * @return list<array{fecha:string, descripcion:string, debito:string, credito:string, saldo:string}>
     */
    public function libroMayorCliente(int $clienteId): array
    {
        if ($this->cliente($clienteId) === null) {
            throw new ValidationException(['cliente_id' => 'El cliente no existe.']);
        }
        $eventos = [];
        foreach ($this->facturas() as $f) {
            if ((int) $f['cliente_id'] !== $clienteId) {
                continue;
            }
            $eventos[] = [
                'fecha' => (string) $f['fecha_emision'],
                'ord'   => (int) $f['id'],
                'desc'  => 'Factura ' . $f['numero_factura'],
                'deb'   => (string) $f['monto_total'],
                'cre'   => '0.00',
            ];
            if ($f['estado'] === 'anulada') {
                $eventos[] = [
                    'fecha' => (string) $f['fecha_emision'],
                    'ord'   => (int) $f['id'] + 500000,
                    'desc'  => 'Anulación factura ' . $f['numero_factura'],
                    'deb'   => '0.00',
                    'cre'   => (string) $f['monto_total'],
                ];
            }
        }
        foreach ($this->pagos() as $p) {
            if ((int) $p['cliente_id'] !== $clienteId) {
                continue;
            }
            $num = $this->numeroFactura((int) $p['factura_id']);
            $eventos[] = [
                'fecha' => (string) $p['fecha'],
                'ord'   => 1000000 + (int) $p['id'],
                'desc'  => 'Pago ' . (self::METODOS[$p['metodo']] ?? $p['metodo']) . ' · ' . $num,
                'deb'   => '0.00',
                'cre'   => (string) $p['monto'],
            ];
            if (($p['estado'] ?? 'aplicado') === 'anulado') {
                $eventos[] = [
                    'fecha' => (string) $p['fecha'],
                    'ord'   => 1000000 + (int) $p['id'] + 1,
                    'desc'  => 'Anulación pago · ' . $num,
                    'deb'   => (string) $p['monto'],
                    'cre'   => '0.00',
                ];
            }
        }
        foreach ($this->devoluciones() as $d) {
            if ((int) $d['cliente_id'] !== $clienteId) {
                continue;
            }
            $num = $d['factura_aplicada_id'] !== null
                ? $this->numeroFactura((int) $d['factura_aplicada_id'])
                : 'crédito a favor';
            $eventos[] = [
                'fecha' => (string) $d['fecha'],
                'ord'   => 2000000 + (int) $d['id'],
                'desc'  => 'Devolución · ' . $num,
                'deb'   => '0.00',
                'cre'   => (string) $d['monto'],
            ];
            if ($d['estado'] === 'anulada') {
                $eventos[] = [
                    'fecha' => (string) $d['fecha'],
                    'ord'   => 2000000 + (int) $d['id'] + 1,
                    'desc'  => 'Anulación devolución · ' . $num,
                    'deb'   => (string) $d['monto'],
                    'cre'   => '0.00',
                ];
            }
        }
        usort($eventos, static fn (array $a, array $b): int => [$a['fecha'], $a['ord']] <=> [$b['fecha'], $b['ord']]);
        $saldoC = 0;
        $filas = [];
        foreach ($eventos as $e) {
            $saldoC += Caja::aCentavos($e['deb']) - Caja::aCentavos($e['cre']);
            $filas[] = [
                'fecha'       => $e['fecha'],
                'descripcion' => $e['desc'],
                'debito'      => $e['deb'],
                'credito'     => $e['cre'],
                'saldo'       => Caja::desdeCentavos($saldoC),
            ];
        }
        return $filas;
    }

    /** Recalcula el estado de una factura. Devuelve el estado resultante. */
    public function actualizarEstadoFactura(int $facturaId): string
    {
        $this->db->prepare('UPDATE cxc_facturas SET estado = ' . self::SQL_ESTADO . ' WHERE id = :id')->execute(['id' => $facturaId]);
        $stmt = $this->db->prepare('SELECT estado FROM cxc_facturas WHERE id = :id');
        $stmt->execute(['id' => $facturaId]);
        $estado = $stmt->fetchColumn();
        if ($estado === false) {
            throw new ValidationException(['factura_id' => 'La factura no existe.']);
        }
        return (string) $estado;
    }

    /**
     * Facturas vencidas o por vencer con saldo, para la campana del layout.
     * @return array{total:int, lista:list<array<string, mixed>>}
     */
    public function alertasCampana(int $limite = 40): array
    {
        $this->refrescarEstados();
        $limite = max(1, min(100, $limite));
        $rows = $this->db->query(
            "SELECT f.id, f.cliente_id, c.nombre AS cliente, f.numero_factura, f.estado, f.saldo_pendiente,
                    f.fecha_vencimiento,
                    DATEDIFF(CURDATE(), f.fecha_vencimiento) AS dias_atraso,
                    DATEDIFF(f.fecha_vencimiento, CURDATE()) AS dias_restantes
             FROM cxc_facturas f
             JOIN cxc_clientes c ON c.id = f.cliente_id
             WHERE f.estado IN ('vencida', 'por_vencer') AND f.saldo_pendiente > 0
             ORDER BY f.estado = 'vencida' DESC, DATEDIFF(CURDATE(), f.fecha_vencimiento) DESC, f.fecha_vencimiento, f.id
             LIMIT " . $limite
        )->fetchAll();
        $total = (int) $this->db->query(
            "SELECT COUNT(*) FROM cxc_facturas
             WHERE estado IN ('vencida', 'por_vencer') AND saldo_pendiente > 0"
        )->fetchColumn();
        $lista = [];
        foreach ($rows as $r) {
            $vencida = $r['estado'] === 'vencida';
            $diasAtraso = max(0, (int) $r['dias_atraso']);
            $diasRestantes = max(0, (int) $r['dias_restantes']);
            $lista[] = [
                'cliente_id'     => (int) $r['cliente_id'],
                'cliente'        => (string) $r['cliente'],
                'factura_id'     => (int) $r['id'],
                'numero'         => (string) $r['numero_factura'],
                'estado'         => (string) $r['estado'],
                'saldo'          => (float) $r['saldo_pendiente'],
                'dias_atraso'    => $diasAtraso,
                'dias_restantes' => $diasRestantes,
                'dias'           => $vencida ? $diasAtraso : $diasRestantes,
            ];
        }
        return ['total' => $total, 'lista' => $lista];
    }

    /** Pone al día los estados que cambian solo por el paso del tiempo (vigente → por_vencer → vencida). */
    public function refrescarEstados(): int
    {
        $sql = "UPDATE cxc_facturas SET estado = " . self::SQL_ESTADO
            . " WHERE estado <> 'anulada' AND estado <> " . self::SQL_ESTADO;
        return (int) $this->db->exec($sql);
    }

    // ----------------------------------------------------------------------
    // Lecturas
    // ----------------------------------------------------------------------

    /**
     * KPIs del módulo. Se calculan desde saldos y fechas (no desde el estado guardado).
     * "Total cobrado" es dinero recibido: pagos no pagados con saldo a favor + excedentes de abonos.
     * @return array<string, mixed>
     */
    public function obtenerMetricasDashboard(): array
    {
        $this->refrescarEstados();
        $f = $this->db->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(saldo_pendiente > 0), 0) AS por_cobrar,
                    COALESCE(SUM(saldo_pendiente <= 0), 0) AS pagadas,
                    COALESCE(SUM(monto_total), 0) AS facturado,
                    COALESCE(SUM(saldo_pendiente), 0) AS a_cobrar,
                    COALESCE(SUM(CASE WHEN saldo_pendiente > 0 AND fecha_vencimiento >= CURDATE() THEN saldo_pendiente END), 0) AS vigente,
                    COALESCE(SUM(CASE WHEN saldo_pendiente > 0 AND fecha_vencimiento < CURDATE() THEN saldo_pendiente END), 0) AS vencido,
                    COALESCE(SUM(saldo_pendiente > 0 AND fecha_vencimiento < CURDATE()), 0) AS vencidas,
                    COALESCE(SUM(saldo_pendiente > 0 AND fecha_vencimiento BETWEEN CURDATE() AND CURDATE() + INTERVAL " . self::DIAS_POR_VENCER . " DAY), 0) AS por_vencer
             FROM cxc_facturas
             WHERE estado <> 'anulada'"
        )->fetch();
        $cobrado = $this->db->query(
            "SELECT (SELECT COALESCE(SUM(monto), 0) FROM cxc_pagos WHERE metodo <> 'saldo_favor' AND estado = 'aplicado')
                  + (SELECT COALESCE(SUM(monto), 0) FROM cxc_saldo_movimientos WHERE tipo = 'abono_excedente')"
        )->fetchColumn();
        $devoluciones = $this->db->query(
            "SELECT COALESCE(SUM(monto), 0) FROM cxc_devoluciones WHERE estado <> 'anulada'"
        )->fetchColumn();
        $clientes = $this->db->query('SELECT COUNT(*) AS n, COALESCE(SUM(saldo_favor), 0) AS credito FROM cxc_clientes')->fetch();

        return [
            'clientes'           => (int) $clientes['n'],
            'facturas'           => (int) $f['total'],
            'por_cobrar'         => (int) $f['por_cobrar'],
            'pagadas'            => (int) $f['pagadas'],
            'facturado'          => (string) $f['facturado'],
            'a_cobrar'           => (string) $f['a_cobrar'],
            'cartera_vigente'    => (string) $f['vigente'],
            'cartera_vencida'    => (string) $f['vencido'],
            'cobrado'            => (string) $cobrado,
            'total_devoluciones' => (string) $devoluciones,
            'vencido'            => (string) $f['vencido'],
            'vencidas'           => (int) $f['vencidas'],
            'por_vencer'         => (int) $f['por_vencer'],
            'credito'            => (string) $clientes['credito'],
            'prioridad'          => $this->aQuienesCobrarPrimero(),
        ];
    }

    /** @return list<array{cliente_id:int, cliente:string, saldo_total:string, vencido:string, facturas_vencidas:int, dias_atraso:int}> */
    public function aQuienesCobrarPrimero(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id AS cliente_id, c.nombre AS cliente,
                    SUM(f.saldo_pendiente) AS saldo_total,
                    SUM(CASE WHEN f.fecha_vencimiento < CURDATE() THEN f.saldo_pendiente ELSE 0 END) AS vencido,
                    SUM(f.fecha_vencimiento < CURDATE()) AS facturas_vencidas,
                    MAX(CASE WHEN f.fecha_vencimiento < CURDATE() THEN DATEDIFF(CURDATE(), f.fecha_vencimiento) ELSE 0 END) AS dias_atraso
             FROM cxc_facturas f
             JOIN cxc_clientes c ON c.id = f.cliente_id
             WHERE f.saldo_pendiente > 0
             GROUP BY c.id, c.nombre
             HAVING facturas_vencidas > 0
             ORDER BY dias_atraso DESC, vencido DESC
             LIMIT :limite'
        );
        $stmt->bindValue('limite', max(1, min(500, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn (array $r): array => [
            'cliente_id'        => (int) $r['cliente_id'],
            'cliente'           => (string) $r['cliente'],
            'saldo_total'       => (string) $r['saldo_total'],
            'vencido'           => (string) $r['vencido'],
            'facturas_vencidas' => (int) $r['facturas_vencidas'],
            'dias_atraso'       => (int) $r['dias_atraso'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function clientes(): array
    {
        return $this->db->query('SELECT id, nombre, documento, telefono, domicilio, saldo_favor FROM cxc_clientes ORDER BY nombre')->fetchAll();
    }

    /**
     * Facturas con el desglose de lo pagado en dinero y lo cubierto con saldo a favor.
     * @param list<int>|null $ids
     * @return list<array<string, mixed>>
     */
    public function facturas(?array $ids = null): array
    {
        $where = '';
        if ($ids !== null) {
            if ($ids === []) {
                return [];
            }
            $where = 'WHERE f.id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        return $this->db->query(
            "SELECT f.id, f.numero_factura, f.cliente_id, c.nombre AS cliente, f.domicilio, f.observaciones,
                    f.fecha_emision, f.fecha_vencimiento, f.monto_total, f.total_pagado, f.total_devuelto, f.saldo_pendiente, f.estado,
                    COALESCE((SELECT SUM(p.monto) FROM cxc_pagos p WHERE p.factura_id = f.id AND p.metodo = 'saldo_favor' AND p.estado = 'aplicado'), 0) AS credito_aplicado
             FROM cxc_facturas f
             JOIN cxc_clientes c ON c.id = f.cliente_id
             $where
             ORDER BY f.fecha_emision DESC, f.id DESC"
        )->fetchAll();
    }

    /**
     * @param list<int>|null $ids
     * @return list<array<string, mixed>>
     */
    public function pagos(?array $ids = null): array
    {
        $where = $ids === null ? '' : ($ids === [] ? 'WHERE 1 = 0' : 'WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        return $this->db->query("SELECT id, factura_id, cliente_id, monto, fecha, metodo, referencia, notas, tasa_cambio, monto_bs, estado FROM cxc_pagos $where ORDER BY fecha DESC, id DESC")->fetchAll();
    }

    /**
     * @param list<int>|null $ids
     * @return list<array<string, mixed>>
     */
    public function devoluciones(?array $ids = null): array
    {
        $where = $ids === null ? '' : ($ids === [] ? 'WHERE 1 = 0' : 'WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        return $this->db->query(
            "SELECT id, cliente_id, factura_origen_id, factura_aplicada_id, monto, fecha, motivo, destino, estado
             FROM cxc_devoluciones $where ORDER BY fecha DESC, id DESC"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> facturas con saldo cuyo número o cliente contiene $q */
    public function buscarPendientes(string $q, int $limite = 50): array
    {
        $patron = '%' . addcslashes($q, '\\%_') . '%';
        $stmt = $this->db->prepare(
            "SELECT f.id FROM cxc_facturas f JOIN cxc_clientes c ON c.id = f.cliente_id
             WHERE f.saldo_pendiente > 0 AND (f.numero_factura LIKE :q1 OR c.nombre LIKE :q2)
             ORDER BY f.fecha_vencimiento ASC, f.id ASC
             LIMIT :limite"
        );
        $stmt->bindValue('q1', $patron);
        $stmt->bindValue('q2', $patron);
        $stmt->bindValue('limite', max(1, min(200, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $porId = array_column($this->facturas($ids), null, 'id');
        return array_values(array_filter(array_map(static fn (int $id) => $porId[$id] ?? null, $ids)));
    }

    /** @return array<string, mixed>|null */
    public function cliente(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, nombre, documento, telefono, domicilio, saldo_favor FROM cxc_clientes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // ----------------------------------------------------------------------
    // Internos
    // ----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function bloquearCliente(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM cxc_clientes WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['cliente_id' => 'El cliente no existe.']);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function bloquearFactura(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM cxc_facturas WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['factura_id' => 'La factura no existe.']);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function bloquearPago(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM cxc_pagos WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['id' => 'El pago no existe.']);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function bloquearDevolucion(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM cxc_devoluciones WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['id' => 'La devolución no existe.']);
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function pagosDeFactura(int $facturaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, factura_id, cliente_id, monto, fecha, metodo, referencia, notas, tasa_cambio, monto_bs, estado
             FROM cxc_pagos WHERE factura_id = :id ORDER BY fecha DESC, id DESC'
        );
        $stmt->execute(['id' => $facturaId]);
        return $stmt->fetchAll();
    }

    private function numeroFactura(int $id): string
    {
        $stmt = $this->db->prepare('SELECT numero_factura FROM cxc_facturas WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $n = $stmt->fetchColumn();
        return $n === false ? '' : (string) $n;
    }

    private function clienteDeFactura(int $facturaId): int
    {
        $stmt = $this->db->prepare('SELECT cliente_id FROM cxc_facturas WHERE id = :id');
        $stmt->execute(['id' => $facturaId]);
        $v = $stmt->fetchColumn();
        if ($v === false) {
            throw new ValidationException(['factura_id' => 'La factura no existe.']);
        }
        return (int) $v;
    }

    /**
     * Marca como aplicadas, en orden FIFO, las devoluciones a crédito que cubre $montoC.
     * Solo cierra una devolución si el remanente del pago alcanza su monto completo.
     * @return list<int>
     */
    private function consumirDevolucionesPendientesFifo(int $clienteId, int $montoC): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, monto FROM cxc_devoluciones
             WHERE cliente_id = :c AND estado = 'pendiente'
             ORDER BY fecha ASC, id ASC
             FOR UPDATE"
        );
        $stmt->execute(['c' => $clienteId]);
        $resto = $montoC;
        $ids = [];
        $upd = $this->db->prepare("UPDATE cxc_devoluciones SET estado = 'aplicada' WHERE id = :id");
        foreach ($stmt->fetchAll() as $d) {
            $devC = Caja::aCentavos($d['monto']);
            if ($resto < $devC) {
                break;
            }
            $upd->execute(['id' => (int) $d['id']]);
            $ids[] = (int) $d['id'];
            $resto -= $devC;
        }
        return $ids;
    }

    /** @return array{0:?string, 1:?string} */
    private static function normalizarTasa(?string $tasa, ?string $montoBs, int $montoUsdC): array
    {
        if ($tasa === null && $montoBs === null) {
            return [null, null];
        }
        if ($tasa === null || (float) $tasa <= 0) {
            throw new ValidationException(['tasa_cambio' => 'La tasa de cambio debe ser mayor que 0 (Bs por 1 USD).']);
        }
        $bs = $montoBs;
        if ($bs === null) {
            $bs = number_format(round($montoUsdC * (float) $tasa / 100, 2), 2, '.', '');
        }
        if ((float) $bs <= 0) {
            throw new ValidationException(['monto_bs' => 'El monto en bolívares debe ser mayor que 0.']);
        }
        return [$tasa, $bs];
    }

    /** Inserta el pago y ajusta totales de una factura ya bloqueada. El llamador valida que no supere el saldo. */
    private function aplicarPago(array $factura, int $montoC, string $fecha, string $metodo, ?string $ref, ?string $notas, ?string $lote, int $userId, ?string $tasaCambio = null, ?string $montoBs = null): int
    {
        $m = Caja::desdeCentavos($montoC);
        $this->db->prepare(
            'INSERT INTO cxc_pagos (factura_id, cliente_id, monto, fecha, metodo, referencia, notas, tasa_cambio, monto_bs, lote, registrado_por)
             VALUES (:factura, :cliente, :monto, :fecha, :metodo, :ref, :notas, :tasa, :bs, :lote, :usuario)'
        )->execute([
            'factura' => $factura['id'],
            'cliente' => $factura['cliente_id'],
            'monto'   => $m,
            'fecha'   => $fecha,
            'metodo'  => $metodo,
            'ref'     => self::nulo($ref),
            'notas'   => self::nulo($notas),
            'tasa'    => $tasaCambio,
            'bs'      => $montoBs,
            'lote'    => $lote,
            'usuario' => $userId,
        ]);
        $pagoId = (int) $this->db->lastInsertId();
        $this->db->prepare(
            'UPDATE cxc_facturas SET total_pagado = total_pagado + :m1, saldo_pendiente = saldo_pendiente - :m2 WHERE id = :id'
        )->execute(['m1' => $m, 'm2' => $m, 'id' => $factura['id']]);
        return $pagoId;
    }

    /** Suma (o resta) al saldo a favor del cliente ya bloqueado y deja el asiento en el libro. Devuelve el saldo final. */
    private function moverSaldoFavor(int $clienteId, int $deltaC, string $tipo, string $fecha, int $userId,
                                     ?int $factura = null, ?int $devolucion = null, ?int $pago = null, ?string $lote = null): string
    {
        $stmt = $this->db->prepare('SELECT saldo_favor FROM cxc_clientes WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $clienteId]);
        $nuevoC = Caja::aCentavos($stmt->fetchColumn()) + $deltaC;
        if ($nuevoC < 0) {
            throw new ValidationException(['saldo_favor' => 'Saldo a favor insuficiente.']);
        }
        $nuevo = Caja::desdeCentavos($nuevoC);
        $this->db->prepare('UPDATE cxc_clientes SET saldo_favor = :s WHERE id = :id')->execute(['s' => $nuevo, 'id' => $clienteId]);
        $this->db->prepare(
            'INSERT INTO cxc_saldo_movimientos (cliente_id, tipo, monto, saldo_resultante, factura_id, devolucion_id, pago_id, lote, fecha, registrado_por)
             VALUES (:cliente, :tipo, :monto, :saldo, :factura, :devolucion, :pago, :lote, :fecha, :usuario)'
        )->execute([
            'cliente'    => $clienteId,
            'tipo'       => $tipo,
            'monto'      => Caja::desdeCentavos($deltaC),
            'saldo'      => $nuevo,
            'factura'    => $factura,
            'devolucion' => $devolucion,
            'pago'       => $pago,
            'lote'       => $lote,
            'fecha'      => $fecha,
            'usuario'    => $userId,
        ]);
        return $nuevo;
    }

    /**
     * Normaliza renglones: cantidad y precio > 0 con 2 decimales; subtotal = round(cantidad × precio) en centavos.
     * Descarta filas totalmente vacías; una fila a medio llenar es un error.
     * @return list<array{cantidad:string, precio:string, subtotal_c:int, descripcion:?string}>
     */
    private function renglones(array $detalles): array
    {
        if (count($detalles) > self::MAX_RENGLONES) {
            throw new ValidationException(['renglones' => 'Demasiados renglones (máximo ' . self::MAX_RENGLONES . ').']);
        }
        $out = [];
        foreach (array_values($detalles) as $i => $d) {
            if (!is_array($d)) {
                throw new ValidationException(['renglones' => 'Renglón ' . ($i + 1) . ' inválido.']);
            }
            $cantRaw = $d['cantidad'] ?? '';
            $precioRaw = $d['precio'] ?? '';
            $desc = trim((string) ($d['descripcion'] ?? ''));
            if (($cantRaw === '' || $cantRaw === 0 || $cantRaw === '0') && ($precioRaw === '' || $precioRaw === 0 || $precioRaw === '0') && $desc === '') {
                continue;
            }
            $cantC = self::centavos($cantRaw, 'renglones', 'Renglón ' . ($i + 1) . ': cantidad inválida.');
            if ($cantC > self::MAX_CANTIDAD_CENTESIMAS) {
                throw new ValidationException(['renglones' => 'Renglón ' . ($i + 1) . ': cantidad fuera de rango.']);
            }
            $precioC = self::centavos($precioRaw, 'renglones', 'Renglón ' . ($i + 1) . ': precio inválido.');
            $subC = intdiv($cantC * $precioC + 50, 100);
            if ($subC <= 0 || $subC > self::MAX_CENTAVOS) {
                throw new ValidationException(['renglones' => 'Renglón ' . ($i + 1) . ': subtotal fuera de rango.']);
            }
            $out[] = [
                'cantidad'    => Caja::desdeCentavos($cantC),
                'precio'      => Caja::desdeCentavos($precioC),
                'subtotal_c'  => $subC,
                'descripcion' => $desc === '' ? null : mb_substr(preg_replace('/\s+/u', ' ', $desc), 0, 120),
            ];
        }
        return $out;
    }

    /** Importe > 0 con hasta 2 decimales → centavos. Acepta float o texto ("1.234,56", "1234.56"). */
    private static function centavos(mixed $v, string $campo, ?string $mensaje = null): int
    {
        if (is_int($v) || is_float($v)) {
            $c = is_float($v) && !is_finite($v) ? 0 : (int) round($v * 100);
        } else {
            $s = parse_monto(is_string($v) ? $v : null, 2, 9999999999.99);
            $c = $s === null ? 0 : Caja::aCentavos($s);
        }
        if ($c <= 0 || $c > self::MAX_CENTAVOS) {
            throw new ValidationException([$campo => $mensaje ?? 'Monto inválido: mayor que 0, hasta 2 decimales.']);
        }
        return $c;
    }

    private static function validarMetodo(string $metodo): void
    {
        if (!in_array($metodo, self::METODOS_MANUALES, true) && $metodo !== 'saldo_favor') {
            throw new ValidationException(['metodo' => 'Método de pago inválido.']);
        }
    }

    private static function nulo(mixed $v): ?string
    {
        $v = $v === null ? '' : trim((string) $v);
        return $v === '' ? null : $v;
    }
}
