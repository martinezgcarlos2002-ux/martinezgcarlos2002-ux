<?php
declare(strict_types=1);

final class DescuadreBancario extends RuntimeException
{
    /**
     * @param list<array<string, mixed>> $descuadres
     * @param list<string> $errores
     */
    public function __construct(public readonly array $descuadres, public readonly array $errores = [])
    {
        parent::__construct('La conciliación no cuadra');
    }
}

/**
 * Libro de bancos. La caja del día se acepta completa o no se acepta.
 * saldo_final = saldo_inicio + ingresos − egresos − comisiones.
 */
final class Banco
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<string> */
    public function aliasActivos(): array
    {
        $rows = $this->db->query(
            'SELECT alias_cuenta
             FROM bancos_cuentas
             WHERE activa = 1
             ORDER BY alias_cuenta'
        )->fetchAll();
        $alias = [];
        foreach ($rows as $row) {
            $nombre = trim((string) $row['alias_cuenta']);
            if ($nombre !== '') {
                $alias[] = $nombre;
            }
        }
        return $alias;
    }

    /** @return list<array{alias:string, moneda:string}> */
    public function cuentasParaPlantilla(): array
    {
        $rows = $this->db->query(
            "SELECT alias_cuenta, moneda
             FROM bancos_cuentas
             WHERE activa = 1
             ORDER BY alias_cuenta"
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $nombre = trim((string) $row['alias_cuenta']);
            if ($nombre === '') {
                continue;
            }
            $moneda = strtoupper((string) ($row['moneda'] ?? 'BS')) === 'USD' ? 'USD' : 'BS';
            $out[] = ['alias' => $nombre, 'moneda' => $moneda];
        }
        return $out;
    }

    /** @return list<string> */
    public function nombresEmpleados(): array
    {
        return $this->columna(
            "SELECT TRIM(CONCAT(nombres, ' ', apellidos)) AS nombre
             FROM empleados
             WHERE activo = 1 AND fecha_salida IS NULL
             ORDER BY apellidos, nombres"
        );
    }

    /** @return list<string> */
    public function nombresProductores(): array
    {
        return $this->columna(
            "SELECT nombre AS nombre FROM productores WHERE activo = 1 ORDER BY nombre"
        );
    }

    /** @return list<string> */
    public function nombresTransportistas(): array
    {
        return $this->columna(
            "SELECT nombre AS nombre FROM log_transportistas WHERE estado = 'activo' ORDER BY nombre"
        );
    }

    /** @return list<string> */
    public function nombresGas(): array
    {
        return $this->columna(
            "SELECT nombre AS nombre FROM gas_proveedores WHERE estado = 'activo' ORDER BY nombre"
        );
    }

    /** @return list<string> */
    public function nombresClientes(): array
    {
        return $this->columna(
            'SELECT nombre AS nombre FROM cxc_clientes ORDER BY nombre'
        );
    }

    /** @return list<string> */
    private function columna(string $sql): array
    {
        $out = [];
        foreach ($this->db->query($sql) as $row) {
            $nombre = trim((string) ($row['nombre'] ?? ''));
            if ($nombre !== '') {
                $out[] = $nombre;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function cuentas(): array
    {
        $filas = $this->db->query(
            'SELECT id, alias_cuenta, nombre_banco, numero_cuenta, cedula, telefono, moneda, saldo_actual_bs, activa
             FROM bancos_cuentas
             ORDER BY activa DESC, alias_cuenta'
        )->fetchAll();
        foreach ($filas as &$fila) {
            $fila['texto_compartir'] = self::textoCompartir($fila);
        }
        unset($fila);
        return $filas;
    }

    /** @param array<string, mixed> $cuenta */
    public static function textoCompartir(array $cuenta): string
    {
        $lineas = [];
        $banco = trim((string) ($cuenta['nombre_banco'] ?? ''));
        $numero = trim((string) ($cuenta['numero_cuenta'] ?? ''));
        $cedula = trim((string) ($cuenta['cedula'] ?? ''));
        $telefono = trim((string) ($cuenta['telefono'] ?? ''));
        if ($banco !== '') {
            $lineas[] = 'Banco: ' . $banco;
        }
        if ($numero !== '') {
            $lineas[] = 'Cuenta: ' . $numero;
        }
        if ($cedula !== '') {
            $lineas[] = 'CI: ' . $cedula;
        }
        if ($telefono !== '') {
            $lineas[] = 'Teléfono: ' . $telefono;
        }
        return implode("\n", $lineas);
    }

    /**
     * @param array{alias:string, nombre_banco:string, numero_cuenta:string, cedula:string, telefono:string, moneda:string} $datos
     */
    public function guardarCuenta(?int $id, array $datos): int
    {
        $alias = trim(preg_replace('/\s+/u', ' ', $datos['alias']) ?? '');
        $banco = trim(preg_replace('/\s+/u', ' ', $datos['nombre_banco']) ?? '');
        $numero = trim(preg_replace('/\s+/u', ' ', $datos['numero_cuenta']) ?? '');
        $cedula = trim(preg_replace('/\s+/u', ' ', $datos['cedula']) ?? '');
        $telefono = trim(preg_replace('/\s+/u', ' ', $datos['telefono']) ?? '');
        $moneda = strtoupper(trim($datos['moneda'])) === 'USD' ? 'USD' : (strtoupper(trim($datos['moneda'])) === 'BS' ? 'BS' : '');
        $errores = [];
        if ($alias === '' || mb_strlen($alias) > 80) {
            $errores['alias'] = 'El alias necesita un nombre de hasta 80 caracteres.';
        }
        if ($banco === '' || mb_strlen($banco) > 80) {
            $errores['nombre_banco'] = 'Indica el banco.';
        }
        if ($numero === '' || mb_strlen($numero) > 30) {
            $errores['numero_cuenta'] = 'Indica el número de cuenta (hasta 30 caracteres).';
        }
        if ($cedula === '' || mb_strlen($cedula) > 24) {
            $errores['cedula'] = 'Indica la cédula.';
        }
        if ($telefono === '' || mb_strlen($telefono) > 24) {
            $errores['telefono'] = 'Indica el teléfono.';
        }
        if ($moneda === '') {
            $errores['moneda'] = 'La moneda es BS o USD.';
        }
        if ($errores !== []) {
            throw new ValidationException($errores);
        }
        $dup = $this->db->prepare(
            'SELECT id FROM bancos_cuentas
             WHERE alias_cuenta = :alias AND (:id IS NULL OR id <> :id2)
             LIMIT 1'
        );
        $dup->execute(['alias' => $alias, 'id' => $id, 'id2' => $id]);
        if ($dup->fetchColumn() !== false) {
            throw new ValidationException(['alias' => 'Ya existe una cuenta con ese alias.']);
        }
        $params = [
            'alias' => $alias,
            'banco' => $banco,
            'numero' => $numero,
            'cedula' => $cedula,
            'telefono' => $telefono,
            'moneda' => $moneda,
        ];
        if ($id === null) {
            $ins = $this->db->prepare(
                'INSERT INTO bancos_cuentas
                    (alias_cuenta, nombre_banco, numero_cuenta, cedula, telefono, moneda, saldo_actual_bs, activa)
                 VALUES
                    (:alias, :banco, :numero, :cedula, :telefono, :moneda, 0, 1)'
            );
            $ins->execute($params);
            return (int) $this->db->lastInsertId();
        }
        $params['id'] = $id;
        $upd = $this->db->prepare(
            'UPDATE bancos_cuentas
             SET alias_cuenta = :alias, nombre_banco = :banco, numero_cuenta = :numero,
                 cedula = :cedula, telefono = :telefono, moneda = :moneda, activa = 1
             WHERE id = :id'
        );
        $upd->execute($params);
        if ($upd->rowCount() === 0 && !$this->existeCuenta($id)) {
            throw new ValidationException(['alias' => 'Esa cuenta ya no existe.']);
        }
        return $id;
    }

    public function desactivarCuenta(int $id): void
    {
        $upd = $this->db->prepare('UPDATE bancos_cuentas SET activa = 0 WHERE id = :id AND activa = 1');
        $upd->execute(['id' => $id]);
        if ($upd->rowCount() === 0) {
            throw new ValidationException(['alias' => 'Esa cuenta ya no está activa.']);
        }
    }

    public function activarCuenta(int $id): void
    {
        $upd = $this->db->prepare('UPDATE bancos_cuentas SET activa = 1 WHERE id = :id AND activa = 0');
        $upd->execute(['id' => $id]);
        if ($upd->rowCount() === 0) {
            throw new ValidationException(['alias' => 'Esa cuenta ya está activa.']);
        }
    }

    /** @return array<string, string> alias normalizado no; alias tal como está => saldo */
    public function saldosParaPlantilla(): array
    {
        $rows = $this->db->query(
            'SELECT a.alias_cuenta, d.saldo_final
             FROM bancos_cuentas a
             JOIN bancos_cierres_diarios d ON d.cuenta_id = a.id
             JOIN (
                 SELECT cuenta_id, MAX(fecha) AS fecha
                 FROM bancos_cierres_diarios
                 GROUP BY cuenta_id
             ) u ON u.cuenta_id = d.cuenta_id AND u.fecha = d.fecha
             WHERE a.activa = 1'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['alias_cuenta']] = (string) $row['saldo_final'];
        }
        return $out;
    }

    /** @return array<string, array{id:int, activa:int}> */
    private function mapaCuentas(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT id, alias_cuenta, activa FROM bancos_cuentas') as $row) {
            $out[ConciliacionExcel::norm((string) $row['alias_cuenta'])] = [
                'id' => (int) $row['id'],
                'activa' => (int) $row['activa'],
            ];
        }
        return $out;
    }

    /** @param array<string, array{id:int, activa:int}> $mapa */
    private function exigirCuentaActiva(string $alias, array $mapa): int
    {
        $clave = ConciliacionExcel::norm($alias);
        $cuenta = $mapa[$clave] ?? null;
        if ($cuenta === null) {
            throw new ValidationException([
                'archivo' => 'La cuenta ' . $alias . ' no está en el gestor. Créala antes de cargar el Excel.',
            ]);
        }
        if ($cuenta['activa'] !== 1) {
            throw new ValidationException([
                'archivo' => 'La cuenta ' . $alias . ' está inactiva. Actívala en el gestor antes de cargar.',
            ]);
        }
        return $cuenta['id'];
    }

    private function existeCuenta(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM bancos_cuentas WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> */
    public function cierresRecientes(int $limite = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.fecha, a.alias_cuenta, c.saldo_inicio, c.total_ingresos, c.total_egresos,
                    c.total_comisiones, c.saldo_final, c.estado, c.archivo
             FROM bancos_cierres_diarios c
             JOIN bancos_cuentas a ON a.id = c.cuenta_id
             ORDER BY c.fecha DESC, a.alias_cuenta
             LIMIT :limite'
        );
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Agrupa el Excel y aplica la fórmula. No toca la base.
     *
     * @param array{formato:string, saldos:list<array<string, mixed>>, movimientos:list<array<string, mixed>>} $libro
     * @return array{cierres:list<array<string, mixed>>, avisos:list<string>}
     */
    public static function cuadrar(array $libro): array
    {
        /** @var array<string, array<string, mixed>> $grupos */
        $grupos = [];
        foreach ($libro['saldos'] as $saldo) {
            $clave = $saldo['fecha'] . '|' . $saldo['cuenta'];
            if (isset($grupos[$clave])) {
                throw new ValidationException([
                    'archivo' => 'La cuenta ' . $saldo['cuenta'] . ' está repetida el ' . self::fechaCorta($saldo['fecha']) . '.',
                ]);
            }
            $grupos[$clave] = [
                'cuenta' => $saldo['cuenta'],
                'fecha' => $saldo['fecha'],
                'saldo_inicial' => (int) $saldo['saldo_inicial'],
                'saldo_final' => (int) $saldo['saldo_final'],
                'total_ingresos' => 0,
                'total_egresos' => 0,
                'total_comisiones' => 0,
                'movimientos' => [],
                'hoja' => $saldo['hoja'],
                'tiene_declarado' => !empty($saldo['tiene_declarado']),
            ];
        }
        $esClasificada = ($libro['formato'] ?? '') === 'clasificada';

        $avisos = [];
        $huerfanos = [];
        foreach ($libro['movimientos'] as $mov) {
            $cuenta = $mov['cuenta'];
            $clave = $mov['fecha'] . '|' . $cuenta;
            if (!isset($grupos[$clave])) {
                $corregida = self::cuentaCercana($cuenta, $mov['fecha'], $grupos);
                if ($corregida !== null) {
                    $avisos[] = $mov['hoja'] . ' fila ' . $mov['fila'] . ': "' . $cuenta . '" se tomó como ' . $corregida . '.';
                    $cuenta = $corregida;
                    $mov['cuenta'] = $corregida;
                    $clave = $mov['fecha'] . '|' . $cuenta;
                }
            }
            if (!isset($grupos[$clave])) {
                $huerfanos[$clave]['cuenta'] = $mov['cuenta'];
                $huerfanos[$clave]['fecha'] = $mov['fecha'];
                $huerfanos[$clave]['n'] = ($huerfanos[$clave]['n'] ?? 0) + 1;
                $huerfanos[$clave]['monto'] = ($huerfanos[$clave]['monto'] ?? 0) + (int) $mov['monto_bs'];
                continue;
            }
            $monto = (int) $mov['monto_bs'];
            $comision = (int) $mov['comision_bs'];
            if ($mov['tipo'] === 'ingreso') {
                $grupos[$clave]['total_ingresos'] += $monto;
            } else {
                $grupos[$clave]['total_egresos'] += $monto;
            }
            $grupos[$clave]['total_comisiones'] += $comision;
            $grupos[$clave]['movimientos'][] = $mov;
        }

        $descuadres = [];
        foreach ($huerfanos as $huerfano) {
            $descuadres[] = [
                'cuenta' => $huerfano['cuenta'],
                'fecha' => $huerfano['fecha'],
                'motivo' => 'cuenta_ausente',
                'saldo_inicial' => '0.00',
                'total_ingresos' => '0.00',
                'total_egresos' => ConciliacionExcel::desdeCentavos($huerfano['monto']),
                'total_comisiones' => '0.00',
                'saldo_final_calculado' => '0.00',
                'saldo_final_declarado' => '0.00',
                'diferencia' => ConciliacionExcel::desdeCentavos($huerfano['monto']),
                'mensaje' => $huerfano['cuenta'] . ' el ' . self::fechaCorta($huerfano['fecha'])
                    . ' tiene ' . $huerfano['n'] . ' movimiento(s) por Bs. ' . ConciliacionExcel::formatoBs($huerfano['monto'])
                    . ' y no aparece en el cuadro de saldos (INICIO / FINAL).',
            ];
        }
        $cierres = [];
        foreach ($grupos as $grupo) {
            $calculado = $grupo['saldo_inicial'] + $grupo['total_ingresos'] - $grupo['total_egresos'] - $grupo['total_comisiones'];
            $diferencia = $grupo['saldo_final'] - $calculado;
            if ($diferencia !== 0) {
                if ($esClasificada) {
                    if (!empty($grupo['tiene_declarado'])) {
                        $avisos[] = $grupo['cuenta'] . ' el ' . self::fechaCorta($grupo['fecha'])
                            . ': el saldo final del banco difiere del calculado en '
                            . ConciliacionExcel::formatoBs(abs($diferencia))
                            . '. Se guardó el calculado. La carga no se detiene por esto.';
                    }
                    $grupo['saldo_final'] = $calculado;
                } else {
                    $descuadres[] = self::descuadre($grupo, $calculado, $diferencia);
                    continue;
                }
            }
            $cierres[] = $grupo;
        }

        $porCuenta = [];
        foreach ($cierres as $cierre) {
            $porCuenta[$cierre['cuenta']][] = $cierre;
        }
        foreach ($porCuenta as $lista) {
            usort($lista, static fn (array $a, array $b): int => $a['fecha'] <=> $b['fecha']);
            $anterior = null;
            foreach ($lista as $cierre) {
                if ($anterior !== null && $cierre['saldo_inicial'] !== $anterior['saldo_final']) {
                    $diff = $cierre['saldo_inicial'] - $anterior['saldo_final'];
                    $descuadres[] = [
                        'cuenta' => $cierre['cuenta'],
                        'fecha' => $cierre['fecha'],
                        'motivo' => 'continuidad',
                        'saldo_inicial' => ConciliacionExcel::desdeCentavos($cierre['saldo_inicial']),
                        'saldo_final_calculado' => ConciliacionExcel::desdeCentavos($anterior['saldo_final']),
                        'saldo_final_declarado' => ConciliacionExcel::desdeCentavos($cierre['saldo_inicial']),
                        'total_ingresos' => '0.00',
                        'total_egresos' => '0.00',
                        'total_comisiones' => '0.00',
                        'diferencia' => ConciliacionExcel::desdeCentavos($diff),
                        'mensaje' => $cierre['cuenta'] . ' el ' . self::fechaCorta($cierre['fecha'])
                            . ': el saldo inicial (Bs. ' . ConciliacionExcel::formatoBs($cierre['saldo_inicial'])
                            . ') no sigue al saldo final del ' . self::fechaCorta($anterior['fecha'])
                            . ' (Bs. ' . ConciliacionExcel::formatoBs($anterior['saldo_final'])
                            . '). Diferencia: Bs. ' . ConciliacionExcel::formatoBs($diff) . '.',
                    ];
                }
                $anterior = $cierre;
            }
        }

        if ($descuadres !== []) {
            throw new DescuadreBancario($descuadres);
        }

        return ['cierres' => array_values($cierres), 'avisos' => array_values(array_unique($avisos))];
    }

    /**
     * Exige la transacción abierta. Si algo no cuadra, lanza y el controlador revierte.
     *
     * @param array{formato:string, saldos:list<array<string, mixed>>, movimientos:list<array<string, mixed>>} $libro
     * @return array{formato:string, cuentas:int, dias:int, movimientos:int, cierres:int, avisos:list<string>}
     */
    public function aplicar(array $libro, string $archivo, int $userId): array
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('La conciliación tiene que correr dentro de una transacción.');
        }
        $cuadre = self::cuadrar($libro);
        $archivo = mb_substr($archivo, 0, 180);

        $ids = [];
        $cuentasTocadas = [];
        $clasificada = ($libro['formato'] ?? '') === 'clasificada';
        $mapa = $clasificada ? $this->mapaCuentas() : [];
        foreach ($cuadre['cierres'] as $cierre) {
            $ids[$cierre['cuenta']] = $clasificada
                ? $this->exigirCuentaActiva($cierre['cuenta'], $mapa)
                : $this->asegurarCuenta($cierre['cuenta']);
            $cuentasTocadas[$cierre['cuenta']] = true;
        }
        $this->bloquearCuentas(array_values($ids), $clasificada);
        $this->exigirContinuidad($cuadre['cierres'], $ids);

        $stmtCierre = $this->db->prepare(
            'INSERT INTO bancos_cierres_diarios
                (cuenta_id, fecha, saldo_inicio, total_ingresos, total_egresos, total_comisiones, saldo_final, estado, archivo, registrado_por)
             VALUES
                (:cuenta_id, :fecha, :saldo_inicio, :total_ingresos, :total_egresos, :total_comisiones, :saldo_final, \'cuadrado\', :archivo, :usuario)'
        );
        $stmtMov = $this->db->prepare(
            'INSERT INTO bancos_movimientos
                (cuenta_id, cierre_id, fecha, tipo, categoria, metodo_pago, banco_origen_destino, descripcion, beneficiario,
                 entidad_relacionada, referencia, monto_bs, comision_bs, monto_usd, tasa_usd_dia, hoja, fila,
                 estado_auditoria, modulo_origen, id_origen, registrado_por)
             VALUES
                (:cuenta_id, :cierre_id, :fecha, :tipo, :categoria, :metodo_pago, :banco_origen_destino, :descripcion, :beneficiario,
                 :entidad, :referencia, :monto_bs, :comision_bs, :monto_usd, :tasa_usd_dia, :hoja, :fila,
                 :estado, :modulo, :id_origen, :usuario)'
        );
        try {
            $cruce = $this->indicesAuditoria();
        } catch (Throwable $e) {
            error_log('[ADARA] auditoria indices ' . $e->getMessage());
            $cruce = ['productores' => [], 'gas' => [], 'nomina' => [], 'transporte' => [], 'clientes' => []];
        }
        $monedas = $this->monedasPorAlias(array_keys($ids));
        $marcar = $this->db->prepare(
            'UPDATE bancos_movimientos
             SET estado_auditoria = :estado, modulo_origen = :modulo, id_origen = :id_origen
             WHERE id = :id'
        );

        $nMov = 0;
        $fechas = [];
        foreach ($cuadre['cierres'] as $cierre) {
            $cuentaId = $ids[$cierre['cuenta']];
            $stmtCierre->execute([
                'cuenta_id' => $cuentaId,
                'fecha' => $cierre['fecha'],
                'saldo_inicio' => ConciliacionExcel::desdeCentavos($cierre['saldo_inicial']),
                'total_ingresos' => ConciliacionExcel::desdeCentavos($cierre['total_ingresos']),
                'total_egresos' => ConciliacionExcel::desdeCentavos($cierre['total_egresos']),
                'total_comisiones' => ConciliacionExcel::desdeCentavos($cierre['total_comisiones']),
                'saldo_final' => ConciliacionExcel::desdeCentavos($cierre['saldo_final']),
                'archivo' => $archivo,
                'usuario' => $userId,
            ]);
            $cierreId = (int) $this->db->lastInsertId();
            foreach ($cierre['movimientos'] as $mov) {
                $moneda = $monedas[$cierre['cuenta']] ?? 'BS';
                $moduloHoja = is_string($mov['modulo_origen'] ?? null) && $mov['modulo_origen'] !== ''
                    ? (string) $mov['modulo_origen']
                    : null;
                $stmtMov->execute([
                    'cuenta_id' => $cuentaId,
                    'cierre_id' => $cierreId,
                    'fecha' => $mov['fecha'],
                    'tipo' => $mov['tipo'],
                    'categoria' => $mov['categoria'],
                    'metodo_pago' => $mov['metodo_pago'] ?? null,
                    'banco_origen_destino' => $mov['banco_origen_destino'] ?? null,
                    'descripcion' => $mov['descripcion'],
                    'beneficiario' => $mov['beneficiario'],
                    'entidad' => $mov['entidad_relacionada'] ?? $mov['beneficiario'] ?? null,
                    'referencia' => $mov['referencia'],
                    'monto_bs' => ConciliacionExcel::desdeCentavos($mov['monto_bs']),
                    'comision_bs' => ConciliacionExcel::desdeCentavos($mov['comision_bs']),
                    'monto_usd' => $this->montoUsdNominal($mov, $moneda),
                    'tasa_usd_dia' => $mov['tasa_usd_dia'],
                    'hoja' => $mov['hoja'],
                    'fila' => $mov['fila'],
                    'estado' => 'huerfano',
                    'modulo' => $moduloHoja,
                    'id_origen' => null,
                    'usuario' => $userId,
                ]);
                $movId = (int) $this->db->lastInsertId();
                $nMov++;
                try {
                    $auditoria = $this->auditarMovimiento($mov, $cruce);
                    $marcar->execute([
                        'estado' => $auditoria['estado'],
                        'modulo' => $auditoria['modulo'],
                        'id_origen' => $auditoria['id'],
                        'id' => $movId,
                    ]);
                } catch (Throwable $e) {
                    error_log('[ADARA] soft-match movimiento ' . $movId . ' ' . $e->getMessage());
                }
            }
            $fechas[$cierre['fecha']] = true;
        }

        $this->refrescarSaldos(array_values($ids));

        return [
            'formato' => $libro['formato'],
            'cuentas' => count($cuentasTocadas),
            'dias' => count($fechas),
            'movimientos' => $nMov,
            'cierres' => count($cuadre['cierres']),
            'avisos' => $cuadre['avisos'],
        ];
    }

    /**
     * Huérfanos del banco y fantasmas de los módulos operativos.
     *
     * @return array{huerfanos:list<array<string, mixed>>, fantasmas:list<array<string, mixed>>}
     */
    public function auditoria(): array
    {
        $huerfanos = $this->db->query(
            "SELECT m.fecha, c.alias_cuenta, c.moneda, m.categoria, m.referencia,
                    m.entidad_relacionada, m.monto_bs, m.monto_usd, m.modulo_origen
             FROM bancos_movimientos m
             JOIN bancos_cuentas c ON c.id = m.cuenta_id
             WHERE m.estado_auditoria = 'huerfano'
             ORDER BY m.fecha DESC, m.id DESC
             LIMIT 200"
        )->fetchAll();

        $fantasmas = array_merge(
            $this->fantasmasClientes(),
            $this->fantasmasProductores(),
            $this->fantasmasNomina(),
            $this->fantasmasTransporte(),
            $this->fantasmasGas()
        );
        usort($fantasmas, static fn (array $a, array $b): int => strcmp((string) $b['fecha'], (string) $a['fecha']));

        return ['huerfanos' => $huerfanos, 'fantasmas' => array_slice($fantasmas, 0, 200)];
    }

    /** @param list<string> $alias @return array<string, string> */
    private function monedasPorAlias(array $alias): array
    {
        if ($alias === []) {
            return [];
        }
        $mapa = [];
        foreach ($this->db->query('SELECT alias_cuenta, moneda FROM bancos_cuentas') as $row) {
            $mapa[ConciliacionExcel::norm((string) $row['alias_cuenta'])] = strtoupper((string) $row['moneda']) === 'USD' ? 'USD' : 'BS';
        }
        $out = [];
        foreach ($alias as $nombre) {
            $clave = ConciliacionExcel::norm((string) $nombre);
            if (isset($mapa[$clave])) {
                $out[(string) $nombre] = $mapa[$clave];
            }
        }
        return $out;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function indicesAuditoria(): array
    {
        $idx = ['productores' => [], 'gas' => [], 'nomina' => [], 'transporte' => [], 'clientes' => []];
        foreach ($this->db->query(
            "SELECT id, referencia FROM productores_abonos
             WHERE anulado_en IS NULL AND tipo = 'pm_trans'
               AND referencia IS NOT NULL AND TRIM(referencia) <> ''
             ORDER BY id"
        ) as $row) {
            $this->indexarRef($idx['productores'], (string) $row['referencia'], (int) $row['id']);
        }
        foreach ($this->db->query(
            "SELECT id, referencia FROM gas_pagos
             WHERE anulado_en IS NULL AND cuenta = 'banco'
               AND referencia IS NOT NULL AND TRIM(referencia) <> ''
             ORDER BY id"
        ) as $row) {
            $this->indexarRef($idx['gas'], (string) $row['referencia'], (int) $row['id']);
        }
        foreach ($this->db->query("SELECT id FROM nomina_semanal WHERE estado = 'pagada'") as $row) {
            $idx['nomina'][(string) $row['id']] = (int) $row['id'];
        }
        foreach ($this->db->query("SELECT id FROM log_pagos_transporte WHERE tipo = 'transferencia'") as $row) {
            $idx['transporte'][(string) $row['id']] = (int) $row['id'];
        }
        foreach ($this->db->query(
            "SELECT id, referencia FROM cxc_pagos
             WHERE metodo IN ('transferencia', 'pago_movil', 'punto')
               AND referencia IS NOT NULL AND TRIM(referencia) <> ''
             ORDER BY id"
        ) as $row) {
            $this->indexarRef($idx['clientes'], (string) $row['referencia'], (int) $row['id']);
        }
        return $idx;
    }

    /** @param array<string, int> $bolsa */
    private function indexarRef(array &$bolsa, string $referencia, int $id): void
    {
        $referencia = trim($referencia);
        if ($referencia !== '' && !isset($bolsa[$referencia])) {
            $bolsa[$referencia] = $id;
        }
        $sinCeros = ltrim($referencia, '0');
        if ($sinCeros !== '' && $sinCeros !== $referencia && !isset($bolsa[$sinCeros])) {
            $bolsa[$sinCeros] = $id;
        }
        $comoId = (string) $id;
        if (!isset($bolsa[$comoId])) {
            $bolsa[$comoId] = $id;
        }
    }

    /** @param array<string, int> $bolsa */
    private static function buscarRef(array $bolsa, string $ref): ?int
    {
        if ($ref !== '' && isset($bolsa[$ref])) {
            return $bolsa[$ref];
        }
        $sinCeros = ltrim($ref, '0');
        if ($sinCeros !== '' && isset($bolsa[$sinCeros])) {
            return $bolsa[$sinCeros];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $mov
     * @param array<string, array<string, int>> $idx
     * @return array{estado:string, modulo:?string, id:?int}
     */
    private function auditarMovimiento(array $mov, array $idx): array
    {
        $modulo = is_string($mov['modulo_origen'] ?? null) && $mov['modulo_origen'] !== ''
            ? (string) $mov['modulo_origen']
            : self::moduloDeCategoria((string) ($mov['categoria'] ?? ''));
        if ($modulo === null) {
            return ['estado' => 'no_aplica', 'modulo' => null, 'id' => null];
        }
        $bolsa = $modulo === 'gastos' ? 'gas' : $modulo;
        $ref = trim((string) ($mov['referencia'] ?? ''));
        $id = self::buscarRef($idx[$bolsa] ?? [], $ref);
        if ($id !== null) {
            return ['estado' => 'conciliado', 'modulo' => $modulo, 'id' => $id];
        }
        return ['estado' => 'huerfano', 'modulo' => $modulo, 'id' => null];
    }

    private static function moduloDeCategoria(string $categoria): ?string
    {
        $n = ConciliacionExcel::norm($categoria);
        return match ($n) {
            'PRODUCTORES', 'PRODUCTOR', 'PAGO PRODUCTOR', 'PAGOS PRODUCTORES' => 'productores',
            'EMPLEADOS', 'EMPLEADO', 'NOMINA' => 'nomina',
            'GAS', 'PROVEEDORES DE GAS', 'PROVEEDOR DE GAS' => 'gas',
            'TRANSPORTISTAS', 'TRANSPORTISTA', 'TRANSPORTE' => 'transporte',
            default => null,
        };
    }

    /** @param array<string, mixed> $mov */
    private function montoUsdNominal(array $mov, string $moneda): string
    {
        if ($mov['monto_usd'] !== null && $mov['monto_usd'] !== '') {
            return (string) $mov['monto_usd'];
        }
        $nominal = ConciliacionExcel::desdeCentavos((int) $mov['monto_bs']);
        if ($moneda === 'USD') {
            return $nominal;
        }
        $tasa = (float) $mov['tasa_usd_dia'];
        if ($tasa <= 0) {
            return $nominal;
        }
        return number_format(((int) $mov['monto_bs']) / 100 / $tasa, 4, '.', '');
    }

    /** @return list<array<string, mixed>> */
    private function fantasmasClientes(): array
    {
        return $this->db->query(
            "SELECT 'clientes' AS modulo, p.id, p.fecha, p.referencia, p.monto, c.nombre AS entidad
             FROM cxc_pagos p
             JOIN cxc_clientes c ON c.id = p.cliente_id
             WHERE p.metodo IN ('transferencia', 'pago_movil', 'punto')
               AND p.referencia IS NOT NULL AND TRIM(p.referencia) <> ''
               AND p.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM bancos_movimientos m
                   WHERE m.modulo_origen = 'clientes' AND m.id_origen = p.id
               )
             ORDER BY p.fecha DESC
             LIMIT 100"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    private function fantasmasProductores(): array
    {
        return $this->db->query(
            "SELECT 'productores' AS modulo, a.id, a.fecha, a.referencia, a.monto, p.nombre AS entidad
             FROM productores_abonos a
             JOIN productores p ON p.id = a.productor_id
             WHERE a.anulado_en IS NULL AND a.tipo = 'pm_trans'
               AND a.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM bancos_movimientos m
                   WHERE m.modulo_origen = 'productores' AND m.id_origen = a.id
               )
             ORDER BY a.fecha DESC
             LIMIT 100"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    private function fantasmasNomina(): array
    {
        return $this->db->query(
            "SELECT 'nomina' AS modulo, n.id, n.semana_inicio AS fecha, CAST(n.id AS CHAR) AS referencia,
                    n.neto_usd AS monto, TRIM(CONCAT(e.nombres, ' ', e.apellidos)) AS entidad
             FROM nomina_semanal n
             JOIN empleados e ON e.id = n.empleado_id
             WHERE n.estado = 'pagada'
               AND n.semana_inicio >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM bancos_movimientos m
                   WHERE m.modulo_origen = 'nomina' AND m.id_origen = n.id
               )
             ORDER BY n.semana_inicio DESC
             LIMIT 100"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    private function fantasmasTransporte(): array
    {
        return $this->db->query(
            "SELECT 'transporte' AS modulo, t.id, t.fecha, CAST(t.id AS CHAR) AS referencia,
                    t.monto, tr.nombre AS entidad
             FROM log_pagos_transporte t
             JOIN log_pagos_semanales s ON s.id = t.pago_semanal_id
             JOIN log_transportistas tr ON tr.id = s.transportista_id
             WHERE t.tipo = 'transferencia'
               AND t.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM bancos_movimientos m
                   WHERE m.modulo_origen = 'transporte' AND m.id_origen = t.id
               )
             ORDER BY t.fecha DESC
             LIMIT 100"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    private function fantasmasGas(): array
    {
        return $this->db->query(
            "SELECT 'gas' AS modulo, g.id, g.fecha, g.referencia, g.monto, p.nombre AS entidad
             FROM gas_pagos g
             JOIN gas_despachos d ON d.id = g.despacho_id
             JOIN gas_proveedores p ON p.id = d.proveedor_id
             WHERE g.anulado_en IS NULL AND g.cuenta = 'banco'
               AND g.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM bancos_movimientos m
                   WHERE m.modulo_origen IN ('gas', 'gastos') AND m.id_origen = g.id
               )
             ORDER BY g.fecha DESC
             LIMIT 100"
        )->fetchAll();
    }

    /**
     * Bloqueo pesimista de las cuentas del libro hasta el COMMIT/ROLLBACK. Dos conciliaciones que
     * tocan la misma cuenta quedan en serie: la segunda espera y valida la continuidad contra los
     * cierres que la primera acaba de confirmar. Orden por id para que dos cargas con las mismas
     * cuentas en distinto orden no se bloqueen en cruz (deadlock).
     *
     * @param list<int> $ids
     */
    private function bloquearCuentas(array $ids, bool $exigirActivas): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        if ($ids === []) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, alias_cuenta, activa FROM bancos_cuentas WHERE id IN ($marcas) ORDER BY id FOR UPDATE"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            if ($exigirActivas && (int) $row['activa'] !== 1) {
                throw new ValidationException([
                    'archivo' => 'La cuenta ' . $row['alias_cuenta'] . ' se desactivó mientras cargabas. Actívala y vuelve a subir el Excel.',
                ]);
            }
        }
    }

    /** @param list<array<string, mixed>> $cierres @param array<string, int> $ids */
    private function exigirContinuidad(array $cierres, array $ids): void
    {
        $porCuenta = [];
        foreach ($cierres as $cierre) {
            $porCuenta[$cierre['cuenta']][] = $cierre;
        }
        // FOR UPDATE: lectura bloqueante = última versión confirmada. Una lectura normal en
        // REPEATABLE READ devolvería el snapshot tomado por mapaCuentas(), anterior a la espera
        // del bloqueo, y no vería el cierre que otra conciliación acaba de confirmar.
        $stmt = $this->db->prepare(
            'SELECT fecha, saldo_inicio, saldo_final
             FROM bancos_cierres_diarios
             WHERE cuenta_id = :cuenta
             ORDER BY fecha
             FOR UPDATE'
        );
        $monedas = $this->monedasPorAlias(array_keys($ids));
        $descuadres = [];
        foreach ($porCuenta as $alias => $nuevos) {
            $stmt->execute(['cuenta' => $ids[$alias]]);
            $cadena = [];
            foreach ($stmt->fetchAll() as $row) {
                $cadena[$row['fecha']] = [
                    'inicio' => self::centavosDecimal((string) $row['saldo_inicio']),
                    'final' => self::centavosDecimal((string) $row['saldo_final']),
                    'nuevo' => false,
                ];
            }
            foreach ($nuevos as $cierre) {
                if (isset($cadena[$cierre['fecha']])) {
                    $descuadres[] = [
                        'cuenta' => $alias,
                        'fecha' => $cierre['fecha'],
                        'motivo' => 'ya_cerrado',
                        'saldo_inicial' => ConciliacionExcel::desdeCentavos($cierre['saldo_inicial']),
                        'saldo_final_declarado' => ConciliacionExcel::desdeCentavos($cierre['saldo_final']),
                        'saldo_final_calculado' => ConciliacionExcel::desdeCentavos($cierre['saldo_final']),
                        'total_ingresos' => ConciliacionExcel::desdeCentavos($cierre['total_ingresos']),
                        'total_egresos' => ConciliacionExcel::desdeCentavos($cierre['total_egresos']),
                        'total_comisiones' => ConciliacionExcel::desdeCentavos($cierre['total_comisiones']),
                        'diferencia' => '0.00',
                        'mensaje' => $alias . ' ya tiene un cierre cuadrado el ' . self::fechaCorta($cierre['fecha']) . '. No se volvió a cargar.',
                    ];
                    continue;
                }
                $cadena[$cierre['fecha']] = [
                    'inicio' => $cierre['saldo_inicial'],
                    'final' => $cierre['saldo_final'],
                    'nuevo' => true,
                ];
            }
            ksort($cadena);
            $anterior = null;
            $fechaAnterior = null;
            foreach ($cadena as $fecha => $punto) {
                if ($anterior !== null && $punto['inicio'] !== $anterior['final'] && ($punto['nuevo'] || $anterior['nuevo'])) {
                    $diff = $punto['inicio'] - $anterior['final'];
                    $moneda = $monedas[$alias] ?? 'BS';
                    $descuadres[] = [
                        'cuenta' => $alias,
                        'fecha' => $fecha,
                        'motivo' => 'continuidad',
                        'saldo_inicial' => ConciliacionExcel::desdeCentavos($punto['inicio']),
                        'saldo_final_calculado' => ConciliacionExcel::desdeCentavos($anterior['final']),
                        'saldo_final_declarado' => ConciliacionExcel::desdeCentavos($punto['inicio']),
                        'total_ingresos' => '0.00',
                        'total_egresos' => '0.00',
                        'total_comisiones' => '0.00',
                        'diferencia' => ConciliacionExcel::desdeCentavos($diff),
                        'mensaje' => $alias . ' el ' . self::fechaCorta($fecha)
                            . ': el saldo inicial (' . self::montoNativo($punto['inicio'], $moneda)
                            . ') tiene que ser igual al saldo final anterior del ' . self::fechaCorta((string) $fechaAnterior)
                            . ' (' . self::montoNativo($anterior['final'], $moneda)
                            . '). El cuadre es en la moneda de la cuenta; la tasa no entra.',
                    ];
                }
                $anterior = $punto;
                $fechaAnterior = $fecha;
            }
        }
        if ($descuadres !== []) {
            throw new DescuadreBancario($descuadres);
        }
    }

    private function asegurarCuenta(string $alias): int
    {
        $stmt = $this->db->prepare('SELECT id FROM bancos_cuentas WHERE alias_cuenta = :alias FOR UPDATE');
        $stmt->execute(['alias' => $alias]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $ins = $this->db->prepare('INSERT INTO bancos_cuentas (alias_cuenta, saldo_actual_bs) VALUES (:alias, 0)');
        $ins->execute(['alias' => $alias]);
        return (int) $this->db->lastInsertId();
    }

    /** @param list<int> $ids */
    private function refrescarSaldos(array $ids): void
    {
        $stmt = $this->db->prepare(
            'UPDATE bancos_cuentas c
             SET saldo_actual_bs = (
                 SELECT d.saldo_final
                 FROM bancos_cierres_diarios d
                 WHERE d.cuenta_id = c.id
                 ORDER BY d.fecha DESC
                 LIMIT 1
             )
             WHERE c.id = :id'
        );
        foreach ($ids as $id) {
            $stmt->execute(['id' => $id]);
        }
    }

    /** @param array<string, array<string, mixed>> $grupos */
    private static function cuentaCercana(string $cuenta, string $fecha, array $grupos): ?string
    {
        $mejor = null;
        $mejorDist = 3;
        $empate = false;
        foreach ($grupos as $grupo) {
            if ($grupo['fecha'] !== $fecha) {
                continue;
            }
            $candidato = $grupo['cuenta'];
            if (abs(strlen($cuenta) - strlen($candidato)) > 2) {
                continue;
            }
            $dist = levenshtein($cuenta, $candidato);
            if ($dist < 1 || $dist > 2) {
                continue;
            }
            if ($dist < $mejorDist) {
                $mejor = $candidato;
                $mejorDist = $dist;
                $empate = false;
            } elseif ($dist === $mejorDist && $candidato !== $mejor) {
                $empate = true;
            }
        }
        return $empate ? null : $mejor;
    }

    /** @param array<string, mixed> $grupo */
    private static function descuadre(array $grupo, int $calculado, int $diferencia): array
    {
        $signo = $diferencia > 0 ? 'de más' : 'de menos';
        return [
            'cuenta' => $grupo['cuenta'],
            'fecha' => $grupo['fecha'],
            'motivo' => 'formula',
            'saldo_inicial' => ConciliacionExcel::desdeCentavos($grupo['saldo_inicial']),
            'total_ingresos' => ConciliacionExcel::desdeCentavos($grupo['total_ingresos']),
            'total_egresos' => ConciliacionExcel::desdeCentavos($grupo['total_egresos']),
            'total_comisiones' => ConciliacionExcel::desdeCentavos($grupo['total_comisiones']),
            'saldo_final_calculado' => ConciliacionExcel::desdeCentavos($calculado),
            'saldo_final_declarado' => ConciliacionExcel::desdeCentavos($grupo['saldo_final']),
            'diferencia' => ConciliacionExcel::desdeCentavos($diferencia),
            'mensaje' => $grupo['cuenta'] . ' el ' . self::fechaCorta($grupo['fecha'])
                . ' no cuadra. Declarado Bs. ' . ConciliacionExcel::formatoBs($grupo['saldo_final'])
                . ', calculado Bs. ' . ConciliacionExcel::formatoBs($calculado)
                . ' (inicial ' . ConciliacionExcel::formatoBs($grupo['saldo_inicial'])
                . ' + ingresos ' . ConciliacionExcel::formatoBs($grupo['total_ingresos'])
                . ' − egresos ' . ConciliacionExcel::formatoBs($grupo['total_egresos'])
                . ' − comisiones ' . ConciliacionExcel::formatoBs($grupo['total_comisiones'])
                . '). El Excel trae Bs. ' . ConciliacionExcel::formatoBs(abs($diferencia)) . ' ' . $signo . '.',
        ];
    }

    private static function montoNativo(int $cents, string $moneda): string
    {
        return str_replace('.', ',', ConciliacionExcel::desdeCentavos($cents)) . ' ' . $moneda;
    }

    private static function centavosDecimal(string $decimal): int
    {
        return ConciliacionExcel::centavos($decimal, 'base', 0, 'saldo');
    }

    private static function fechaCorta(string $iso): string
    {
        $p = explode('-', $iso);
        return count($p) === 3 ? $p[2] . '/' . $p[1] . '/' . $p[0] : $iso;
    }
}
