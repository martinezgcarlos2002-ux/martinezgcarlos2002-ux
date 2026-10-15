<?php
declare(strict_types=1);

final class Venta
{
    public const MONEDAS = ['USD', 'VES'];

    public const TIPOS_CLIENTE = [
        'generico'  => 'Genérico',
        'frecuente' => 'Frecuente',
        'exclusivo' => 'Exclusivo',
    ];

    public const ENTIDADES = [
        'productor'     => 'Productor',
        'empleado'      => 'Empleado',
        'transportista' => 'Transportista',
        'cxc'           => 'Cliente CxC',
    ];

    public const METODOS_PAGO = [
        'efectivo'             => 'Efectivo',
        'pago_movil'           => 'Pago Móvil',
        'transferencia'        => 'Transferencia',
        'punto'                => 'Punto',
        'credito'              => 'Crédito',
        'cortesia'             => 'Cortesía',
        'mercancia_descuento'  => 'Mercancía / descuento',
    ];

    public const METODOS_DEUDA = ['credito', 'mercancia_descuento'];
    public const METODOS_SIN_CAJA = ['credito', 'cortesia', 'mercancia_descuento'];

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array{id:int, nombre:string}> */
    public function clientesActivos(): array
    {
        return $this->db->query('SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre')->fetchAll();
    }

