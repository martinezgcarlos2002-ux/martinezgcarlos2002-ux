<?php
declare(strict_types=1);

/**
 * Gestión de gas: proveedores, bombonas, despachos y abonos.
 * El saldo, el estado de la deuda y la foto del dueño los cierran los triggers de 040_modulo_gas.sql.
 */
final class Gas
{
    public const FISICO = [
        'optimo'     => 'Óptimas condiciones',
        'regular'    => 'Regular',
        'mal_estado' => 'Mal estado',
    ];
    public const ESTADOS_PROVEEDOR = ['activo', 'inactivo', 'eliminado'];
    public const METODOS = [
        'efectivo'      => 'Efectivo',
        'pago_movil'    => 'Pago móvil',
        'transferencia' => 'Transferencia',
        'otro'          => 'Otro',
    ];

    /** Mensaje del trigger (sin tildes) → texto para la pantalla. */
    private const SENALES = [
        'El abono supera el saldo del despacho' => 'El abono supera el saldo pendiente de este despacho.',
        'El despacho no tiene bombonas que cobrar' => 'Este despacho todavía no tiene bombonas cargadas.',
        'El despacho ya tiene abonos: anulelos antes de corregirlo' => 'Este despacho ya tiene abonos. Anúlalos antes de cambiar el precio, el proveedor o la fecha de salida.',
        'El despacho ya tiene abonos' => 'Este despacho ya tiene abonos. No se pueden agregar bombonas.',
        'El despacho ya fue entregado' => 'El despacho ya fue entregado. No se agregan bombonas.',
        'No se quitan bombonas de un despacho entregado o con abonos' => 'No se quitan bombonas de un despacho entregado o con abonos.',
        'La bombona ya esta en un despacho sin entregar' => 'Esa bombona ya está en un despacho que no ha regresado.',
        'La bombona esta en reparacion' => 'Esa bombona está en reparación y no puede salir en un viaje.',
        'La bombona esta en un despacho sin entregar' => 'Esa bombona está en un viaje que todavía no regresa.',
        'Solo se repara una bombona en estado regular o mal estado' => 'Solo se envía a reparación una bombona en estado regular o mal estado.',
        'La reparacion nace en curso, sin costo ni retorno' => 'La reparación se abre sin costo. El costo se registra al marcarla reparada.',
        'La bombona no tiene una capacidad de 10, 15, 27 o 43 kg' => 'La bombona no tiene una capacidad de 10, 15, 27 o 43 kg.',
        'La bombona esta archivada' => 'La bombona está archivada.',
        'El proveedor de gas no esta activo' => 'El proveedor no está activo.',
        'El proveedor no se elimina: paselo a estado eliminado' => 'El proveedor no se borra: archívalo para conservar los viajes.',
        'La bombona no se elimina: archivela para conservar los viajes' => 'La bombona no se borra: archívala para conservar los viajes.',
        'El serial de la bombona no se modifica' => 'El serial de la bombona no se modifica.',
        'No se archiva una bombona que esta en un viaje abierto' => 'No se archiva una bombona que está en un viaje abierto.',
        'Solo se borra un despacho vacio y sin entrega' => 'Solo se puede quitar un viaje vacío, sin bombonas y sin abonos.',
        'Solo se borra un despacho vacio, sin abonos' => 'Solo se puede quitar un viaje vacío, sin bombonas y sin abonos.',
        'Los abonos de gas no se eliminan: se anulan' => 'Los abonos no se eliminan: se anulan.',
        'El abono ya esta anulado' => 'Ese abono ya está anulado.',
        'Los datos economicos del abono son inmutables' => 'Los datos del abono no se modifican. Si hubo un error, anúlalo.',
        'El cargo al tercero ya fue aplicado' => 'Ese cargo al tercero ya fue aplicado.',
        'El precio se edita en el despacho, no en la linea' => 'El precio se edita en el despacho, no en cada bombona.',
    ];

    private const REGLAS = [
        'ck_gas_pago_referencia' => 'Pago móvil y transferencia exigen la referencia de la operación.',
        'ck_gas_pago_cuenta'     => 'El efectivo sale de caja. Pago móvil y transferencia salen del banco.',
        'ck_gas_pago_montos'     => 'El monto y la tasa tienen que ser mayores que cero.',
        'ck_gas_pago_usd'        => 'El monto en dólares no cuadra con la tasa del día.',
        'ck_gas_pago_anulacion'  => 'Para anular indica quién, cuándo y el motivo.',
        'ck_gas_despacho_precio' => 'El precio por bombona tiene que ser mayor que cero.',
        'ck_gas_despacho_fechas' => 'La fecha de entrega no puede ser anterior a la salida.',
        'ck_gas_cil_dueno'       => 'Revisa el propietario: una bombona de un tercero necesita su dueño.',
        'ck_gas_prov_documento'  => 'El RIF debe tener la forma J-12345678-9.',
        'ck_gas_prov_nombre'     => 'El nombre del proveedor es demasiado corto.',
        'uq_gas_prov_nombre'     => 'Ya existe un proveedor con ese nombre.',
        'uq_gas_prov_documento'  => 'Ese RIF ya está registrado.',
        'uq_gas_cil_serial'      => 'Ese serial ya está registrado.',
        'uq_gas_dc_despacho_cil' => 'Esa bombona ya está en el despacho.',
    ];

