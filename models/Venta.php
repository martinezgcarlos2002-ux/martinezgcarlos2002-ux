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
        'productor' => 'Productor',
        'empleado'  => 'Empleado',
        'cxc'       => 'Cliente CxC',
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
            'empleado'  => 'SELECT id, TRIM(CONCAT(nombres, \' \', apellidos)) AS nombre, cedula AS documento
                            FROM empleados
                            WHERE activo = 1 AND (nombres LIKE :q OR apellidos LIKE :q2 OR IFNULL(cedula, \'\') LIKE :q3)
                            ORDER BY apellidos, nombres LIMIT 20',
            'cxc'       => 'SELECT id, nombre, documento FROM cxc_clientes
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
                 (numero, cliente_id, tipo_cliente, entidad_tipo, entidad_id, fecha, moneda_pago, metodo_pago,
                  tasa_id, tasa_bs_por_usd, total_usd, total_bs, emitida_por)
             VALUES
                 (:numero, :cliente_id, :tipo_cliente, :entidad_tipo, :entidad_id, :fecha, :moneda_pago, :metodo_pago,
                  :tasa_id, :tasa_bs_por_usd, 0, 0, :emitida_por)'
        );
        $stmt->execute([
            'numero'          => 'TMP-' . bin2hex(random_bytes(8)),
            'cliente_id'      => $data['cliente_id'],
            'tipo_cliente'    => $data['tipo_cliente'],
            'entidad_tipo'    => $data['entidad_tipo'],
            'entidad_id'      => $data['entidad_id'],
            'fecha'           => $data['fecha'],
            'moneda_pago'     => $data['moneda_pago'],
            'metodo_pago'     => $data['metodo_pago'],
            'tasa_id'         => $data['tasa_id'],
            'tasa_bs_por_usd' => $data['tasa_bs_por_usd'],
            'emitida_por'     => $data['emitida_por'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function addItem(int $ventaId, int $productoId, string $cantidad, string $precioUnitUsd): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unit_usd)
             VALUES (:venta_id, :producto_id, :cantidad, :precio_unit_usd)'
        );
        $stmt->execute([
            'venta_id'        => $ventaId,
            'producto_id'     => $productoId,
            'cantidad'        => $cantidad,
            'precio_unit_usd' => $precioUnitUsd,
        ]);
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
            'numero'   => 'FAC-' . str_pad((string) $ventaId, 8, '0', STR_PAD_LEFT),
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
            'SELECT v.*, c.nombre AS cliente_nombre, c.documento AS cliente_documento
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
            'SELECT p.nombre, i.cantidad, i.precio_unit_usd, i.subtotal_usd
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
            'productor' => 'SELECT nombre FROM productores WHERE id = :id',
            'empleado'  => 'SELECT TRIM(CONCAT(nombres, \' \', apellidos)) FROM empleados WHERE id = :id',
            'cxc'       => 'SELECT nombre FROM cxc_clientes WHERE id = :id',
            default     => null,
        };
        if ($sql === null) {
            return (string) ($venta['cliente_nombre'] ?? '');
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $nombre = $stmt->fetchColumn();
        return $nombre !== false && $nombre !== '' ? (string) $nombre : (string) ($venta['cliente_nombre'] ?? '');
    }

    /** El asiento se deriva de la factura ya persistida, así caja y venta no pueden discrepar. */
    public function registrarIngresoCaja(int $ventaId, int $usuarioId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO caja_movimientos
                 (fecha, tipo, moneda, monto, tasa_bs_por_usd, monto_usd, concepto, origen, venta_id, registrado_por)
             SELECT v.fecha, 'ingreso', v.moneda_pago,
                    IF(v.moneda_pago = 'USD', v.total_usd, v.total_bs),
                    v.tasa_bs_por_usd, v.total_usd,
                    LEFT(CONCAT('Venta ', v.numero, ' - ', c.nombre), 160),
                    'venta', v.id, :usuario_id
             FROM ventas v
             JOIN clientes c ON c.id = v.cliente_id
             WHERE v.id = :id"
        );
        $stmt->execute(['usuario_id' => $usuarioId, 'id' => $ventaId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(sprintf('No se pudo asentar en caja la venta #%d.', $ventaId));
        }
        return (int) $this->db->lastInsertId();
    }
}