    public function clienteActivo(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, nombre FROM clientes WHERE id = :id AND activo = 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array{id:int, tasa:string}|null  Tasa como string para congelarla en la factura sin pasar por float. */
    public function tasaVigenteEn(string $momento): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tasa_bs_por_usd
             FROM tasas_cambio
             WHERE vigente_desde <= :momento
             ORDER BY vigente_desde DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['momento' => $momento]);
        $row = $stmt->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'tasa' => (string) $row['tasa_bs_por_usd']];
    }

    public function clienteContado(): array
    {
        $row = $this->db->query(
            "SELECT id, nombre FROM clientes WHERE activo = 1 AND nombre = 'CONSUMIDOR FINAL' LIMIT 1"
        )->fetch();
        if ($row !== false) {
            return $row;
        }
        $any = $this->db->query('SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY id ASC LIMIT 1')->fetch();
        if ($any === false) {
            throw new ValidationException(['cliente_id' => 'No hay clientes activos. Crea al menos CONSUMIDOR FINAL.']);
        }
        return $any;
    }

    /** @return list<array{id:int, nombre:string, documento:?string}> */
    public function buscarEntidades(string $tipo, string $q): array
    {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr(trim($q), 0, 80)) . '%';
        $sql = match ($tipo) {
            'productor' => 'SELECT id, nombre, documento FROM productores
                            WHERE activo = 1 AND (nombre LIKE :q OR IFNULL(documento, \'\') LIKE :q2)
                            ORDER BY nombre LIMIT 20',
            'empleado'      => 'SELECT id, TRIM(CONCAT(nombres, \' \', apellidos)) AS nombre, cedula AS documento
                            FROM empleados
                            WHERE activo = 1 AND (nombres LIKE :q OR apellidos LIKE :q2 OR IFNULL(cedula, \'\') LIKE :q3)
                            ORDER BY apellidos, nombres LIMIT 20',
            'transportista' => 'SELECT id, nombre, documento FROM log_transportistas
                            WHERE estado = \'activo\' AND (nombre LIKE :q OR IFNULL(documento, \'\') LIKE :q2)
                            ORDER BY nombre LIMIT 20',
            'cxc'           => 'SELECT id, nombre, documento FROM cxc_clientes
                            WHERE nombre LIKE :q OR IFNULL(documento, \'\') LIKE :q2
                            ORDER BY nombre LIMIT 20',
            default     => throw new ValidationException(['tipo' => 'Tipo de entidad inválido.']),
        };
        $stmt = $this->db->prepare($sql);
        $params = ['q' => $like, 'q2' => $like];
        if ($tipo === 'empleado') {
            $params['q3'] = $like;
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Los totales quedan en 0 hasta finalizar(): los calcula MySQL a partir de venta_items.subtotal_usd. */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ventas
                 (numero, cliente_id, ventas_cliente_id, cliente_nombre, tipo_cliente, entidad_tipo, entidad_id,
                  fecha, moneda_pago, metodo_pago, referencia, descripcion_pago, semana_aplicacion,
                  tasa_id, tasa_bs_por_usd, total_usd, total_bs, emitida_por)
             VALUES
                 (:numero, :cliente_id, :ventas_cliente_id, :cliente_nombre, :tipo_cliente, :entidad_tipo, :entidad_id,
                  :fecha, :moneda_pago, :metodo_pago, :referencia, :descripcion_pago, :semana_aplicacion,
                  :tasa_id, :tasa_bs_por_usd, 0, 0, :emitida_por)'
        );
        $stmt->execute([
            'numero'            => 'TMP-' . bin2hex(random_bytes(8)),
            'cliente_id'        => $data['cliente_id'],
            'ventas_cliente_id' => $data['ventas_cliente_id'],
            'cliente_nombre'    => $data['cliente_nombre'],
            'tipo_cliente'      => $data['tipo_cliente'],
            'entidad_tipo'      => $data['entidad_tipo'],
            'entidad_id'        => $data['entidad_id'],
            'fecha'             => $data['fecha'],
            'moneda_pago'       => $data['moneda_pago'],
            'metodo_pago'       => $data['metodo_pago'],
            'referencia'        => $data['referencia'],
            'descripcion_pago'  => $data['descripcion_pago'],
            'semana_aplicacion' => $data['semana_aplicacion'],
            'tasa_id'           => $data['tasa_id'],
            'tasa_bs_por_usd'   => $data['tasa_bs_por_usd'],
            'emitida_por'       => $data['emitida_por'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function addItem(int $ventaId, int $productoId, string $cantidad, string $precioUnitUsd, int $empaques = 0, ?string $pesoBruto = null, string $taraUnitaria = '0.000', string $taraTotal = '0.000'): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO venta_items
                (venta_id, producto_id, cantidad, cantidad_secundaria, peso_bruto, tara_unitaria, tara_total, precio_unit_usd)
             VALUES
                (:venta_id, :producto_id, :cantidad, :empaques, :bruto, :tara_u, :tara_t, :precio_unit_usd)'
        );
        $stmt->execute([
            'venta_id'        => $ventaId,
            'producto_id'     => $productoId,
            'cantidad'        => $cantidad,
            'empaques'        => max(0, $empaques),
            'bruto'           => $pesoBruto,
            'tara_u'          => $taraUnitaria,
            'tara_t'          => $taraTotal,
            'precio_unit_usd' => $precioUnitUsd,
        ]);
    }

    /**
     * Siguiente correlativo FAC dentro de la transacción de la venta.
     * FOR UPDATE serializa dos facturas concurrentes; MAX evita el hueco de COUNT(*)+1.
     */
    public function siguienteNumero(): string
    {
        $stmt = $this->db->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 5) AS UNSIGNED)), 0) + 1
             FROM ventas
             WHERE numero REGEXP '^FAC-[0-9]+$'
             FOR UPDATE"
        );
        $n = (int) $stmt->fetchColumn();
        return 'FAC-' . str_pad((string) max(1, $n), 8, '0', STR_PAD_LEFT);
    }

    /** MySQL evalúa las asignaciones de izquierda a derecha: total_bs usa el total_usd recién calculado. */
    public function finalizar(int $ventaId): array
    {
        $stmt = $this->db->prepare(
            'UPDATE ventas
             SET numero    = :numero,
                 total_usd = (SELECT COALESCE(SUM(i.subtotal_usd), 0) FROM venta_items i WHERE i.venta_id = :id_items),
                 total_bs  = ROUND(total_usd * tasa_bs_por_usd, 2)
             WHERE id = :id'
        );
        $stmt->execute([
            'numero'   => $this->siguienteNumero(),
            'id_items' => $ventaId,
            'id'       => $ventaId,
        ]);
        return $this->find($ventaId);
    }

    public function find(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ventas WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['venta' => 'La venta no existe.']);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    public function ticket(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.*,
                    COALESCE(NULLIF(v.cliente_nombre, \'\'), c.nombre) AS cliente_nombre,
                    c.documento AS cliente_documento
             FROM ventas v
             JOIN clientes c ON c.id = v.cliente_id
             WHERE v.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $venta = $stmt->fetch();
        if ($venta === false) {
            throw new ValidationException(['venta' => 'La venta no existe.']);
        }
        return $venta + ['items' => $this->items($id), 'entidad_nombre' => $this->nombreEntidad($venta)];
    }

    /** @return list<array{nombre:string, cantidad:string, precio_unit_usd:string, subtotal_usd:string}> */
    public function items(int $ventaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.nombre, p.unidad_secundaria, i.cantidad, i.cantidad_secundaria,
                    i.peso_bruto, i.tara_unitaria, i.tara_total, i.precio_unit_usd, i.subtotal_usd
             FROM venta_items i
             JOIN productos p ON p.id = i.producto_id
             WHERE i.venta_id = :id
             ORDER BY i.id'
        );
        $stmt->execute(['id' => $ventaId]);
        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $venta */
    public function nombreEntidad(array $venta): string
    {
        $tipo = (string) ($venta['entidad_tipo'] ?? '');
        $id = (int) ($venta['entidad_id'] ?? 0);
        if ($tipo === '' || $id < 1) {
            return (string) ($venta['cliente_nombre'] ?? '');
        }
        $sql = match ($tipo) {
            'productor'     => 'SELECT nombre FROM productores WHERE id = :id',
            'empleado'      => 'SELECT TRIM(CONCAT(nombres, \' \', apellidos)) FROM empleados WHERE id = :id',
            'transportista' => 'SELECT nombre FROM log_transportistas WHERE id = :id',
            'cxc'           => 'SELECT nombre FROM cxc_clientes WHERE id = :id',
            default         => null,
        };
        if ($sql === null) {
            return (string) ($venta['cliente_nombre'] ?? '');
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $nombre = $stmt->fetchColumn();
        return $nombre !== false && $nombre !== '' ? (string) $nombre : (string) ($venta['cliente_nombre'] ?? '');
    }

    /**
     * Ingreso en Finanzas (caja_movimientos). Efectivo/punto → gaveta; PM/transferencia → banco.
     * Cortesía, crédito y mercancía no deben llamar este método.
     */
    public function registrarIngresoCaja(int $ventaId, int $usuarioId, string $nombreCliente): int
    {
        $venta = $this->find($ventaId);
        $cuenta = in_array($venta['metodo_pago'], ['pago_movil', 'transferencia'], true) ? 'banco' : 'caja';
        $concepto = mb_substr(
            sprintf('Venta POS - Factura Nro %s - Cliente: %s', $venta['numero'], $nombreCliente),
            0,
            160
        );
        $ref = trim((string) ($venta['referencia'] ?? ''));
        if ($ref !== '') {
            $concepto = mb_substr($concepto . ' Ref ' . $ref, 0, 160);
        }
        $stmt = $this->db->prepare(
            "INSERT INTO caja_movimientos
                 (fecha, tipo, cuenta, moneda, monto, tasa_bs_por_usd, monto_usd, concepto, origen, venta_id, registrado_por)
             VALUES
                 (:fecha, 'ingreso', :cuenta, :moneda, :monto, :tasa, :usd, :concepto, 'venta', :venta_id, :usuario)"
        );
        $stmt->execute([
            'fecha'    => $venta['fecha'],
            'cuenta'   => $cuenta,
            'moneda'   => $venta['moneda_pago'],
            'monto'    => $venta['moneda_pago'] === 'USD' ? $venta['total_usd'] : $venta['total_bs'],
            'tasa'     => $venta['tasa_bs_por_usd'],
            'usd'      => $venta['total_usd'],
            'concepto' => $concepto,
            'venta_id' => $ventaId,
            'usuario'  => $usuarioId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<array{id:int, nombre:string, documento:?string, telefono:?string, direccion:?string, tipo:string, cxc_cliente_id:?int}> */
    public function clientesFrecuentes(): array
    {
        return $this->db->query(
            "SELECT id, nombre, documento, telefono, direccion, tipo, cxc_cliente_id
             FROM ventas_clientes WHERE tipo = 'frecuente' ORDER BY nombre"
        )->fetchAll();
    }

    /** @return list<array{id:int, nombre:string, documento:?string, tipo:string}> */
    public function clientesPos(): array
    {
        return $this->db->query(
            'SELECT id, nombre, documento, tipo FROM ventas_clientes ORDER BY tipo, nombre'
        )->fetchAll();
    }

    public function clientePos(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ventas_clientes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function crearClientePos(string $nombre, ?string $documento, string $tipo = 'frecuente', ?string $telefono = null, ?string $direccion = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ventas_clientes (nombre, documento, telefono, direccion, tipo) VALUES (:n, :d, :tel, :dir, :t)'
        );
        try {
            $stmt->execute(['n' => $nombre, 'd' => $documento, 'tel' => $telefono, 'dir' => $direccion, 't' => $tipo]);
        } catch (PDOException $e) {
            if (duplicate_key($e) === 'uq_vcli_documento') {
                throw new ValidationException(['documento' => 'Ya existe un cliente POS con ese documento.']);
            }
            throw $e;
        }
        return (int) $this->db->lastInsertId();
    }

    public function marcarExclusivo(int $id, int $cxcId): void
    {
        $this->db->prepare(
            "UPDATE ventas_clientes SET tipo = 'exclusivo', cxc_cliente_id = :cxc WHERE id = :id"
        )->execute(['cxc' => $cxcId, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function historial(int $limite = 200): array
    {
        $limite = max(1, min(500, $limite));
        return $this->db->query(
            "SELECT v.id, v.fecha, v.numero, v.tipo_cliente, v.entidad_tipo, v.metodo_pago,
                    v.total_usd, v.total_bs, v.moneda_pago,
                    COALESCE(
                        NULLIF(v.cliente_nombre, ''),
                        CASE v.entidad_tipo
                            WHEN 'productor' THEN pr.nombre
                            WHEN 'empleado' THEN TRIM(CONCAT(emp.nombres, ' ', emp.apellidos))
                            WHEN 'transportista' THEN tr.nombre
                            WHEN 'cxc' THEN cx.nombre
                        END,
                        vc.nombre,
                        c.nombre,
                        '—'
                    ) AS cliente,
                    COALESCE(u.nombre, '—') AS emisor
             FROM ventas v
             LEFT JOIN clientes c ON c.id = v.cliente_id
             LEFT JOIN ventas_clientes vc ON vc.id = v.ventas_cliente_id
             LEFT JOIN usuarios u ON u.id = v.emitida_por
             LEFT JOIN productores pr ON v.entidad_tipo = 'productor' AND pr.id = v.entidad_id
             LEFT JOIN empleados emp ON v.entidad_tipo = 'empleado' AND emp.id = v.entidad_id
             LEFT JOIN log_transportistas tr ON v.entidad_tipo = 'transportista' AND tr.id = v.entidad_id
             LEFT JOIN cxc_clientes cx ON v.entidad_tipo = 'cxc' AND cx.id = v.entidad_id
             WHERE v.estado = 'emitida'
             ORDER BY v.fecha DESC, v.id DESC
             LIMIT {$limite}"
        )->fetchAll();
    }
}