    public function __construct(private PDO $db)
    {
    }

    public static function mensajePdo(PDOException $e): ?string
    {
        $signal = trigger_error_message($e);
        if ($signal !== null && $signal !== '') {
            return self::SENALES[$signal] ?? $signal;
        }
        $dup = duplicate_key($e);
        if ($dup !== null && isset(self::REGLAS[$dup])) {
            return self::REGLAS[$dup];
        }
        $code = (int) ($e->errorInfo[1] ?? 0);
        if ($code === 3819 || $code === 4025) {
            $sql = (string) ($e->errorInfo[2] ?? '');
            foreach (self::REGLAS as $nombre => $texto) {
                if (str_contains($sql, $nombre)) {
                    return $texto;
                }
            }
            return 'Los datos no cumplen una regla del módulo de gas.';
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function resumen(): array
    {
        $inicio = self::sabadoSemana();
        $fin = $inicio->modify('+6 days');
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(despachos), 0) AS despachos,
                    COALESCE(SUM(cilindros), 0) AS cilindros,
                    COALESCE(SUM(invertido_usd), 0) AS invertido_usd,
                    COALESCE(SUM(pagado_usd), 0) AS pagado_usd,
                    COALESCE(SUM(saldo_usd), 0) AS saldo_usd
             FROM v_gas_inversion_semanal
             WHERE semana_inicio = :semana'
        );
        $stmt->execute(['semana' => $inicio->format('Y-m-d')]);
        $semana = $stmt->fetch() ?: [];

        $semanas = $this->db->query(
            'SELECT semana_inicio, semana_fin, proveedor_id, proveedor, despachos, cilindros,
                    invertido_usd, pagado_usd, saldo_usd
             FROM v_gas_inversion_semanal
             ORDER BY semana_inicio DESC, proveedor
             LIMIT 16'
        )->fetchAll();

        $alertas = $this->db->query(
            'SELECT c.id, c.serial, c.observacion_fisica, c.pertenencia, c.tipo_tercero, ' . self::DUENO . '
             FROM gas_cilindros c
             ' . self::JOIN_DUENO . '
             WHERE c.estado_fisico = \'mal_estado\' AND c.estado_registro = \'activo\'
             ORDER BY c.serial
             LIMIT 100'
        )->fetchAll();

        $cargos = (int) $this->db->query(
            "SELECT COUNT(*) FROM gas_despacho_cilindros WHERE cargo_estado = 'pendiente'"
        )->fetchColumn();

        $inventario = [];
        foreach ([10, 15, 27, 43] as $kg) {
            $inventario[$kg] = ['optimo' => 0, 'regular' => 0, 'mal_estado' => 0, 'total' => 0];
        }
        $conteo = $this->db->query(
            "SELECT capacidad_kg, estado_fisico, COUNT(*) AS n
             FROM gas_cilindros
             WHERE estado_registro = 'activo'
             GROUP BY capacidad_kg, estado_fisico"
        )->fetchAll();
        foreach ($conteo as $fila) {
            $kg = (int) $fila['capacidad_kg'];
            $fisico = (string) $fila['estado_fisico'];
            if (!isset($inventario[$kg]) || !isset($inventario[$kg][$fisico])) {
                continue;
            }
            $inventario[$kg][$fisico] = (int) $fila['n'];
            $inventario[$kg]['total'] += (int) $fila['n'];
        }

        return [
            'semana_inicio' => $inicio->format('Y-m-d'),
            'semana_fin'    => $fin->format('Y-m-d'),
            'semana'        => $semana,
            'semanas'       => $semanas,
            'alertas'       => $alertas,
            'inventario'    => $inventario,
            'cargos_pendientes' => $cargos,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function proveedores(): array
    {
        return $this->db->query(
            'SELECT id, documento, nombre, contacto, telefono, correo, direccion, notas, estado
             FROM gas_proveedores
             ORDER BY estado = \'eliminado\', nombre
             LIMIT 500'
        )->fetchAll();
    }

    public function guardarProveedor(?int $id, array $in, int $userId): int
    {
        $nombre = self::requerido($in['nombre'] ?? '', 120, 'nombre', 'Indica el nombre del proveedor.');
        $documento = self::opcional($in['documento'] ?? '', 12);
        if ($documento !== null && !preg_match('/^[VEJGP]-[0-9]{6,9}(-[0-9])?$/', $documento)) {
            throw new ValidationException(['documento' => 'El RIF debe tener la forma J-12345678-9.']);
        }
        $datos = [
            'documento' => $documento,
            'nombre'    => $nombre,
            'contacto'  => self::opcional($in['contacto'] ?? '', 80),
            'telefono'  => self::opcional($in['telefono'] ?? '', 20),
            'correo'    => self::opcional($in['correo'] ?? '', 100),
            'direccion' => self::opcional($in['direccion'] ?? '', 160),
            'notas'     => self::opcional($in['notas'] ?? '', 255),
        ];

        if ($id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO gas_proveedores
                    (documento, nombre, contacto, telefono, correo, direccion, notas, registrado_por)
                 VALUES
                    (:documento, :nombre, :contacto, :telefono, :correo, :direccion, :notas, :registrado_por)'
            );
            $stmt->execute($datos + ['registrado_por' => $userId]);
            return (int) $this->db->lastInsertId();
        }

        $estado = (string) ($in['estado'] ?? 'activo');
        if (!in_array($estado, self::ESTADOS_PROVEEDOR, true)) {
            throw new ValidationException(['estado' => 'Estado de proveedor no válido.']);
        }
        $stmt = $this->db->prepare(
            'UPDATE gas_proveedores
             SET documento = :documento, nombre = :nombre, contacto = :contacto, telefono = :telefono,
                 correo = :correo, direccion = :direccion, notas = :notas, estado = :estado
             WHERE id = :id'
        );
        $stmt->execute($datos + ['estado' => $estado, 'id' => $id]);
        if ($stmt->rowCount() === 0 && !$this->existe('gas_proveedores', $id)) {
            throw new ValidationException(['general' => 'Ese proveedor no existe.']);
        }
        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function cilindros(): array
    {
        return $this->db->query(
            'SELECT c.id, c.serial, c.capacidad_kg, c.estado_fisico, c.observacion_fisica, c.pertenencia, c.tipo_tercero,
                    c.empleado_id, c.transportista_id, c.productor_id, c.propietario_nombre,
                    c.despacho_abierto_id, c.estado_registro, ' . self::DUENO . '
             FROM gas_cilindros c
             ' . self::JOIN_DUENO . '
             ORDER BY c.estado_registro, c.serial
             LIMIT 1000'
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function cilindrosDisponibles(): array
    {
        return $this->db->query(
            'SELECT c.id, c.serial, c.capacidad_kg, c.estado_fisico, c.pertenencia, c.tipo_tercero, ' . self::DUENO . '
             FROM gas_cilindros c
             ' . self::JOIN_DUENO . "
             WHERE c.estado_registro = 'activo' AND c.despacho_abierto_id IS NULL
             ORDER BY c.serial
             LIMIT 1000"
        )->fetchAll();
    }

    /** @return array{empleados:list<array>, transportistas:list<array>, productores:list<array>, proveedores:list<array>} */
    public function catalogo(): array
    {
        return [
            'empleados' => $this->db->query(
                "SELECT id, nombres, apellidos FROM empleados WHERE activo = 1 ORDER BY apellidos, nombres LIMIT 500"
            )->fetchAll(),
            'transportistas' => $this->db->query(
                "SELECT id, nombre FROM log_transportistas WHERE estado = 'activo' ORDER BY nombre LIMIT 500"
            )->fetchAll(),
            'productores' => $this->db->query(
                'SELECT id, nombre FROM productores WHERE activo = 1 ORDER BY nombre LIMIT 500'
            )->fetchAll(),
            'proveedores' => $this->db->query(
                "SELECT id, nombre FROM gas_proveedores WHERE estado = 'activo' ORDER BY nombre LIMIT 500"
            )->fetchAll(),
        ];
    }

    public function guardarCilindro(?int $id, array $in, int $userId): int
    {
        $fisico = (string) ($in['estado_fisico'] ?? 'optimo');
        if (!isset(self::FISICO[$fisico])) {
            throw new ValidationException(['estado_fisico' => 'Indica el estado físico de la bombona.']);
        }
        $capacidad = (int) ($in['capacidad_kg'] ?? 0);
        if (!in_array($capacidad, [10, 15, 27, 43], true)) {
            throw new ValidationException(['capacidad_kg' => 'Selecciona la capacidad: 10, 15, 27 o 43 kg.']);
        }
        $dueno = $this->dueno($in);
        $registro = (string) ($in['estado_registro'] ?? 'activo');
        if (!in_array($registro, ['activo', 'archivado'], true)) {
            throw new ValidationException(['estado_registro' => 'Estado de archivo no válido.']);
        }
        $obs = self::opcional($in['observacion_fisica'] ?? '', 255);
        $params = $dueno + [
            'capacidad_kg'       => $capacidad,
            'estado_fisico'      => $fisico,
            'observacion_fisica' => $obs,
            'estado_registro'    => $registro,
        ];

        if ($id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO gas_cilindros
                    (capacidad_kg, estado_fisico, observacion_fisica, pertenencia, tipo_tercero,
                     empleado_id, transportista_id, productor_id, propietario_nombre, estado_registro, registrado_por)
                 VALUES
                    (:capacidad_kg, :estado_fisico, :observacion_fisica, :pertenencia, :tipo_tercero,
                     :empleado_id, :transportista_id, :productor_id, :propietario_nombre, :estado_registro, :registrado_por)'
            );
            $stmt->execute($params + ['registrado_por' => $userId]);
            return (int) $this->db->lastInsertId();
        }

        $params['id'] = $id;
        $stmt = $this->db->prepare(
            'UPDATE gas_cilindros
             SET capacidad_kg = :capacidad_kg, estado_fisico = :estado_fisico, observacion_fisica = :observacion_fisica,
                 pertenencia = :pertenencia, tipo_tercero = :tipo_tercero,
                 empleado_id = :empleado_id, transportista_id = :transportista_id,
                 productor_id = :productor_id, propietario_nombre = :propietario_nombre,
                 estado_registro = :estado_registro
             WHERE id = :id'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() === 0 && !$this->existe('gas_cilindros', $id)) {
            throw new ValidationException(['general' => 'Esa bombona no existe.']);
        }
        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function despachos(): array
    {
        return $this->db->query(
            'SELECT d.id, d.codigo_despacho, d.proveedor_id, p.nombre AS proveedor, d.fecha_salida, d.fecha_entrega,
                    d.semana_inicio, d.precio_10_usd, d.precio_15_usd, d.precio_27_usd, d.precio_43_usd,
                    d.cantidad, d.costo_total_usd, d.pagado_usd, d.saldo_usd, d.estado_deuda
             FROM gas_despachos d
             JOIN gas_proveedores p ON p.id = d.proveedor_id
             ORDER BY d.fecha_salida DESC, d.id DESC
             LIMIT 300'
        )->fetchAll();
    }

    /** @return array{despacho:array, lineas:list<array>, pagos:list<array>} */
    public function despacho(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.id, d.codigo_despacho, d.proveedor_id, p.nombre AS proveedor, p.estado AS proveedor_estado,
                    d.fecha_salida, d.fecha_entrega, d.semana_inicio,
                    d.precio_10_usd, d.precio_15_usd, d.precio_27_usd, d.precio_43_usd,
                    d.cantidad, d.costo_total_usd, d.pagado_usd, d.saldo_usd, d.estado_deuda,
                    d.observaciones
             FROM gas_despachos d
             JOIN gas_proveedores p ON p.id = d.proveedor_id
             WHERE d.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['general' => 'Ese despacho no existe.']);
        }
        $lineas = $this->db->prepare(
            'SELECT dc.id, dc.cilindro_id, c.serial, dc.capacidad_kg, dc.pertenencia, dc.tipo_tercero, dc.propietario_nombre,
                    dc.estado_fisico, dc.precio_unitario_usd, dc.subtotal_usd, dc.cargo_estado
             FROM gas_despacho_cilindros dc
             JOIN gas_cilindros c ON c.id = dc.cilindro_id
             WHERE dc.despacho_id = :id
             ORDER BY c.serial'
        );
        $lineas->execute(['id' => $id]);
        $pagos = $this->db->prepare(
            'SELECT g.id, g.fecha, g.metodo, g.cuenta, g.moneda, g.monto, g.tasa_bs_por_usd, g.monto_usd,
                    g.referencia, g.anulado_en, g.motivo_anulacion, u.nombre AS registrado_por
             FROM gas_pagos g
             JOIN usuarios u ON u.id = g.registrado_por
             WHERE g.despacho_id = :id
             ORDER BY g.id'
        );
        $pagos->execute(['id' => $id]);
        return ['despacho' => $row, 'lineas' => $lineas->fetchAll(), 'pagos' => $pagos->fetchAll()];
    }

    public function crearDespacho(array $in, int $userId): int
    {
        $proveedorId = self::entero($in['proveedor_id'] ?? '', 'proveedor_id', 'Selecciona el proveedor.');
        $salida = self::fecha($in['fecha_salida'] ?? '', 'fecha_salida', 'Indica la fecha de salida.');
        $precios = self::preciosCapacidad($in);
        $stmt = $this->db->prepare(
            'INSERT INTO gas_despachos
                (proveedor_id, fecha_salida, precio_10_usd, precio_15_usd, precio_27_usd, precio_43_usd, observaciones, registrado_por)
             VALUES
                (:proveedor_id, :fecha_salida, :p10, :p15, :p27, :p43, :observaciones, :registrado_por)'
        );
        $stmt->execute([
            'proveedor_id'   => $proveedorId,
            'fecha_salida'   => $salida,
            'p10'            => $precios[10],
            'p15'            => $precios[15],
            'p27'            => $precios[27],
            'p43'            => $precios[43],
            'observaciones'  => self::opcional($in['observaciones'] ?? '', 255),
            'registrado_por' => $userId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function editarDespacho(int $id, array $in): void
    {
        $this->despacho($id);
        $sets = [];
        $params = ['id' => $id];
        foreach ([10, 15, 27, 43] as $kg) {
            $clave = 'precio_' . $kg;
            if (array_key_exists($clave, $in) && (string) $in[$clave] !== '') {
                $sets[] = 'precio_' . $kg . '_usd = :p' . $kg;
                $params['p' . $kg] = self::money($in[$clave], $clave, 'Indica el precio de ' . $kg . ' kg.', false);
            }
        }
        if (array_key_exists('fecha_salida', $in) && (string) $in['fecha_salida'] !== '') {
            $sets[] = 'fecha_salida = :fecha_salida';
            $params['fecha_salida'] = self::fecha($in['fecha_salida'], 'fecha_salida', 'Fecha de salida inválida.');
        }
        if (array_key_exists('fecha_entrega', $in)) {
            $sets[] = 'fecha_entrega = :fecha_entrega';
            $params['fecha_entrega'] = self::fechaOpcional($in['fecha_entrega'], 'fecha_entrega');
        }
        if (array_key_exists('observaciones', $in)) {
            $sets[] = 'observaciones = :observaciones';
            $params['observaciones'] = self::opcional($in['observaciones'], 255);
        }
        if (array_key_exists('proveedor_id', $in) && (string) $in['proveedor_id'] !== '') {
            $sets[] = 'proveedor_id = :proveedor_id';
            $params['proveedor_id'] = self::entero($in['proveedor_id'], 'proveedor_id', 'Selecciona el proveedor.');
        }
        if ($sets === []) {
            throw new ValidationException(['general' => 'No hay cambios para guardar.']);
        }
        $stmt = $this->db->prepare('UPDATE gas_despachos SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function eliminarDespacho(int $id): void
    {
        $despacho = $this->despacho($id)['despacho'];
        if ((int) $despacho['cantidad'] > 0 || (float) $despacho['pagado_usd'] > 0) {
            throw new ValidationException(['general' => 'Solo se puede quitar un viaje vacío, sin bombonas y sin abonos.']);
        }
        $stmt = $this->db->prepare('DELETE FROM gas_despachos WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** @param list<int|string> $ids */
    public function agregarCilindros(int $despachoId, array $ids): int
    {
        $limpios = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $limpios[$n] = $n;
            }
        }
        $limpios = array_values($limpios);
        if ($limpios === []) {
            throw new ValidationException(['cilindros' => 'Selecciona al menos una bombona.']);
        }
        if (count($limpios) > 300) {
            throw new ValidationException(['cilindros' => 'Carga como máximo 300 bombonas a la vez.']);
        }
        $this->despacho($despachoId);
        $stmt = $this->db->prepare(
            'INSERT INTO gas_despacho_cilindros (despacho_id, cilindro_id) VALUES (:despacho_id, :cilindro_id)'
        );
        foreach ($limpios as $cilindroId) {
            $stmt->execute(['despacho_id' => $despachoId, 'cilindro_id' => $cilindroId]);
        }
        return count($limpios);
    }

    public function quitarCilindro(int $despachoId, int $cilindroId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM gas_despacho_cilindros WHERE despacho_id = :despacho_id AND cilindro_id = :cilindro_id'
        );
        $stmt->execute(['despacho_id' => $despachoId, 'cilindro_id' => $cilindroId]);
        if ($stmt->rowCount() !== 1) {
            throw new ValidationException(['general' => 'Esa bombona no está en el despacho.']);
        }
    }

    /** @return array{id:int, monto_usd:string, despacho:array} */
    public function registrarPago(array $in, int $userId): array
    {
        $despachoId = self::entero($in['despacho_id'] ?? '', 'despacho_id', 'Falta el despacho.');
        $fecha = self::fecha($in['fecha'] ?? '', 'fecha', 'Indica la fecha del abono.');
        $metodo = (string) ($in['metodo'] ?? '');
        if (!isset(self::METODOS[$metodo])) {
            throw new ValidationException(['metodo' => 'Selecciona el método de pago.']);
        }
        $moneda = (string) ($in['moneda'] ?? 'USD');
        if (!in_array($moneda, ['USD', 'VES'], true)) {
            throw new ValidationException(['moneda' => 'La moneda es USD o VES.']);
        }
        $monto = self::money($in['monto'] ?? '', 'monto', 'Indica el monto del abono.', false);
        $tasa = self::tasa($in['tasa_bs_por_usd'] ?? '', 'tasa_bs_por_usd');
        $referencia = self::opcional($in['referencia'] ?? '', 40);
        if (in_array($metodo, ['pago_movil', 'transferencia'], true) && $referencia === null) {
            throw new ValidationException(['referencia' => 'Indica la referencia de la operación.']);
        }
        $cuenta = $metodo === 'efectivo' ? 'caja' : ($metodo === 'otro'
            ? ((string) ($in['cuenta'] ?? 'banco') === 'caja' ? 'caja' : 'banco')
            : 'banco');
        $montoUsd = $moneda === 'USD' ? $monto : self::vesAUsd($monto, $tasa);

        return Database::transaction(function () use ($despachoId, $fecha, $metodo, $cuenta, $moneda, $monto, $tasa, $montoUsd, $referencia, $userId): array {
            $caja = new Caja($this->db);
            $caja->verificarFondos($cuenta, $moneda, $monto, 'monto');
            $stmt = $this->db->prepare(
                'INSERT INTO gas_pagos
                    (despacho_id, fecha, metodo, cuenta, moneda, monto, tasa_bs_por_usd, monto_usd, referencia, registrado_por)
                 VALUES
                    (:despacho_id, :fecha, :metodo, :cuenta, :moneda, :monto, :tasa, :monto_usd, :referencia, :registrado_por)'
            );
            $stmt->execute([
                'despacho_id'    => $despachoId,
                'fecha'          => $fecha,
                'metodo'         => $metodo,
                'cuenta'         => $cuenta,
                'moneda'         => $moneda,
                'monto'          => $monto,
                'tasa'           => $tasa,
                'monto_usd'      => $montoUsd,
                'referencia'     => $referencia,
                'registrado_por' => $userId,
            ]);
            $pagoId = (int) $this->db->lastInsertId();
            $caja->egresoPagoGas([
                'fecha'           => $fecha . ' ' . date('H:i:s'),
                'cuenta'          => $cuenta,
                'moneda'          => $moneda,
                'monto'           => $monto,
                'tasa_bs_por_usd' => $tasa,
                'monto_usd'       => $montoUsd,
                'concepto'        => 'Gas despacho #' . $despachoId,
                'gas_pago_id'     => $pagoId,
                'registrado_por'  => $userId,
            ]);
            $detalle = $this->despacho($despachoId);
            return ['id' => $pagoId, 'monto_usd' => $montoUsd, 'despacho' => $detalle];
        });
    }

    public function anularPago(int $pagoId, string $motivo, int $userId): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw new ValidationException(['motivo' => 'Indica el motivo de la anulación (mínimo 3 caracteres).']);
        }
        return Database::transaction(function () use ($pagoId, $motivo, $userId): array {
            $stmt = $this->db->prepare(
                'SELECT id, despacho_id, anulado_en FROM gas_pagos WHERE id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $pagoId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new ValidationException(['general' => 'Ese abono no existe.']);
            }
            if ($row['anulado_en'] !== null) {
                throw new ValidationException(['general' => 'Ese abono ya está anulado.']);
            }
            $upd = $this->db->prepare(
                'UPDATE gas_pagos
                 SET anulado_en = NOW(), anulado_por = :usuario, motivo_anulacion = :motivo
                 WHERE id = :id'
            );
            $upd->execute([
                'usuario' => $userId,
                'motivo'  => mb_substr($motivo, 0, 255),
                'id'      => $pagoId,
            ]);
            $caja = new Caja($this->db);
            $mov = $caja->movimientoDePagoGas($pagoId);
            if ($mov === null) {
                throw new ValidationException(['general' => 'El abono no tiene asiento en caja, así que no se anuló.']);
            }
            $caja->contraAsiento((int) $mov['id'], 'Anulación pago gas #' . $pagoId, $userId);
            return $this->despacho((int) $row['despacho_id']);
        });
    }

    /** @return list<array<string, mixed>> */
    public function reparaciones(): array
    {
        return $this->db->query(
            "SELECT r.id, r.cilindro_id, c.serial, c.capacidad_kg, c.estado_fisico, r.responsable,
                    r.fecha_salida, r.costo_usd, r.estado, r.fecha_retorno
             FROM gas_reparaciones r
             JOIN gas_cilindros c ON c.id = r.cilindro_id
             ORDER BY r.estado = 'reparado', r.fecha_salida DESC, r.id DESC
             LIMIT 300"
        )->fetchAll();
    }

    /** Bombonas que pueden salir a reparación: regular o mal estado, libres y sin reparación abierta. */
    public function cilindrosParaReparar(): array
    {
        return $this->db->query(
            "SELECT c.id, c.serial, c.capacidad_kg, c.estado_fisico
             FROM gas_cilindros c
             WHERE c.estado_registro = 'activo'
               AND c.estado_fisico IN ('regular', 'mal_estado')
               AND c.despacho_abierto_id IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM gas_reparaciones r
                   WHERE r.cilindro_id = c.id AND r.estado = 'en_reparacion'
               )
             ORDER BY c.serial
             LIMIT 500"
        )->fetchAll();
    }

    /** @param list<int|string> $ids */
    public function enviarReparacion(array $ids, string $responsable, string $fecha, int $userId): int
    {
        $responsable = self::requerido($responsable, 120, 'responsable', 'Indica quién se lleva la bombona.');
        $fecha = self::fecha($fecha, 'fecha_salida', 'Indica la fecha de salida a reparación.');
        $limpios = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $limpios[$n] = $n;
            }
        }
        $limpios = array_values($limpios);
        if ($limpios === []) {
            throw new ValidationException(['cilindros' => 'Selecciona al menos una bombona.']);
        }
        return Database::transaction(function () use ($limpios, $responsable, $fecha, $userId): int {
            $lock = $this->db->prepare(
                'SELECT id FROM gas_cilindros WHERE id = :id FOR UPDATE'
            );
            $abierta = $this->db->prepare(
                "SELECT 1 FROM gas_reparaciones WHERE cilindro_id = :id AND estado = 'en_reparacion' LIMIT 1"
            );
            $ins = $this->db->prepare(
                'INSERT INTO gas_reparaciones (cilindro_id, responsable, fecha_salida, registrado_por)
                 VALUES (:cilindro_id, :responsable, :fecha_salida, :registrado_por)'
            );
            foreach ($limpios as $cilindroId) {
                $lock->execute(['id' => $cilindroId]);
                if ($lock->fetchColumn() === false) {
                    throw new ValidationException(['cilindros' => 'Una de las bombonas no existe.']);
                }
                $abierta->execute(['id' => $cilindroId]);
                if ($abierta->fetchColumn() !== false) {
                    throw new ValidationException(['cilindros' => 'Una bombona ya tiene una reparación abierta.']);
                }
                $ins->execute([
                    'cilindro_id'    => $cilindroId,
                    'responsable'    => $responsable,
                    'fecha_salida'   => $fecha,
                    'registrado_por' => $userId,
                ]);
            }
            return count($limpios);
        });
    }

    public function cerrarReparacion(int $id, string $costoRaw, int $userId): void
    {
        $costo = self::money($costoRaw, 'costo_usd', 'Indica el costo de la reparación en USD.', false);
        $tasa = TasaCambio::vigente($this->db);
        if ($tasa === null) {
            throw new ValidationException(['costo_usd' => 'Registra la tasa del día en Finanzas antes de cargar el egreso.']);
        }
        $tasaTxt = number_format((float) $tasa['tasa'], 4, '.', '');
        Database::transaction(function () use ($id, $costo, $tasaTxt, $userId): void {
            $stmt = $this->db->prepare(
                "SELECT r.id, r.estado, r.fecha_salida, c.serial
                 FROM gas_reparaciones r
                 JOIN gas_cilindros c ON c.id = r.cilindro_id
                 WHERE r.id = :id
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new ValidationException(['general' => 'Esa reparación no existe.']);
            }
            if ($row['estado'] !== 'en_reparacion') {
                throw new ValidationException(['general' => 'Esa reparación ya fue cerrada.']);
            }
            if (date('Y-m-d') < $row['fecha_salida']) {
                throw new ValidationException(['general' => 'La fecha de retorno no puede ser anterior a la salida a reparación.']);
            }
            $caja = new Caja($this->db);
            $caja->verificarFondos('caja', 'USD', $costo, 'costo_usd');
            $upd = $this->db->prepare(
                "UPDATE gas_reparaciones
                 SET estado = 'reparado', costo_usd = :costo, fecha_retorno = CURDATE()
                 WHERE id = :id AND estado = 'en_reparacion'"
            );
            $upd->execute(['costo' => $costo, 'id' => $id]);
            if ($upd->rowCount() !== 1) {
                throw new ValidationException(['general' => 'No se pudo cerrar la reparación.']);
            }
            $fisico = $this->db->prepare(
                "UPDATE gas_cilindros c
                 JOIN gas_reparaciones r ON r.cilindro_id = c.id
                 SET c.estado_fisico = 'optimo'
                 WHERE r.id = :id"
            );
            $fisico->execute(['id' => $id]);
            $caja->egresoReparacionGas([
                'fecha'           => date('Y-m-d H:i:s'),
                'monto'           => $costo,
                'tasa_bs_por_usd' => $tasaTxt,
                'monto_usd'       => $costo,
                'concepto'        => 'Reparación gas ' . $row['serial'],
                'gas_reparacion_id' => $id,
                'registrado_por'  => $userId,
            ]);
        });
    }

    private const DUENO = "CASE
            WHEN c.pertenencia = 'propio' THEN 'Empresa'
            WHEN c.tipo_tercero = 'empleado' THEN TRIM(CONCAT(e.nombres, ' ', e.apellidos))
            WHEN c.tipo_tercero = 'transportista' THEN t.nombre
            WHEN c.tipo_tercero = 'productor' THEN pr.nombre
            ELSE c.propietario_nombre
        END AS dueno";

    private const JOIN_DUENO = 'LEFT JOIN empleados e ON e.id = c.empleado_id
             LEFT JOIN log_transportistas t ON t.id = c.transportista_id
             LEFT JOIN productores pr ON pr.id = c.productor_id';

    /** @return array{pertenencia:string, tipo_tercero:?string, empleado_id:?int, transportista_id:?int, productor_id:?int, propietario_nombre:?string} */
    private function dueno(array $in): array
    {
        $tipo = (string) ($in['tipo_dueno'] ?? $in['pertenencia'] ?? 'propio');
        $vacio = [
            'pertenencia'        => 'propio',
            'tipo_tercero'       => null,
            'empleado_id'        => null,
            'transportista_id'   => null,
            'productor_id'       => null,
            'propietario_nombre' => null,
        ];
        if ($tipo === 'propio' || $tipo === '') {
            return $vacio;
        }
        $vacio['pertenencia'] = 'tercero';
        if ($tipo === 'empleado') {
            $vacio['tipo_tercero'] = 'empleado';
            $vacio['empleado_id'] = self::entero($in['empleado_id'] ?? '', 'empleado_id', 'Selecciona el empleado dueño.');
            return $vacio;
        }
        if ($tipo === 'transportista') {
            $vacio['tipo_tercero'] = 'transportista';
            $vacio['transportista_id'] = self::entero($in['transportista_id'] ?? '', 'transportista_id', 'Selecciona el transportista dueño.');
            return $vacio;
        }
        if ($tipo === 'productor') {
            $vacio['tipo_tercero'] = 'productor';
            $vacio['productor_id'] = self::entero($in['productor_id'] ?? '', 'productor_id', 'Selecciona el productor dueño.');
            return $vacio;
        }
        if ($tipo === 'otro') {
            $vacio['tipo_tercero'] = 'otro';
            $vacio['propietario_nombre'] = self::requerido($in['propietario_nombre'] ?? '', 120, 'propietario_nombre', 'Indica el nombre del propietario.');
            return $vacio;
        }
        throw new ValidationException(['tipo_dueno' => 'El propietario es propio, empleado, transportista, productor u otro.']);
    }

    private function existe(string $tabla, int $id): bool
    {
        $tablas = ['gas_proveedores' => true, 'gas_cilindros' => true];
        if (!isset($tablas[$tabla])) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM ' . $tabla . ' WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    private static function sabadoSemana(): DateTimeImmutable
    {
        $hoy = new DateTimeImmutable('today');
        $retroceso = ((int) $hoy->format('w') + 1) % 7;
        return $hoy->modify('-' . $retroceso . ' days');
    }

    private static function requerido(mixed $value, int $max, string $campo, string $mensaje): string
    {
        $texto = self::opcional($value, $max);
        $min = $campo === 'serial' ? 1 : 2;
        if ($texto === null || mb_strlen($texto) < $min) {
            throw new ValidationException([$campo => $mensaje]);
        }
        return $texto;
    }

    private static function opcional(mixed $value, int $max): ?string
    {
        $texto = trim((string) $value);
        if ($texto === '') {
            return null;
        }
        return mb_substr($texto, 0, $max);
    }

    private static function entero(mixed $value, string $campo, string $mensaje): int
    {
        $texto = trim((string) $value);
        if (!preg_match('/^[1-9]\d{0,9}$/', $texto)) {
            throw new ValidationException([$campo => $mensaje]);
        }
        return (int) $texto;
    }

    private static function fecha(mixed $value, string $campo, string $mensaje): string
    {
        $fecha = self::fechaOpcional($value, $campo);
        if ($fecha === null) {
            throw new ValidationException([$campo => $mensaje]);
        }
        return $fecha;
    }

    private static function fechaOpcional(mixed $value, string $campo): ?string
    {
        $texto = trim((string) $value);
        if ($texto === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
        if ($dt === false || $dt->format('Y-m-d') !== $texto) {
            throw new ValidationException([$campo => 'Usa una fecha válida (AAAA-MM-DD).']);
        }
        return $texto;
    }

    /** @param array<string, mixed> $in @return array<int, string> */
    private static function preciosCapacidad(array $in): array
    {
        $out = [];
        foreach ([10, 15, 27, 43] as $kg) {
            $out[$kg] = self::money(
                $in['precio_' . $kg] ?? '',
                'precio_' . $kg,
                'Indica el precio de ' . $kg . ' kg.',
                false
            );
        }
        return $out;
    }

    private static function money(mixed $value, string $campo, string $mensaje, bool $permiteCero): string
    {
        $texto = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.(\d{1,2}))?$/', $texto, $m)) {
            throw new ValidationException([$campo => $mensaje]);
        }
        $dec = str_pad($m[1] ?? '', 2, '0');
        $entero = strtok($texto, '.');
        $out = $entero . '.' . $dec;
        if (!$permiteCero && $out === '0.00') {
            throw new ValidationException([$campo => $mensaje]);
        }
        return $out;
    }

    private static function tasa(mixed $value, string $campo): string
    {
        $texto = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.(\d{1,4}))?$/', $texto, $m)) {
            throw new ValidationException([$campo => 'Indica la tasa del día (Bs por 1 USD).']);
        }
        $dec = str_pad($m[1] ?? '', 4, '0');
        $entero = strtok($texto, '.');
        $out = $entero . '.' . $dec;
        if ((float) $out <= 0) {
            throw new ValidationException([$campo => 'La tasa tiene que ser mayor que cero.']);
        }
        return $out;
    }

    private static function vesAUsd(string $monto, string $tasa): string
    {
        $usd = round(((float) $monto) / ((float) $tasa), 2);
        if ($usd <= 0) {
            throw new ValidationException(['monto' => 'El monto en bolívares no alcanza a un centavo de dólar con esa tasa.']);
        }
        return number_format($usd, 2, '.', '');
    }
}
