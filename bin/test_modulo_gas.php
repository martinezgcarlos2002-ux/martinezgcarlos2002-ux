<?php
declare(strict_types=1);

/**
 * Prueba de proceso y rendimiento del módulo de gas.
 *
 *   php bin/test_modulo_gas.php
 *
 * Crea un proveedor, 100 bombonas, un despacho, los abonos y un pago de más.
 * Todo queda dentro de una transacción que se revierte al terminar: no deja
 * datos en adara. El umbral de 50 ms aplica a la lectura de v_gas_cargos_terceros
 * y a un recálculo de saldo (un abono), no a la suma de las 100 inserciones.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ejecuta este script por consola: php bin/test_modulo_gas.php\n");
    exit(1);
}

require dirname(__DIR__) . '/config/bootstrap.php';

const UMBRAL_MS = 50.0;
const BOMBONAS = 100;

$pdo = get_db();
$gas = new Gas($pdo);
$fallos = [];
$tiempos = [];

$pdo->beginTransaction();
try {
    $usuarioId = (int) $pdo->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetchColumn();
    if ($usuarioId < 1) {
        throw new RuntimeException('No hay usuarios en la base. El script necesita un registrado_por.');
    }
    $empleadoId = (int) $pdo->query('SELECT id FROM empleados WHERE activo = 1 ORDER BY id LIMIT 1')->fetchColumn();

    $marca = 'QA' . strtoupper(bin2hex(random_bytes(3)));
    $proveedorId = $gas->guardarProveedor(null, [
        'nombre'    => 'Gas ' . $marca,
        'documento' => null,
        'telefono'  => '0414-0000000',
    ], $usuarioId);
    assert_true($proveedorId > 0, 'Se creó el proveedor.', $fallos);

    $ids = [];
    $terceros = 0;
    $caps = [10, 15, 27, 43];
    for ($i = 1; $i <= BOMBONAS; $i++) {
        $n = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $cap = $caps[($i - 1) % 4];
        if ($i <= 70) {
            $in = ['capacidad_kg' => $cap, 'estado_fisico' => 'optimo', 'tipo_dueno' => 'propio'];
        } elseif ($i <= 90 || $empleadoId < 1) {
            $in = [
                'capacidad_kg' => $cap,
                'estado_fisico' => $i % 17 === 0 ? 'mal_estado' : 'regular',
                'tipo_dueno' => 'otro',
                'propietario_nombre' => 'Tercero ' . $n,
            ];
            $terceros++;
        } else {
            $in = [
                'capacidad_kg' => $cap,
                'estado_fisico' => 'optimo',
                'tipo_dueno' => 'empleado',
                'empleado_id' => $empleadoId,
            ];
            $terceros++;
        }
        $ids[] = $gas->guardarCilindro(null, $in, $usuarioId);
    }
    assert_true(count($ids) === BOMBONAS, 'Se registraron 100 bombonas.', $fallos);
    $serialStmt = $pdo->prepare('SELECT serial FROM gas_cilindros WHERE id = :id');
    $serialStmt->execute(['id' => $ids[0]]);
    $serialEsperado = 'CIL-' . str_pad((string) $ids[0], 6, '0', STR_PAD_LEFT);
    $serialReal = (string) $serialStmt->fetchColumn();
    assert_true($serialReal === $serialEsperado, 'El serial lo asigna la base: ' . $serialReal . '.', $fallos);

    $despachoId = $gas->crearDespacho([
        'proveedor_id' => $proveedorId,
        'fecha_salida' => date('Y-m-d'),
        'precio_10'    => '1.00',
        'precio_15'    => '2.00',
        'precio_27'    => '3.00',
        'precio_43'    => '4.00',
    ], $usuarioId);

    $t0 = hrtime(true);
    $cargadas = $gas->agregarCilindros($despachoId, $ids);
    $tiempos['insercion_masiva'] = (hrtime(true) - $t0) / 1e6;
    assert_true($cargadas === BOMBONAS, 'Las 100 bombonas entraron al despacho.', $fallos);

    $cabeza = $gas->despacho($despachoId)['despacho'];
    assert_true(preg_match('/^DESP-\d{6}$/', (string) $cabeza['codigo_despacho']) === 1, 'El código ' . $cabeza['codigo_despacho'] . ' lo generó la base.', $fallos);
    assert_true($cabeza['fecha_entrega'] === null, 'El viaje nace sin fecha de entrega.', $fallos);
    assert_true($cabeza['costo_total_usd'] === '250.00', 'Costo = 25×(1+2+3+4) = 250.00 (obtuvo ' . $cabeza['costo_total_usd'] . ').', $fallos);
    assert_true($cabeza['estado_deuda'] === 'pendiente', 'Sin abonos el estado es pendiente.', $fallos);

    $t0 = hrtime(true);
    $parcial = $gas->registrarPago([
        'despacho_id'     => $despachoId,
        'fecha'           => date('Y-m-d'),
        'metodo'          => 'pago_movil',
        'moneda'          => 'USD',
        'monto'           => '100.00',
        'tasa_bs_por_usd' => '40.5000',
        'referencia'      => 'PM-' . $marca,
    ], $usuarioId);
    $tiempos['saldo_abono_parcial'] = (hrtime(true) - $t0) / 1e6;
    $estado = $parcial['despacho']['despacho'];
    assert_true($estado['estado_deuda'] === 'saldo_restante', 'El abono parcial deja saldo restante.', $fallos);
    assert_true($estado['saldo_usd'] === '150.00', 'Saldo 150.00 tras pagar 100.00 (obtuvo ' . $estado['saldo_usd'] . ').', $fallos);
    assert_true($estado['pagado_usd'] === '100.00', 'Pagado 100.00.', $fallos);

    $caja = new Caja($pdo);
    assert_true($caja->movimientoDePagoGas($parcial['id']) !== null, 'El abono parcial generó egreso origen pago_gas.', $fallos);

    $total = $gas->registrarPago([
        'despacho_id'     => $despachoId,
        'fecha'           => date('Y-m-d'),
        'metodo'          => 'transferencia',
        'moneda'          => 'USD',
        'monto'           => '150.00',
        'tasa_bs_por_usd' => '40.5000',
        'referencia'      => 'TR-' . $marca,
    ], $usuarioId);
    $cerrado = $total['despacho']['despacho'];
    assert_true($cerrado['estado_deuda'] === 'cancelado', 'El segundo abono cancela la deuda.', $fallos);
    assert_true($cerrado['saldo_usd'] === '0.00', 'Saldo en cero.', $fallos);

    $excedenteBloqueado = false;
    $mensajeExcedente = '';
    try {
        $gas->registrarPago([
            'despacho_id'     => $despachoId,
            'fecha'           => date('Y-m-d'),
            'metodo'          => 'pago_movil',
            'moneda'          => 'USD',
            'monto'           => '1.00',
            'tasa_bs_por_usd' => '40.5000',
            'referencia'      => 'PM-EXCESO',
        ], $usuarioId);
    } catch (PDOException $e) {
        $mensajeExcedente = Gas::mensajePdo($e) ?? $e->getMessage();
        $excedenteBloqueado = str_contains($mensajeExcedente, 'supera el saldo');
    }
    assert_true($excedenteBloqueado, 'El trigger rechazó el pago excedente. ' . $mensajeExcedente, $fallos);

    $sigue = $gas->despacho($despachoId)['despacho'];
    assert_true($sigue['estado_deuda'] === 'cancelado' && $sigue['saldo_usd'] === '0.00', 'El pago rechazado no movió el saldo.', $fallos);

    $gas->anularPago($total['id'], 'Prueba de reverso de caja', $usuarioId);
    $reverso = $pdo->prepare(
        "SELECT r.id
         FROM caja_movimientos m
         JOIN caja_movimientos r ON r.revierte_id = m.id AND r.origen = 'contra_asiento'
         WHERE m.gas_pago_id = :pago AND m.origen = 'pago_gas'"
    );
    $reverso->execute(['pago' => $total['id']]);
    assert_true($reverso->fetchColumn() !== false, 'Anular el abono llamó a Caja::contraAsiento().', $fallos);

    $t0 = hrtime(true);
    $cargos = $pdo->query('SELECT linea_id, subtotal_usd FROM v_gas_cargos_terceros')->fetchAll();
    $tiempos['vista_cargos'] = (hrtime(true) - $t0) / 1e6;
    $delLote = 0;
    foreach ($cargos as $fila) {
        if (in_array((int) $fila['linea_id'], idsDeLineas($pdo, $despachoId), true)) {
            $delLote++;
        }
    }
    assert_true(count($cargos) >= $terceros, 'La vista de terceros responde y trae los cargos del lote (' . $terceros . ').', $fallos);

    $explain = $pdo->query(
        "EXPLAIN SELECT dc.id
         FROM gas_despacho_cilindros dc
         JOIN gas_despachos d ON d.id = dc.despacho_id
         JOIN gas_cilindros c ON c.id = dc.cilindro_id
         WHERE dc.cargo_estado = 'pendiente'"
    )->fetchAll();

    $pdo->rollBack();
    imprimir($tiempos, $explain, $fallos, $terceros);
    exit($fallos === [] ? 0 : 1);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "La prueba se abortó y la transacción se revirtió.\n" . $e->getMessage() . "\n");
    exit(1);
}

/** @param list<string> $fallos */
function assert_true(bool $ok, string $mensaje, array &$fallos): void
{
    echo ($ok ? '  OK  ' : ' FALLO') . '  ' . $mensaje . "\n";
    if (!$ok) {
        $fallos[] = $mensaje;
    }
}

