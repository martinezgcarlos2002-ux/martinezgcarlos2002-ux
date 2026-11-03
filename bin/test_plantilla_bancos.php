<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

$fallos = 0;
function ok(bool $pasa, string $msg): void
{
    global $fallos;
    echo ($pasa ? 'OK  ' : 'FALLO ') . $msg . PHP_EOL;
    if (!$pasa) {
        $fallos++;
    }
}

$fecha = new DateTimeImmutable('2026-10-27');
$tmp = PlantillaLibroDiario::crear(['CUENTA NUEVA'], '886.22', $fecha);
$zip = new ZipArchive();
$zip->open($tmp);
$wb = $zip->getFromName('xl/workbook.xml');
$hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();

ok(str_contains($wb, 'name="27-10-2026"'), 'la hoja se llama como el día del libro');
ok(str_contains($hoja, 'PROVEEDORES DE LECHE Y TRANSPORTISTAS'), 'está el bloque de proveedores');
ok(str_contains($hoja, 'GASTOS GENERALES'), 'está el bloque de gastos');
ok(str_contains($hoja, 'INGRESOS'), 'está el bloque de ingresos');
ok(substr_count($hoja, '>BANCO<') === 3, 'hay tres tablas con encabezado BANCO');
ok(str_contains($hoja, 'BANCO CENTRAL') && str_contains($hoja, '<v>886.22</v>'), 'la tasa queda en Banco Central');
ok(str_contains($hoja, 'INICIO') && str_contains($hoja, 'FINAL'), 'está el cuadro de saldos');
ok(str_contains($hoja, 'BNC EMPRESA') && str_contains($hoja, 'BANCAMIGA JUAN C') && str_contains($hoja, 'CUENTA NUEVA'), 'el cuadro trae las cuentas del libro y las de ADARA');
ok(str_contains($hoja, 'C8/$E$3'), 'la columna $ divide el monto entre la tasa');
ok(str_contains($hoja, 'inicio en $') && str_contains($hoja, 'C94/$E$3'), 'inicio en $ usa la tasa del día y no divide una celda vacía');
ok(substr_count($hoja, '0.015') === 50, 'la comisión solo está en proveedores y gastos, no en ingresos');
ok(str_contains($hoja, '$A$94:$A$105') || str_contains($hoja, 'formula1>$A$'), 'el banco se elige de la lista del cuadro final');
preg_match('/<row r="8">(.*?)<\/row>/', $hoja, $fila);
preg_match_all('/r="([A-Z]+)8"/', $fila[1] ?? '', $orden);
ok(implode('', $orden[1] ?? []) === 'DE', 'comisión y dólar van en orden, D y luego E');

$vacio = false;
try {
    ConciliacionExcel::leer($tmp);
} catch (ValidationException $e) {
    $vacio = str_contains($e->errors['archivo'] ?? '', 'No reconozco') || str_contains($e->errors['archivo'] ?? '', 'no trae saldos');
}
ok($vacio, 'una hoja vacía no se carga como conciliación');

unlink($tmp);
echo $fallos === 0 ? "TODO OK\n" : "FALLOS $fallos\n";
exit($fallos === 0 ? 0 : 1);
