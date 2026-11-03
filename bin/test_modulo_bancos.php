<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

$fallos = 0;

function chequeo(bool $ok, string $mensaje): void
{
    global $fallos;
    echo ($ok ? 'OK  ' : 'FALLO ') . $mensaje . PHP_EOL;
    if (!$ok) {
        $fallos++;
    }
}

$libroOk = [
    'formato' => 'plantilla',
    'saldos' => [[
        'cuenta' => 'BNC EMPRESA',
        'fecha' => '2099-01-02',
        'saldo_inicial' => 100000,
        'saldo_final' => 85000,
        'hoja' => 'Saldos',
        'fila' => 3,
    ]],
    'movimientos' => [[
        'cuenta' => 'BNC EMPRESA',
        'fecha' => '2099-01-02',
        'tipo' => 'egreso',
        'categoria' => 'Gastos generales',
        'descripcion' => 'Prueba',
        'beneficiario' => null,
        'referencia' => '1',
        'monto_bs' => 15000,
        'comision_bs' => 0,
        'monto_usd' => null,
        'tasa_usd_dia' => '100.000000',
        'hoja' => 'Movimientos',
        'fila' => 3,
    ]],
];
$cuadre = Banco::cuadrar($libroOk);
chequeo(count($cuadre['cierres']) === 1, 'un cierre cuando 1000 − 150 = 850');

$libroMal = $libroOk;
$libroMal['saldos'][0]['saldo_final'] = 80000;
$atrapo = false;
try {
    Banco::cuadrar($libroMal);
} catch (DescuadreBancario $e) {
    $atrapo = ($e->descuadres[0]['diferencia'] ?? '') === '-50.00';
}
chequeo($atrapo, 'descuadre de 50,00 cuando el final declarado no cierra');

$typo = $libroOk;
$typo['movimientos'][0]['cuenta'] = 'BNC EMRESA';
$corregido = Banco::cuadrar($typo);
chequeo($corregido['avisos'] !== [], 'BNC EMRESA se acerca a BNC EMPRESA');

$tmp = tempnam(sys_get_temp_dir(), 'adara') . '.xlsx';
file_put_contents($tmp, ConciliacionExcel::plantilla()->contenido());
$ejemploVacio = false;
try {
    ConciliacionExcel::leer($tmp);
} catch (ValidationException $e) {
    $ejemploVacio = str_contains($e->errors['archivo'] ?? '', 'no trae saldos');
}
chequeo($ejemploVacio, 'la plantilla modelo no se carga con la fila de ejemplo');
unlink($tmp);

$octubre = $argv[1] ?? 'D:\\admin castillo del queso\\CONCILIACIONES AÑO 2026\\10) CONCILIACIONES BANCARIAS OCTUBRE 2026.xlsx';
if (is_file($octubre)) {
    $real = ConciliacionExcel::leer($octubre);
    $cuentas = [];
    foreach ($real['saldos'] as $s) {
        $cuentas[$s['cuenta']] = true;
    }
    echo 'Libro octubre: ' . count($real['saldos']) . ' saldos, ' . count($real['movimientos']) . ' movimientos, ' . count($cuentas) . ' cuentas' . PHP_EOL;
    $nomina = 0;
    foreach ($real['movimientos'] as $m) {
        if ($m['categoria'] === 'Nómina') {
            $nomina++;
        }
    }
    echo 'Lineas de nomina: ' . $nomina . PHP_EOL;
    try {
        Banco::cuadrar($real);
        echo 'El mes de octubre cuadra completo.' . PHP_EOL;
    } catch (DescuadreBancario $e) {
        echo 'Descuadres en octubre: ' . count($e->descuadres) . PHP_EOL;
        foreach (array_slice($e->descuadres, 0, 6) as $d) {
            echo '  ' . $d['mensaje'] . PHP_EOL;
        }
    } catch (ValidationException $e) {
        echo 'Estructura: ' . ($e->errors['archivo'] ?? '') . PHP_EOL;
        $fallos++;
    }
} else {
    echo 'Sin libro de octubre en el argumento; se omite esa prueba.' . PHP_EOL;
}

$pdo = get_db();
$usuario = (int) $pdo->query('SELECT id FROM usuarios WHERE eliminado_en IS NULL ORDER BY id LIMIT 1')->fetchColumn();
chequeo($usuario > 0, 'hay un usuario para registrar el cierre de prueba');

$banco = new Banco($pdo);
$pdo->beginTransaction();
try {
    $resumen = $banco->aplicar($libroOk, 'prueba.xlsx', $usuario);
    chequeo($resumen['movimientos'] === 1 && $resumen['cierres'] === 1, 'la transaccion inserta el dia de prueba');
    $saldo = $pdo->query("SELECT saldo_actual_bs FROM bancos_cuentas WHERE alias_cuenta = 'BNC EMPRESA'")->fetchColumn();
    chequeo($saldo === '850.00', 'saldo actual queda en 850.00 y no en el valor de prueba si ya habia historia');
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'FALLO transaccion ' . $e->getMessage() . PHP_EOL;
    $fallos++;
}

$queda = $pdo->query("SELECT COUNT(*) FROM bancos_cierres_diarios WHERE fecha = '2099-01-02'")->fetchColumn();
chequeo((int) $queda === 0, 'el rollback no deja el cierre de prueba');

echo $fallos === 0 ? "TODO OK\n" : "FALLOS $fallos\n";
exit($fallos === 0 ? 0 : 1);
