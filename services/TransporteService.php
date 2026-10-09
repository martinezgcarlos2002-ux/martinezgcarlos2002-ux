<?php
declare(strict_types=1);

/**
 * Logística: transportistas (flotilla), rutas, fletes diarios y cierres semanales.
 * Orden de bloqueo: transportista → ruta → fletes (id ASC) → cierre.
 */
final class TransporteService
{
    public const VEHICULOS = [
        'moto'      => 'Moto',
        'carro'     => 'Carro',
        'camioneta' => 'Camioneta',
        'camion'    => 'Camión',
        'otro'      => 'Otro',
    ];

    public const TIPOS_PAGO = [
        'efectivo'      => 'Efectivo',
        'transferencia' => 'Transferencia',
        'pago_movil'    => 'Pago móvil',
        'mercancia'     => 'Mercancía',
        'otros'         => 'Otros',
    ];

    /** Umbral de alerta: ((precio_vehiculo * vehiculos) / 7) / litros. */
    public const COSTO_LITRO_MAX = 0.10;

    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array{
     *   gasto_diario:string, gasto_semanal:string, promedio_carga:string, transportistas:int,
     *   pagado_historico:string, deuda_pendiente:string, litros_historico:string
     * }
     */
    public function obtenerMetricas(): array
    {
        $hoy = date('Y-m-d');
        $lunes = (new DateTimeImmutable($hoy))->modify('monday this week')->format('Y-m-d');
        $domingo = (new DateTimeImmutable($hoy))->modify('sunday this week')->format('Y-m-d');

        $diario = $this->db->prepare(
            'SELECT COALESCE(SUM(costo_flete), 0) FROM log_fletes_diarios WHERE fecha = :f'
        );
        $diario->execute(['f' => $hoy]);
        $semana = $this->db->prepare(
            'SELECT COALESCE(SUM(costo_flete), 0) FROM log_fletes_diarios
             WHERE fecha BETWEEN :a AND :b'
        );
        $semana->execute(['a' => $lunes, 'b' => $domingo]);
        $prom = $this->db->prepare(
            'SELECT COALESCE(AVG(litros_recolectados), 0) FROM log_fletes_diarios
             WHERE fecha BETWEEN :a AND :b'
        );
        $prom->execute(['a' => $lunes, 'b' => $domingo]);
        $n = (int) $this->db->query("SELECT COUNT(*) FROM log_transportistas WHERE estado = 'activo'")->fetchColumn();
        $pagado = (string) $this->db->query(
            'SELECT COALESCE(SUM(monto), 0) FROM log_pagos_transporte'
        )->fetchColumn();
        $deuda = (string) $this->db->query(
            'SELECT COALESCE(SUM(GREATEST(p.total_pagar - COALESCE(a.pagado, 0), 0)), 0)
             FROM log_pagos_semanales p
             LEFT JOIN (
                 SELECT pago_semanal_id, SUM(monto) AS pagado
                 FROM log_pagos_transporte
                 GROUP BY pago_semanal_id
             ) a ON a.pago_semanal_id = p.id'
        )->fetchColumn();
        $litrosH = (string) $this->db->query(
            'SELECT COALESCE(SUM(litros_recolectados), 0) FROM log_fletes_diarios'
        )->fetchColumn();

        return [
            'gasto_diario'      => (string) $diario->fetchColumn(),
            'gasto_semanal'     => (string) $semana->fetchColumn(),
            'promedio_carga'    => (string) $prom->fetchColumn(),
            'transportistas'    => $n,
            'pagado_historico'  => $pagado,
            'deuda_pendiente'   => $deuda,
            'litros_historico'  => $litrosH,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function productoresDisponibles(): array
    {
        return $this->db->query(
            'SELECT id, nombre, documento FROM productores WHERE activo = 1 ORDER BY nombre'
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function transportistas(): array
    {
        $rows = $this->db->query(
            'SELECT id, documento, nombre, telefono, modalidad, estado
             FROM log_transportistas ORDER BY nombre'
        )->fetchAll();
        $rutas = $this->rutas();
        $fletes = $this->fletes();
        $pagos = $this->pagos();
        $veh = $this->vehiculosPorTransportista();
        $porTr = [];
        foreach ($rows as $t) {
            $id = (int) $t['id'];
            $t['vehiculos'] = $veh[$id] ?? [];
            $porTr[$id] = $t + ['rutas' => [], 'fletes' => [], 'pagos' => []];
        }
        foreach ($rutas as $r) {
            $tid = (int) $r['transportista_id'];
            if (isset($porTr[$tid])) {
                $porTr[$tid]['rutas'][] = $r;
            }
        }
        foreach ($fletes as $f) {
            $tid = (int) $f['transportista_id'];
            if (isset($porTr[$tid])) {
                $porTr[$tid]['fletes'][] = $f;
            }
        }
        foreach ($pagos as $p) {
            $tid = (int) $p['transportista_id'];
            if (isset($porTr[$tid])) {
                $porTr[$tid]['pagos'][] = $p;
            }
        }
        return array_values($porTr);
    }

    /** @return list<array<string, mixed>> */
    public function rutas(?int $transportistaId = null): array
    {
        $sql = 'SELECT r.id, r.transportista_id, r.nombre_zona, r.precio_viaje, r.vehiculos_asignados, r.estado
                FROM log_rutas r';
        $params = [];
        if ($transportistaId !== null) {
            $sql .= ' WHERE r.transportista_id = :t';
            $params['t'] = $transportistaId;
        }
        $sql .= ' ORDER BY r.nombre_zona, r.id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rutas = $stmt->fetchAll();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rutas);
        $prod = $this->productoresDeRutas($ids);
        foreach ($rutas as &$r) {
            $r['productores'] = $prod[(int) $r['id']] ?? [];
        }
        unset($r);
        return $rutas;
    }

    /**
     * @param list<int> $rutaIds
     * @return array<int, list<array{id:int, nombre:string}>>
     */
    private function productoresDeRutas(array $rutaIds): array
    {
        if ($rutaIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $rutaIds));
        $rows = $this->db->query(
            "SELECT rp.ruta_id, p.id, p.nombre
             FROM log_ruta_productores rp
             JOIN productores p ON p.id = rp.productor_id
             WHERE rp.ruta_id IN ($in)
             ORDER BY p.nombre"
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['ruta_id']][] = ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre']];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function fletes(?int $transportistaId = null): array
    {
        $sql = 'SELECT f.id, f.transportista_id, f.ruta_id, r.nombre_zona, f.fecha,
                       f.litros_recolectados, f.costo_flete, f.pago_id
                FROM log_fletes_diarios f
                JOIN log_rutas r ON r.id = f.ruta_id';
        $params = [];
        if ($transportistaId !== null) {
            $sql .= ' WHERE f.transportista_id = :t';
            $params['t'] = $transportistaId;
        }
        $sql .= ' ORDER BY f.fecha DESC, f.id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function pagos(?int $transportistaId = null): array
    {
        $sql = 'SELECT p.id, p.transportista_id, p.fecha_inicio, p.fecha_fin, p.total_litros, p.total_pagar, p.estado,
                       COALESCE(a.pagado, 0) AS pagado_usd
                FROM log_pagos_semanales p
                LEFT JOIN (
                    SELECT pago_semanal_id, COALESCE(SUM(monto), 0) AS pagado
                    FROM log_pagos_transporte
                    GROUP BY pago_semanal_id
                ) a ON a.pago_semanal_id = p.id';
        $params = [];
        if ($transportistaId !== null) {
            $sql .= ' WHERE p.transportista_id = :t';
            $params['t'] = $transportistaId;
        }
        $sql .= ' ORDER BY p.fecha_fin DESC, p.id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array{nombre:string, documento:string, telefono:?string, modalidad:string, vehiculos:list<array<string, mixed>>} $data
     */
    public function guardarTransportista(array $data, ?int $id, int $userId): int
    {
        $modalidad = $data['modalidad'] === 'cooperativa' ? 'cooperativa' : 'individual';
        $vehiculos = $this->normalizarVehiculos($data['vehiculos'] ?? []);
        if ($modalidad === 'individual' && count($vehiculos) !== 1) {
            throw new ValidationException(['vehiculos' => 'Un transportista individual debe registrar exactamente 1 vehículo.']);
        }
        if ($modalidad === 'cooperativa' && count($vehiculos) < 2) {
            throw new ValidationException(['vehiculos' => 'Una cooperativa debe registrar al menos 2 vehículos.']);
        }
        return Database::transaction(function () use ($data, $id, $userId, $modalidad, $vehiculos): int {
            $this->asegurarDocumentoUnico((string) $data['documento'], $id);
            try {
                if ($id === null) {
                    $stmt = $this->db->prepare(
                        'INSERT INTO log_transportistas
                            (documento, nombre, telefono, modalidad, registrado_por)
                         VALUES (:documento, :nombre, :telefono, :modalidad, :usuario)'
                    );
                    $stmt->execute([
                        'documento'  => $data['documento'],
                        'nombre'     => $data['nombre'],
                        'telefono'   => $data['telefono'],
                        'modalidad'  => $modalidad,
                        'usuario'    => $userId,
                    ]);
                    $id = (int) $this->db->lastInsertId();
                    $this->reemplazarVehiculos($id, $vehiculos);
                    audit_log('transportistas', 'crear', $data + ['vehiculos' => count($vehiculos)], 'log_transportistas', $id);
                    return $id;
                }
                $this->bloquearTransportista($id);
                $this->db->prepare(
                    'UPDATE log_transportistas
                     SET documento = :documento, nombre = :nombre, telefono = :telefono, modalidad = :modalidad
                     WHERE id = :id'
                )->execute([
                    'documento' => $data['documento'],
                    'nombre'    => $data['nombre'],
                    'telefono'  => $data['telefono'],
                    'modalidad' => $modalidad,
                    'id'        => $id,
                ]);
                $this->reemplazarVehiculos($id, $vehiculos);
                audit_log('transportistas', 'editar', $data + ['vehiculos' => count($vehiculos)], 'log_transportistas', $id);
                return $id;
            } catch (PDOException $e) {
                $dup = duplicate_key($e);
                if ($dup === 'uq_log_tr_documento') {
                    throw new ValidationException(['documento' => 'Ya existe un transportista con ese documento.']);
                }
                if ($dup === 'uq_log_tv_placa') {
                    throw new ValidationException(['vehiculos' => 'Hay una placa duplicada en la flotilla o ya registrada.']);
                }
                throw $e;
            }
        });
    }

    public function cambiarEstado(int $id, string $estado): void
    {
        if (!in_array($estado, ['activo', 'inactivo'], true)) {
            throw new ValidationException(['estado' => 'Estado inválido.']);
        }
        Database::transaction(function () use ($id, $estado): void {
            $this->bloquearTransportista($id);
            $this->db->prepare('UPDATE log_transportistas SET estado = :e WHERE id = :id')->execute(['e' => $estado, 'id' => $id]);
            audit_log('transportistas', 'estado', ['estado' => $estado], 'log_transportistas', $id);
        });
    }

    /**
     * Crea o actualiza una ruta y reemplaza sus productores (ACID).
     * @param list<int> $productorIds
     */
    public function guardarRuta(
        int $transportistaId,
        ?int $rutaId,
        string $nombreZona,
        string $precio,
        int $vehiculosAsignados,
        array $productorIds,
        int $userId
    ): int {
        if ($productorIds === []) {
            throw new ValidationException(['productores' => 'La ruta debe incluir al menos un productor.']);
        }
        $productorIds = array_values(array_unique(array_map('intval', $productorIds)));
        $nVeh = $vehiculosAsignados;
        if ($nVeh < 1 || $nVeh > 99) {
            throw new ValidationException(['vehiculos_asignados' => 'Indica entre 1 y 99 vehículos asignados.']);
        }
        return Database::transaction(function () use ($transportistaId, $rutaId, $nombreZona, $precio, $nVeh, $productorIds, $userId): int {
            $tr = $this->bloquearTransportista($transportistaId);
            if ($tr['estado'] === 'inactivo') {
                throw new ValidationException(['transportista_id' => 'El transportista está inactivo.']);
            }
            $flota = count($this->vehiculosDe($transportistaId));
            if ($nVeh > max(1, $flota)) {
                throw new ValidationException(['vehiculos_asignados' => 'No puedes asignar más vehículos que los de la flotilla.']);
            }
            $this->validarProductores($productorIds);
            if ($rutaId === null) {
                $this->db->prepare(
                    'INSERT INTO log_rutas (transportista_id, nombre_zona, precio_viaje, vehiculos_asignados, registrado_por)
                     VALUES (:t, :n, :p, :v, :u)'
                )->execute(['t' => $transportistaId, 'n' => $nombreZona, 'p' => $precio, 'v' => $nVeh, 'u' => $userId]);
                $rutaId = (int) $this->db->lastInsertId();
            } else {
                $ruta = $this->bloquearRuta($rutaId);
                if ((int) $ruta['transportista_id'] !== $transportistaId) {
                    throw new ValidationException(['ruta_id' => 'La ruta no pertenece a este transportista.']);
                }
                $this->db->prepare(
                    'UPDATE log_rutas SET nombre_zona = :n, precio_viaje = :p, vehiculos_asignados = :v WHERE id = :id'
                )->execute(['n' => $nombreZona, 'p' => $precio, 'v' => $nVeh, 'id' => $rutaId]);
                $this->db->prepare('DELETE FROM log_ruta_productores WHERE ruta_id = :id')->execute(['id' => $rutaId]);
            }
            $ins = $this->db->prepare(
                'INSERT INTO log_ruta_productores (ruta_id, productor_id) VALUES (:r, :p)'
            );
            foreach ($productorIds as $pid) {
                $ins->execute(['r' => $rutaId, 'p' => $pid]);
            }
            audit_log('transportistas', 'ruta', [
                'ruta_id' => $rutaId,
                'zona'    => $nombreZona,
                'n'       => count($productorIds),
            ], 'log_rutas', $rutaId);
            return $rutaId;
        });
    }

    /**
     * Registra el viaje: litros = SUM(productores_entregas) de los productores de la ruta en esa fecha.
     * @return array{id:int, litros:string, costo_litro:string, warning:?string}
     */
    public function registrarFlete(int $rutaId, string $fecha, int $userId): array
    {
        return Database::transaction(function () use ($rutaId, $fecha, $userId): array {
            $peek = $this->db->prepare('SELECT transportista_id FROM log_rutas WHERE id = :id');
            $peek->execute(['id' => $rutaId]);
            $trId = (int) $peek->fetchColumn();
            if ($trId < 1) {
                throw new ValidationException(['ruta_id' => 'La ruta no existe.']);
            }
            $tr = $this->bloquearTransportista($trId);
            $ruta = $this->bloquearRuta($rutaId);
            if ((int) $ruta['transportista_id'] !== $trId) {
                throw new ValidationException(['ruta_id' => 'La ruta no pertenece a este transportista.']);
            }
            if ($ruta['estado'] === 'inactivo') {
                throw new ValidationException(['ruta_id' => 'La ruta está inactiva.']);
            }
            if ($tr['estado'] === 'inactivo') {
                throw new ValidationException(['transportista_id' => 'El transportista está inactivo.']);
            }

            $litros = $this->litrosRutaEnFecha($rutaId, $fecha);
            $litrosN = (float) $litros;
            if ($litrosN <= 0) {
                throw new ValidationException(['fecha' => 'Ningún productor de esta ruta entregó leche en esa fecha. Revisa Control de Leche.']);
            }

            $flotilla = $this->flotillaDe($trId);
            $nVeh = max(1, $flotilla['n']);
            $capTotal = $flotilla['capacidad_total'];
            if ($capTotal > 0 && $litrosN > $capTotal) {
                throw new ValidationException(['litros' => sprintf(
                    'Los litros (%.2f L) superan la capacidad total de la flotilla (%.2f kg, %d vehículo%s).',
                    $litrosN,
                    $capTotal,
                    $nVeh,
                    $nVeh === 1 ? '' : 's'
                )]);
            }

            $nAsig = max(1, (int) ($ruta['vehiculos_asignados'] ?? 1));
            $costoDiario = Caja::desdeCentavos(self::costoDiarioCentavos((string) $ruta['precio_viaje'], $nAsig));
            $costoLitro = $litrosN > 0 ? ((float) $costoDiario) / $litrosN : 0.0;
            $warning = null;
            if ($costoLitro > self::COSTO_LITRO_MAX) {
                $warning = sprintf(
                    'Alerta de Rentabilidad: El flete cuesta $%s por litro (umbral $0,10).',
                    number_format($costoLitro, 4, ',', '.')
                );
            }

            try {
                $this->db->prepare(
                    'INSERT INTO log_fletes_diarios
                        (transportista_id, ruta_id, fecha, litros_recolectados, costo_flete, registrado_por)
                     VALUES (:t, :r, :f, :l, :c, :u)'
                )->execute([
                    't' => $tr['id'],
                    'r' => $rutaId,
                    'f' => $fecha,
                    'l' => $litros,
                    'c' => $costoDiario,
                    'u' => $userId,
                ]);
            } catch (PDOException $e) {
                if (duplicate_key($e) === 'uq_log_flete_dia') {
                    throw new ValidationException(['fecha' => 'Ya hay un flete de esta ruta en esa fecha.']);
                }
                throw $e;
            }
            $id = (int) $this->db->lastInsertId();
            audit_log('transportistas', 'flete', [
                'ruta'        => $ruta['nombre_zona'],
                'fecha'       => $fecha,
                'litros'      => $litros,
                'costo_litro' => number_format($costoLitro, 6, '.', ''),
            ], 'log_fletes_diarios', $id);
            return [
                'id'           => $id,
                'litros'       => $litros,
                'costo_diario' => number_format($costoDiario, 6, '.', ''),
                'costo_litro'  => number_format($costoLitro, 6, '.', ''),
                'warning'      => $warning,
            ];
        });
    }

    /** Suma fletes sin pago, crea el cierre semanal y los marca procesados. */
    public function cerrarSemana(int $transportistaId, int $userId): int
    {
        return Database::transaction(function () use ($transportistaId, $userId): int {
            $this->bloquearTransportista($transportistaId);
            $stmt = $this->db->prepare(
                'SELECT id, fecha, litros_recolectados, costo_flete
                 FROM log_fletes_diarios
                 WHERE transportista_id = :t AND pago_id IS NULL
                 ORDER BY id ASC
                 FOR UPDATE'
            );
            $stmt->execute(['t' => $transportistaId]);
            $fletes = $stmt->fetchAll();
            if ($fletes === []) {
                throw new ValidationException(['transportista_id' => 'No hay viajes diarios pendientes de cierre.']);
            }
            $litrosC = 0;
            $pagarC = 0;
            $fechas = [];
            foreach ($fletes as $f) {
                $litrosC += Caja::aCentavos($f['litros_recolectados']);
                $pagarC += Caja::aCentavos($f['costo_flete']);
                $fechas[] = (string) $f['fecha'];
            }
            sort($fechas);
            $this->db->prepare(
                'INSERT INTO log_pagos_semanales
                    (transportista_id, fecha_inicio, fecha_fin, total_litros, total_pagar, estado, registrado_por)
                 VALUES (:t, :a, :b, :l, :p, \'pendiente\', :u)'
            )->execute([
                't' => $transportistaId,
                'a' => $fechas[0],
                'b' => $fechas[array_key_last($fechas)],
                'l' => Caja::desdeCentavos($litrosC),
                'p' => Caja::desdeCentavos($pagarC),
                'u' => $userId,
            ]);
            $pagoId = (int) $this->db->lastInsertId();
            $upd = $this->db->prepare('UPDATE log_fletes_diarios SET pago_id = :p WHERE id = :id');
            foreach ($fletes as $f) {
                $upd->execute(['p' => $pagoId, 'id' => $f['id']]);
            }
            audit_log('transportistas', 'cierre', [
                'viajes' => count($fletes),
                'total'  => Caja::desdeCentavos($pagarC),
            ], 'log_pagos_semanales', $pagoId);
            return $pagoId;
        });
    }

    public function reabrirSemana(int $pagoId, int $userId): int
    {
        return Database::transaction(function () use ($pagoId, $userId): int {
            $peek = $this->db->prepare('SELECT transportista_id FROM log_pagos_semanales WHERE id = :id');
            $peek->execute(['id' => $pagoId]);
            $trId = (int) $peek->fetchColumn();
            if ($trId < 1) {
                throw new ValidationException(['pago_id' => 'El cierre no existe.']);
            }
            $this->bloquearTransportista($trId);
            $this->bloquearCierre($pagoId);
            $fletes = $this->db->prepare(
                'SELECT id FROM log_fletes_diarios WHERE pago_id = :p ORDER BY id ASC FOR UPDATE'
            );
            $fletes->execute(['p' => $pagoId]);
            $nFletes = count($fletes->fetchAll());
            $this->db->prepare('DELETE FROM log_pagos_transporte WHERE pago_semanal_id = :id')->execute(['id' => $pagoId]);
            $this->db->prepare('UPDATE log_fletes_diarios SET pago_id = NULL WHERE pago_id = :p')->execute(['p' => $pagoId]);
            $this->db->prepare('DELETE FROM log_pagos_semanales WHERE id = :id')->execute(['id' => $pagoId]);
            audit_log('transportistas', 'reabrir', [
                'pago_id' => $pagoId,
                'fletes'  => $nFletes,
            ], 'log_pagos_semanales', $pagoId);
            return $trId;
        });
    }

    /**
     * Promedio diario (últimos 7 días) de los productores y capacidad de la flotilla.
     * @param list<int> $productorIds
     * @return array<string, mixed>
     */
    public function analizarRuta(int $transportistaId, array $productorIds): array
    {
        $tr = $this->transportista($transportistaId);
        $flotilla = $this->resumenFlotilla($tr['vehiculos'] ?? []);
        $ids = array_values(array_unique(array_map('intval', $productorIds)));
        $detalle = [];
        $volumen = 0.0;
        if ($ids !== []) {
            $this->validarProductores($ids);
            $in = implode(',', $ids);
            $stmt = $this->db->query(
                "SELECT p.id, p.nombre,
                        COALESCE(SUM(e.litros), 0) / 7 AS promedio_diario
                 FROM productores p
                 LEFT JOIN productores_entregas e
                        ON e.productor_id = p.id
                       AND e.fecha BETWEEN (CURDATE() - INTERVAL 6 DAY) AND CURDATE()
                 WHERE p.id IN ($in)
                 GROUP BY p.id, p.nombre
                 ORDER BY p.nombre"
            );
            foreach ($stmt->fetchAll() as $row) {
                $prom = (float) $row['promedio_diario'];
                $volumen += $prom;
                $detalle[] = [
                    'id'              => (int) $row['id'],
                    'nombre'          => (string) $row['nombre'],
                    'promedio_diario' => round($prom, 3),
                ];
            }
        }
        $capMax = $flotilla['capacidad_max'];
        $nReq = 0;
        if ($volumen > 0 && $capMax > 0) {
            $nReq = (int) ceil($volumen / $capMax);
        }
        return [
            'volumen_estimado'     => round($volumen, 3),
            'vehiculos'            => $flotilla['n'],
            'capacidad_total'      => $flotilla['capacidad_total'],
            'capacidad_max'        => $capMax,
            'vehiculos_requeridos' => $nReq,
            'detalle'              => $detalle,
        ];
    }

    /**
     * @param array{fecha:string, monto:string, tipo:string, descripcion_mercancia:?string} $data
     */
    public function registrarPagoCierre(int $pagoSemanalId, array $data, int $userId): int
    {
        if (!isset(self::TIPOS_PAGO[$data['tipo']])) {
            throw new ValidationException(['tipo' => 'Tipo de pago inválido.']);
        }
        $montoC = Caja::aCentavos($data['monto']);
        if ($montoC <= 0) {
            throw new ValidationException(['monto' => 'El monto debe ser mayor que 0.']);
        }
        if ($data['tipo'] === 'mercancia' && ($data['descripcion_mercancia'] ?? '') === '') {
            throw new ValidationException(['descripcion_mercancia' => 'Describe la mercancía entregada.']);
        }
        return Database::transaction(function () use ($pagoSemanalId, $data, $userId, $montoC): int {
            $peek = $this->db->prepare('SELECT transportista_id FROM log_pagos_semanales WHERE id = :id');
            $peek->execute(['id' => $pagoSemanalId]);
            $trId = (int) $peek->fetchColumn();
            if ($trId < 1) {
                throw new ValidationException(['pago_id' => 'El cierre no existe.']);
            }
            $this->bloquearTransportista($trId);
            $this->bloquearCierre($pagoSemanalId);
            $this->db->prepare(
                'INSERT INTO log_pagos_transporte
                    (pago_semanal_id, fecha, monto, descripcion_mercancia, tipo, registrado_por)
                 VALUES (:cierre, :fecha, :monto, :desc, :tipo, :usuario)'
            )->execute([
                'cierre'  => $pagoSemanalId,
                'fecha'   => $data['fecha'],
                'monto'   => Caja::desdeCentavos($montoC),
                'desc'    => $data['descripcion_mercancia'],
                'tipo'    => $data['tipo'],
                'usuario' => $userId,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->actualizarEstadoCierre($pagoSemanalId);
            audit_log('transportistas', 'pago', [
                'cierre_id' => $pagoSemanalId,
                'tipo'      => $data['tipo'],
                'monto'     => Caja::desdeCentavos($montoC),
            ], 'log_pagos_transporte', $id);
            return $id;
        });
    }

    /**
     * @return array{
     *   pago:array<string, mixed>,
     *   transportista:array<string, mixed>,
     *   fletes:list<array<string, mixed>>,
     *   rutas:list<array<string, mixed>>,
     *   adelantos:list<array<string, mixed>>,
     *   bruto:string, pagado:string, neto:string
     * }
     */
    public function detallePago(int $pagoId): array
    {
        $pago = $this->db->prepare(
            'SELECT id, transportista_id, fecha_inicio, fecha_fin, total_litros, total_pagar, estado
             FROM log_pagos_semanales WHERE id = :id'
        );
        $pago->execute(['id' => $pagoId]);
        $p = $pago->fetch();
        if ($p === false) {
            throw new ValidationException(['pago_id' => 'El cierre no existe.']);
        }
        $tr = $this->db->prepare(
            'SELECT id, documento, nombre, telefono, modalidad, estado
             FROM log_transportistas WHERE id = :id'
        );
        $tr->execute(['id' => $p['transportista_id']]);
        $transportista = $tr->fetch() ?: [];
        if ($transportista !== []) {
            $transportista['vehiculos'] = $this->vehiculosDe((int) $transportista['id']);
        }
        $fletes = $this->db->prepare(
            'SELECT f.fecha, f.litros_recolectados, f.costo_flete, r.nombre_zona, r.precio_viaje, r.vehiculos_asignados
             FROM log_fletes_diarios f
             JOIN log_rutas r ON r.id = f.ruta_id
             WHERE f.pago_id = :id
             ORDER BY f.fecha, f.id'
        );
        $fletes->execute(['id' => $pagoId]);
        $filasFlete = $fletes->fetchAll();
        $adelantos = $this->db->prepare(
            'SELECT id, fecha, monto, descripcion_mercancia, tipo
             FROM log_pagos_transporte
             WHERE pago_semanal_id = :id
             ORDER BY fecha, id'
        ); // ix_log_pt_pago (pago_semanal_id, fecha)
        $adelantos->execute(['id' => $pagoId]);
        $filasPago = $adelantos->fetchAll();

        $rutas = [];
        foreach ($filasFlete as $f) {
            $zona = (string) $f['nombre_zona'];
            if (!isset($rutas[$zona])) {
                $rutas[$zona] = [
                    'nombre_zona' => $zona,
                    'precio_viaje'        => (string) $f['precio_viaje'],
                    'vehiculos_asignados' => (int) ($f['vehiculos_asignados'] ?? 1),
                    'costo_semanal'       => Caja::desdeCentavos(
                        self::costoSemanalCentavos((string) $f['precio_viaje'], (int) ($f['vehiculos_asignados'] ?? 1))
                    ),
                    'litros' => '0',
                ];
            }
            $rutas[$zona]['litros'] = Caja::desdeCentavos(
                Caja::aCentavos($rutas[$zona]['litros']) + Caja::aCentavos($f['litros_recolectados'])
            );
        }

        $pagadoC = 0;
        foreach ($filasPago as $a) {
            $pagadoC += Caja::aCentavos($a['monto']);
        }
        $brutoC = Caja::aCentavos($p['total_pagar']);
        $netoC = $brutoC - $pagadoC;

        return [
            'pago'          => $p,
            'transportista' => $transportista,
            'fletes'        => $filasFlete,
            'rutas'         => array_values($rutas),
            'adelantos'     => $filasPago,
            'bruto'         => Caja::desdeCentavos($brutoC),
            'pagado'        => Caja::desdeCentavos($pagadoC),
            'neto'          => Caja::desdeCentavos($netoC),
        ];
    }

    /** @return array<string, mixed> */
    public function transportista(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, documento, nombre, telefono, modalidad, estado
             FROM log_transportistas WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['id' => 'El transportista no existe.']);
        }
        $row['vehiculos'] = $this->vehiculosDe($id);
        $row['rutas'] = $this->rutas($id);
        $row['fletes'] = $this->fletes($id);
        $row['pagos'] = $this->pagos($id);
        return $row;
    }

    private function litrosRutaEnFecha(int $rutaId, string $fecha): string
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(e.litros), 0)
             FROM log_ruta_productores rp
             JOIN productores_entregas e ON e.productor_id = rp.productor_id AND e.fecha = :fecha
             WHERE rp.ruta_id = :ruta'
        );
        $stmt->execute(['ruta' => $rutaId, 'fecha' => $fecha]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? '0' : (string) $v;
    }

    private function asegurarDocumentoUnico(string $documento, ?int $excluirId): void
    {
        $sql = 'SELECT id FROM log_transportistas WHERE documento = :d';
        $params = ['d' => $documento];
        if ($excluirId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excluirId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch() !== false) {
            throw new ValidationException(['documento' => 'Ya existe un transportista con ese documento.']);
        }
    }

    private function actualizarEstadoCierre(int $pagoId): void
    {
        $row = $this->bloquearCierre($pagoId);
        $pagado = $this->db->prepare(
            'SELECT COALESCE(SUM(monto), 0) FROM log_pagos_transporte WHERE pago_semanal_id = :id'
        );
        $pagado->execute(['id' => $pagoId]);
        $pagadoC = Caja::aCentavos((string) $pagado->fetchColumn());
        $brutoC = Caja::aCentavos((string) $row['total_pagar']);
        $estado = $pagadoC >= $brutoC && $brutoC > 0 ? 'pagado' : 'pendiente';
        $this->db->prepare('UPDATE log_pagos_semanales SET estado = :e WHERE id = :id')
            ->execute(['e' => $estado, 'id' => $pagoId]);
    }

    /** @return array<string, mixed> */
    private function bloquearCierre(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM log_pagos_semanales WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['pago_id' => 'El cierre no existe.']);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function bloquearTransportista(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM log_transportistas WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['transportista_id' => 'El transportista no existe.']);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function bloquearRuta(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM log_rutas WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['ruta_id' => 'La ruta no existe.']);
        }
        return $row;
    }

    /** @param list<int> $ids */
    private function validarProductores(array $ids): void
    {
        $in = implode(',', array_map('intval', $ids));
        $n = (int) $this->db->query("SELECT COUNT(*) FROM productores WHERE activo = 1 AND id IN ($in)")->fetchColumn();
        if ($n !== count($ids)) {
            throw new ValidationException(['productores' => 'Hay productores inválidos o inactivos.']);
        }
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function vehiculosPorTransportista(): array
    {
        $rows = $this->db->query(
            'SELECT id, transportista_id, tipo_vehiculo, marca, modelo, color, placa, limite_carga_kg
             FROM log_transportista_vehiculos
             ORDER BY transportista_id, id'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['transportista_id']][] = $row;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function vehiculosDe(int $transportistaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, transportista_id, tipo_vehiculo, marca, modelo, color, placa, limite_carga_kg
             FROM log_transportista_vehiculos
             WHERE transportista_id = :t
             ORDER BY id'
        );
        $stmt->execute(['t' => $transportistaId]);
        return $stmt->fetchAll();
    }

    /** @return array{n:int, capacidad_total:float, capacidad_max:float} */
    private function flotillaDe(int $transportistaId): array
    {
        return $this->resumenFlotilla($this->vehiculosDe($transportistaId));
    }

    /**
     * @param list<array<string, mixed>> $vehiculos
     * @return array{n:int, capacidad_total:float, capacidad_max:float}
     */
    private function resumenFlotilla(array $vehiculos): array
    {
        $total = 0.0;
        $max = 0.0;
        foreach ($vehiculos as $v) {
            $lim = (float) ($v['limite_carga_kg'] ?? 0);
            $total += $lim;
            if ($lim > $max) {
                $max = $lim;
            }
        }
        return [
            'n'               => count($vehiculos),
            'capacidad_total' => $total,
            'capacidad_max'   => $max,
        ];
    }

    /**
     * @param list<array<string, mixed>> $vehiculos
     * @return list<array{tipo_vehiculo:string, marca:?string, modelo:?string, color:?string, placa:string, limite_carga_kg:string}>
     */
    private function normalizarVehiculos(array $vehiculos): array
    {
        if ($vehiculos === []) {
            throw new ValidationException(['vehiculos' => 'Registra al menos un vehículo.']);
        }
        if (count($vehiculos) > 99) {
            throw new ValidationException(['vehiculos' => 'Máximo 99 vehículos por cooperativa.']);
        }
        $out = [];
        $placas = [];
        foreach ($vehiculos as $i => $v) {
            if (!is_array($v)) {
                throw new ValidationException(['vehiculos' => 'Vehículo inválido.']);
            }
            $tipo = (string) ($v['tipo_vehiculo'] ?? 'otro');
            if (!isset(self::VEHICULOS[$tipo])) {
                throw new ValidationException(["vehiculos.$i.tipo_vehiculo" => 'Tipo de vehículo inválido.']);
            }
            $placa = mb_strtoupper(preg_replace('/\s+/u', '', (string) ($v['placa'] ?? '')));
            if (mb_strlen($placa) < 3 || mb_strlen($placa) > 15) {
                throw new ValidationException(["vehiculos.$i.placa" => 'La placa es obligatoria (3 a 15 caracteres).']);
            }
            if (isset($placas[$placa])) {
                throw new ValidationException(['vehiculos' => 'Hay placas repetidas en el formulario.']);
            }
            $placas[$placa] = true;
            $limite = parse_monto((string) ($v['limite_carga_kg'] ?? ''), 2, 999999.99);
            if ($limite === null) {
                throw new ValidationException(["vehiculos.$i.limite_carga_kg" => 'Indica el límite de carga en kg.']);
            }
            $marca = preg_replace('/\s+/u', ' ', trim((string) ($v['marca'] ?? '')));
            $modelo = preg_replace('/\s+/u', ' ', trim((string) ($v['modelo'] ?? '')));
            $color = preg_replace('/\s+/u', ' ', trim((string) ($v['color'] ?? '')));
            $out[] = [
                'tipo_vehiculo'   => $tipo,
                'marca'           => $marca === '' ? null : mb_substr($marca, 0, 60),
                'modelo'          => $modelo === '' ? null : mb_substr($modelo, 0, 60),
                'color'           => $color === '' ? null : mb_substr($color, 0, 40),
                'placa'           => $placa,
                'limite_carga_kg' => $limite,
            ];
        }
        return $out;
    }

    /** @param list<array{tipo_vehiculo:string, marca:?string, modelo:?string, color:?string, placa:string, limite_carga_kg:string}> $vehiculos */
    private function reemplazarVehiculos(int $transportistaId, array $vehiculos): void
    {
        $lock = $this->db->prepare(
            'SELECT id FROM log_transportista_vehiculos WHERE transportista_id = :t ORDER BY id ASC FOR UPDATE'
        );
        $lock->execute(['t' => $transportistaId]);
        $lock->fetchAll();
        $this->db->prepare('DELETE FROM log_transportista_vehiculos WHERE transportista_id = :t')
            ->execute(['t' => $transportistaId]);
        $ins = $this->db->prepare(
            'INSERT INTO log_transportista_vehiculos
                (transportista_id, tipo_vehiculo, marca, modelo, color, placa, limite_carga_kg)
             VALUES (:t, :tipo, :marca, :modelo, :color, :placa, :limite)'
        );
        foreach ($vehiculos as $v) {
            $ins->execute([
                't'      => $transportistaId,
                'tipo'   => $v['tipo_vehiculo'],
                'marca'  => $v['marca'],
                'modelo' => $v['modelo'],
                'color'  => $v['color'],
                'placa'  => $v['placa'],
                'limite' => $v['limite_carga_kg'],
            ]);
        }
    }

    public static function costoSemanalCentavos(string $precioVehiculo, int $vehiculos): int
    {
        return Caja::aCentavos($precioVehiculo) * max(1, $vehiculos);
    }

    public static function costoDiarioCentavos(string $precioVehiculo, int $vehiculos): int
    {
        return (int) round(self::costoSemanalCentavos($precioVehiculo, $vehiculos) / 7);
    }

    /**
     * Saldo CxP por transportista (cierres semanales vs abonos).
     * @return array{filas:list<array<string, mixed>>, totales:array{bruto:string, pagado:string, deuda:string, litros:string}}
     */
    public function reporteCxp(): array
    {
        $rows = $this->db->query(
            'SELECT t.id, t.nombre, t.documento, t.telefono, t.modalidad, t.estado,
                    COALESCE(c.bruto, 0) AS bruto,
                    COALESCE(c.pagado, 0) AS pagado,
                    COALESCE(c.litros, 0) AS litros
             FROM log_transportistas t
             LEFT JOIN (
                 SELECT p.transportista_id,
                        SUM(p.total_pagar) AS bruto,
                        SUM(p.total_litros) AS litros,
                        SUM(COALESCE(a.pagado, 0)) AS pagado
                 FROM log_pagos_semanales p
                 LEFT JOIN (
                     SELECT pago_semanal_id, SUM(monto) AS pagado
                     FROM log_pagos_transporte
                     GROUP BY pago_semanal_id
                 ) a ON a.pago_semanal_id = p.id
                 GROUP BY p.transportista_id
             ) c ON c.transportista_id = t.id
             ORDER BY t.nombre'
        )->fetchAll();
        $filas = [];
        $brutoC = 0;
        $pagadoC = 0;
        $deudaC = 0;
        $litrosC = 0;
        foreach ($rows as $r) {
            $b = Caja::aCentavos($r['bruto']);
            $p = Caja::aCentavos($r['pagado']);
            $d = max(0, $b - $p);
            $l = Caja::aCentavos($r['litros']);
            $brutoC += $b;
            $pagadoC += $p;
            $deudaC += $d;
            $litrosC += $l;
            $filas[] = [
                'id'         => (int) $r['id'],
                'nombre'     => (string) $r['nombre'],
                'documento'  => (string) ($r['documento'] ?? ''),
                'telefono'   => (string) ($r['telefono'] ?? ''),
                'modalidad'  => (string) ($r['modalidad'] ?? 'individual'),
                'estado'     => (string) $r['estado'],
                'bruto'      => Caja::desdeCentavos($b),
                'pagado'     => Caja::desdeCentavos($p),
                'deuda'      => Caja::desdeCentavos($d),
                'litros'     => Caja::desdeCentavos($l),
            ];
        }
        return [
            'filas'   => $filas,
            'totales' => [
                'bruto'  => Caja::desdeCentavos($brutoC),
                'pagado' => Caja::desdeCentavos($pagadoC),
                'deuda'  => Caja::desdeCentavos($deudaC),
                'litros' => Caja::desdeCentavos($litrosC),
            ],
        ];
    }
}
