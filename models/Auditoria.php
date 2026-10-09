<?php
declare(strict_types=1);

/** Lectura de la bitácora. La escritura es audit_log(); los triggers de la BD impiden UPDATE y DELETE. */
final class Auditoria
{
    public const ACCION_RESPALDO = 'respaldo_descargado';
    public const POR_PAGINA = 50;

    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array{q?:string, modulo?:?string, desde?:?string, hasta?:?string} $filtros
     * @return array{filas:list<array>, total:int, pagina:int, paginas:int}
     */
    public function listar(array $filtros, int $pagina = 1): array
    {
        [$where, $params] = self::filtrosSql($filtros);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id' . $where);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = max(1, min($pagina, $paginas));

        $stmt = $this->db->prepare(
            'SELECT a.id, a.creado_en, a.modulo, a.accion, a.entidad, a.entidad_id, a.detalle, a.ip, a.metodo, a.ruta,
                    u.nombre AS usuario, u.email AS usuario_email, u.rol AS usuario_rol
             FROM auditoria a
             LEFT JOIN usuarios u ON u.id = a.usuario_id'
            . $where
            . ' ORDER BY a.id DESC LIMIT ' . self::POR_PAGINA . ' OFFSET ' . (($pagina - 1) * self::POR_PAGINA)
        );
        $stmt->execute($params);

        return ['filas' => $stmt->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function contarTodo(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM auditoria')->fetchColumn();
    }

    /** @return list<string> */
    public function modulosRegistrados(): array
    {
        return $this->db->query('SELECT DISTINCT modulo FROM auditoria ORDER BY modulo')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array{usuario:?string, fecha:string, detalle:?array}|null */
    public function ultimoRespaldo(): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT u.nombre AS usuario, a.creado_en AS fecha, a.detalle
             FROM auditoria a
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             WHERE a.accion = :accion
             ORDER BY a.id DESC
             LIMIT 1'
        );
        $stmt->execute(['accion' => self::ACCION_RESPALDO]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $detalle = $row['detalle'] === null ? null : json_decode((string) $row['detalle'], true);
        return ['usuario' => $row['usuario'], 'fecha' => (string) $row['fecha'], 'detalle' => is_array($detalle) ? $detalle : null];
    }

    public function respaldoDescargadoHoy(): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM auditoria WHERE accion = :accion AND creado_en >= :hoy LIMIT 1'
        );
        $stmt->execute(['accion' => self::ACCION_RESPALDO, 'hoy' => date('Y-m-d 00:00:00')]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array{0:string, 1:array<string, string>} */
    private static function filtrosSql(array $f): array
    {
        $where = [];
        $params = [];
        if (($f['q'] ?? '') !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(a.accion LIKE :q1 OR u.nombre LIKE :q2 OR a.ip LIKE :q3 OR a.detalle LIKE :q4)';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        if (($f['modulo'] ?? '') !== '' && $f['modulo'] !== null) {
            $where[] = 'a.modulo = :modulo';
            $params['modulo'] = $f['modulo'];
        }
        if (!empty($f['desde'])) {
            $where[] = 'a.creado_en >= :desde';
            $params['desde'] = $f['desde'] . ' 00:00:00';
        }
        if (!empty($f['hasta'])) {
            $where[] = 'a.creado_en < :hasta';
            $params['hasta'] = (new DateTimeImmutable($f['hasta']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
