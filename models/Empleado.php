<?php
declare(strict_types=1);

final class Empleado
{
    public const TIPOS_CUENTA = ['propia' => 'Cuenta Propia', 'tercero' => 'Cuenta de Tercero'];

    private const COLUMNAS = 'id, cedula, nombres, apellidos, cargo, profesion, telefono, direccion, direccion_domicilio,
                              fecha_nacimiento, fecha_ingreso, fecha_salida, salario_semanal_usd, dias_libres_mes,
                              tipo_cuenta, banco, titular_cedula, titular_telefono, numero_cuenta,
                              expediente_penal, foto_perfil, foto_documento, activo';

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return array_map(
            [self::class, 'normalizar'],
            $this->db->query('SELECT ' . self::COLUMNAS . ' FROM empleados ORDER BY activo DESC, apellidos, nombres')->fetchAll()
        );
    }

    /**
     * Activos con contrato vigente en algún día del rango.
     * @return list<array<string, mixed>>
     */
    public function vigentesEntre(string $desde, string $hasta, string $orden = 'apellidos, nombres'): array
    {
        $orden = $orden === 'nombres, apellidos' ? $orden : 'apellidos, nombres';
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . '
             FROM empleados
             WHERE activo = 1
               AND (fecha_ingreso IS NULL OR fecha_ingreso <= :hasta)
               AND (fecha_salida  IS NULL OR fecha_salida  >= :desde)
             ORDER BY ' . $orden
        );
        $stmt->execute(['desde' => $desde, 'hasta' => $hasta]);
        return array_map([self::class, 'normalizar'], $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $bloquear = false): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNAS . ' FROM empleados WHERE id = :id' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::normalizar($row);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO empleados
                 (cedula, nombres, apellidos, cargo, profesion, telefono, direccion, direccion_domicilio, fecha_nacimiento, fecha_ingreso, fecha_salida,
                  salario_semanal_usd, dias_libres_mes, tipo_cuenta, banco, titular_cedula, titular_telefono,
                  numero_cuenta, expediente_penal, activo)
             VALUES
                 (:cedula, :nombres, :apellidos, :cargo, :profesion, :telefono, :direccion, :direccion_domicilio, :fecha_nacimiento, :fecha_ingreso, :fecha_salida,
                  :salario_semanal_usd, :dias_libres_mes, :tipo_cuenta, :banco, :titular_cedula, :titular_telefono,
                  :numero_cuenta, :expediente_penal, :activo)'
        );
        $stmt->execute(self::params($data));
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE empleados SET
                 cedula = :cedula, nombres = :nombres, apellidos = :apellidos, cargo = :cargo, profesion = :profesion,
                 telefono = :telefono, direccion = :direccion, direccion_domicilio = :direccion_domicilio,
                 fecha_nacimiento = :fecha_nacimiento, fecha_ingreso = :fecha_ingreso,
                 fecha_salida = :fecha_salida, salario_semanal_usd = :salario_semanal_usd, dias_libres_mes = :dias_libres_mes,
                 tipo_cuenta = :tipo_cuenta, banco = :banco, titular_cedula = :titular_cedula,
                 titular_telefono = :titular_telefono, numero_cuenta = :numero_cuenta,
                 expediente_penal = :expediente_penal, activo = :activo
             WHERE id = :id'
        );
        $stmt->execute(self::params($data) + ['id' => $id]);
    }

    /** @return array<string, int> nombre => cobertura mínima */
    public function cargos(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT nombre, cobertura_minima FROM cargos ORDER BY nombre') as $row) {
            $out[(string) $row['nombre']] = (int) $row['cobertura_minima'];
        }
        return $out;
    }

    /** @return bool true si se creó, false si ya existía */
    public function crearCargo(string $nombre, int $coberturaMinima = 1): bool
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO cargos (nombre, cobertura_minima) VALUES (:nombre, :minimo)');
        $stmt->execute(['nombre' => $nombre, 'minimo' => $coberturaMinima]);
        return $stmt->rowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    private static function normalizar(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'cedula'           => (string) ($row['cedula'] ?? ''),
            'nombres'          => (string) $row['nombres'],
            'apellidos'        => (string) $row['apellidos'],
            'cargo'            => (string) ($row['cargo'] ?? ''),
            'profesion'        => (string) ($row['profesion'] ?? ''),
            'telefono'         => (string) ($row['telefono'] ?? ''),
            'direccion'        => (string) ($row['direccion'] ?? ''),
            'direccion_domicilio' => (string) ($row['direccion_domicilio'] ?? ''),
            'fecha_nacimiento' => (string) ($row['fecha_nacimiento'] ?? ''),
            'foto_perfil'      => (string) ($row['foto_perfil'] ?? ''),
            'foto_documento'   => (string) ($row['foto_documento'] ?? ''),
            'fecha_ingreso'    => (string) ($row['fecha_ingreso'] ?? ''),
            'fecha_salida'     => (string) ($row['fecha_salida'] ?? ''),
            'salario_semanal'  => (string) $row['salario_semanal_usd'],
            'dias_libres_mes'  => (int) $row['dias_libres_mes'],
            'tipo_cuenta'      => (string) $row['tipo_cuenta'],
            'banco'            => (string) ($row['banco'] ?? ''),
            'titular_cedula'   => (string) ($row['titular_cedula'] ?? ''),
            'titular_telefono' => (string) ($row['titular_telefono'] ?? ''),
            'numero_cuenta'    => (string) ($row['numero_cuenta'] ?? ''),
            'expediente_penal' => (bool) $row['expediente_penal'],
            'activo'           => (bool) $row['activo'],
        ];
    }

    /** @param array<string, mixed> $d */
    private static function params(array $d): array
    {
        $nulo = static fn (mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;
        return [
            'cedula'              => $nulo($d['cedula']),
            'nombres'             => $d['nombres'],
            'apellidos'           => $d['apellidos'],
            'cargo'               => $nulo($d['cargo']),
            'profesion'           => $nulo($d['profesion'] ?? ''),
            'telefono'            => $nulo($d['telefono']),
            'direccion'           => $nulo($d['direccion']),
            'direccion_domicilio' => $nulo($d['direccion_domicilio'] ?? $d['direccion'] ?? ''),
            'fecha_nacimiento'    => $nulo($d['fecha_nacimiento']),
            'fecha_ingreso'       => $nulo($d['fecha_ingreso']),
            'fecha_salida'        => $nulo($d['fecha_salida']),
            'salario_semanal_usd' => $d['salario_semanal'],
            'dias_libres_mes'     => $d['dias_libres_mes'],
            'tipo_cuenta'         => $d['tipo_cuenta'],
            'banco'               => $nulo($d['banco']),
            'titular_cedula'      => $nulo($d['titular_cedula']),
            'titular_telefono'    => $nulo($d['titular_telefono']),
            'numero_cuenta'       => $nulo($d['numero_cuenta']),
            'expediente_penal'    => $d['expediente_penal'] ? 1 : 0,
            'activo'              => $d['activo'] ? 1 : 0,
        ];
    }

    /** @param array<string, string> $rutas campo => ruta relativa */
    public function actualizarFotos(int $id, array $rutas): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach (['foto_perfil', 'foto_documento'] as $campo) {
            if (!isset($rutas[$campo]) || $rutas[$campo] === '') {
                continue;
            }
            $sets[] = $campo . ' = :' . $campo;
            $params[$campo] = $rutas[$campo];
        }
        if ($sets === []) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE empleados SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }
}