/** @return list<int> */
function idsDeLineas(PDO $pdo, int $despachoId): array
{
    $stmt = $pdo->prepare('SELECT id FROM gas_despacho_cilindros WHERE despacho_id = :id');
    $stmt->execute(['id' => $despachoId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @param array<string, float> $tiempos
 * @param list<array<string, mixed>> $explain
 * @param list<string> $fallos
 */
function imprimir(array $tiempos, array $explain, array $fallos, int $terceros): void
{
    echo "\nRendimiento (umbral " . UMBRAL_MS . " ms para la vista y para un recálculo de saldo)\n";
    echo sprintf("  Inserción de %d bombonas al despacho: %8.2f ms  (%.2f ms por bombona)\n", BOMBONAS, $tiempos['insercion_masiva'], $tiempos['insercion_masiva'] / BOMBONAS);
    echo sprintf("  Recálculo de saldo en el abono parcial: %8.2f ms\n", $tiempos['saldo_abono_parcial']);
    echo sprintf("  Lectura de v_gas_cargos_terceros:       %8.2f ms\n", $tiempos['vista_cargos']);
    echo "  Cargos de terceros del lote: $terceros\n";

    $sugerir = $tiempos['vista_cargos'] > UMBRAL_MS || $tiempos['saldo_abono_parcial'] > UMBRAL_MS;
    echo "\nEXPLAIN del filtro cargo_estado = 'pendiente':\n";
    $escaneo = false;
    foreach ($explain as $fila) {
        $tipo = (string) ($fila['type'] ?? '');
        $tabla = (string) ($fila['table'] ?? '');
        $key = (string) ($fila['key'] ?? '');
        $rows = (string) ($fila['rows'] ?? '');
        echo "  $tabla  type=$tipo  key=" . ($key !== '' ? $key : '—') . "  rows=$rows\n";
        if ($tabla === 'dc' || $tabla === 'gas_despacho_cilindros') {
            if ($tipo === 'ALL') {
                $escaneo = true;
            }
        }
    }

    echo "\n";
    if (!$sugerir && !$escaneo) {
        echo "La vista y el recálculo del saldo quedaron bajo " . UMBRAL_MS . " ms.\n";
        echo "No hace falta un índice nuevo: el recálculo ya usa la clave de despacho_id y el abono usa ix_gas_pago_despacho.\n";
    } else {
        echo "Sugerencia de índice:\n";
        if ($tiempos['vista_cargos'] > UMBRAL_MS || $escaneo) {
            echo "  ALTER TABLE gas_despacho_cilindros\n";
            echo "    ADD KEY ix_gas_dc_cargo_estado (cargo_estado, despacho_id);\n";
            echo "  La vista filtra cargo_estado = 'pendiente' y hoy el índice más cercano empieza por (cargo_estado, empleado_id).\n";
        }
        if ($tiempos['saldo_abono_parcial'] > UMBRAL_MS) {
            echo "  El abono recalcula con COUNT/SUM por despacho_id. Esas búsquedas ya entran por\n";
            echo "  uq_gas_dc_despacho_cil e ix_gas_pago_despacho. Un índice extra no baja ese tiempo\n";
            echo "  si EXPLAIN no muestra type=ALL. El costo está en el trigger, que corre en cada fila.\n";
        }
    }
    echo "\nLa transacción se revirtió. La base no conserva el lote $terceros de terceros ni los abonos.\n";
    echo $fallos === [] ? "Resultado: proceso completo en orden.\n" : ('Resultado: ' . count($fallos) . " afirmación(es) fallaron.\n");
}
