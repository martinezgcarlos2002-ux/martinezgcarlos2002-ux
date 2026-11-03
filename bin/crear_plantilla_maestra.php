<?php
declare(strict_types=1);

/**
 * Arma storage/plantillas/plantilla_maestra.xlsx.
 * Hojas: Saldos, Movimientos (tablas + validación) y Parametros (oculta).
 */
$destino = dirname(__DIR__) . '/storage/plantillas/plantilla_maestra.xlsx';
if (!is_dir(dirname($destino))) {
    mkdir(dirname($destino), 0775, true);
}

$strings = [
    'Cuenta', 'Fecha', 'Saldo Inicial', 'Saldo Final Declarado',
    'Tipo', 'Categoría', 'Descripción', 'Referencia', 'Monto BS', 'Método',
    'Banco Origen/Destino', 'Comisión BS', 'Tasa USD', 'Beneficiario', 'Banco',
    'Empleado', 'Productor', 'Transportista', 'Proveedor Gas', 'Moneda', 'Monto USD',
];
// [#This Row] y no [@Columna]: Excel repara el libro y borra la tabla Movimientos
// si la fórmula guardada usa el atajo @.
$formulaComision = 'IF(Movimientos[[#This Row],[Monto BS]]="", "", IF(Movimientos[[#This Row],[Método]]="Pago Móvil", ROUND(Movimientos[[#This Row],[Monto BS]]*0.015, 2), IF(Movimientos[[#This Row],[Método]]="Transferencia Otros Bancos", ROUND(Movimientos[[#This Row],[Monto BS]]*0.003, 2), 0)))';
$formulaUsd = 'IF(Movimientos[[#This Row],[Monto BS]]="", "", IF(IFERROR(VLOOKUP(Movimientos[[#This Row],[Cuenta]],Parametros!$A$2:$H$500,8,FALSE),"BS")="USD", Movimientos[[#This Row],[Monto BS]], IF(Movimientos[[#This Row],[Tasa USD]]="", "", ROUND(Movimientos[[#This Row],[Monto BS]]/Movimientos[[#This Row],[Tasa USD]], 2))))';
$ss = '';
foreach ($strings as $s) {
    $ss .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
}
$shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">'
    . $ss . '</sst>';

$saldos = hoja('A1:D2', encabezado([0, 1, 2, 3]), null, 1);
$movs = hoja(
    'A1:M2',
    encabezado([0, 1, 4, 5, 6, 7, 8, 20, 9, 10, 11, 12, 13])
        . '<row r="2"><c r="H2"><f>' . htmlspecialchars($formulaUsd, ENT_XML1) . '</f></c>'
        . '<c r="K2"><f>' . htmlspecialchars($formulaComision, ENT_XML1) . '</f></c><c r="L2"/></row>',
    validacionMovimientos(),
    1,
    '<cols>'
        . '<col min="1" max="1" width="24" customWidth="1"/>'
        . '<col min="2" max="2" width="14" customWidth="1"/>'
        . '<col min="3" max="3" width="14" customWidth="1"/>'
        . '<col min="4" max="4" width="28" customWidth="1"/>'
        . '<col min="5" max="5" width="34" customWidth="1"/>'
        . '<col min="6" max="6" width="16" customWidth="1"/>'
        . '<col min="7" max="7" width="16" customWidth="1"/>'
        . '<col min="8" max="8" width="16" customWidth="1"/>'
        . '<col min="9" max="9" width="30" customWidth="1"/>'
        . '<col min="10" max="10" width="32" customWidth="1"/>'
        . '<col min="11" max="11" width="16" customWidth="1"/>'
        . '<col min="12" max="12" width="14" customWidth="1"/>'
        . '<col min="13" max="13" width="24" customWidth="1"/>'
        . '</cols>'
);
$param = hoja('A1:H2', encabezado([0, 14, 9, 15, 16, 17, 18, 19]), null, 7);

$files = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '<Override PartName="/xl/tables/table2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '<Override PartName="/xl/tables/table3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '<Override PartName="/xl/tables/table4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '<Override PartName="/xl/tables/table5.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '<Override PartName="/xl/tables/table6.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '<Override PartName="/xl/tables/table7.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '<Override PartName="/xl/tables/table8.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '<Override PartName="/xl/tables/table9.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '</Types>',
    '_rels/.rels' => rels([
        ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'xl/workbook.xml'],
    ]),
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView activeTab="1"/></bookViews>'
        . '<sheets>'
        . '<sheet name="Saldos" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Movimientos" sheetId="2" r:id="rId2"/>'
        . '<sheet name="Parametros" sheetId="3" r:id="rId3"/>'
        . '</sheets>'
        . '<definedNames>'
        . '<definedName name="ListaCuentas">Parametros!$A$2:$A$2</definedName>'
        . '<definedName name="ListaBancos">Parametros!$B$2:$B$2</definedName>'
        . '<definedName name="ListaMetodos">Parametros!$C$2:$C$4</definedName>'
        . '<definedName name="ListaEmpleados">Parametros!$D$2:$D$2</definedName>'
        . '<definedName name="ListaProductores">Parametros!$E$2:$E$2</definedName>'
        . '<definedName name="ListaTransportistas">Parametros!$F$2:$F$2</definedName>'
        . '<definedName name="ListaGas">Parametros!$G$2:$G$2</definedName>'
        . '<definedName name="ListaMonedas">Parametros!$H$2:$H$2</definedName>'
        . '</definedNames>'
        . '</workbook>',
    'xl/_rels/workbook.xml.rels' => rels([
        ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'worksheets/sheet1.xml'],
        ['rId2', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'worksheets/sheet2.xml'],
        ['rId3', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'worksheets/sheet3.xml'],
        ['rId4', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles', 'styles.xml'],
        ['rId5', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings', 'sharedStrings.xml'],
    ]),
    'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>',
    'xl/sharedStrings.xml' => $shared,
    'xl/worksheets/sheet1.xml' => $saldos,
    'xl/worksheets/sheet2.xml' => $movs,
    'xl/worksheets/sheet3.xml' => $param,
    'xl/worksheets/_rels/sheet1.xml.rels' => rels([
        ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table', '../tables/table1.xml'],
    ]),
    'xl/worksheets/_rels/sheet2.xml.rels' => rels([
        ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table', '../tables/table2.xml'],
    ]),
    'xl/worksheets/_rels/sheet3.xml.rels' => rels([
        ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table', '../tables/table3.xml'],
        ['rId2', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table', '../tables/table4.xml'],
        ['rId3', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table', '../tables/table5.xml'],
        ['rId4', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table', '../tables/table6.xml'],
        ['rId5', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table', '../tables/table7.xml'],
        ['rId6', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table', '../tables/table8.xml'],
        ['rId7', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table', '../tables/table9.xml'],
    ]),
    'xl/tables/table1.xml' => tabla(1, 'Saldos', 'A1:D2', ['Cuenta', 'Fecha', 'Saldo Inicial', 'Saldo Final Declarado']),
    'xl/tables/table2.xml' => tabla(2, 'Movimientos', 'A1:M2', [
        'Cuenta', 'Fecha', 'Tipo', 'Categoría', 'Descripción', 'Referencia', 'Monto BS', 'Monto USD',
        'Método', 'Banco Origen/Destino', 'Comisión BS', 'Tasa USD', 'Beneficiario',
    ], ['Monto USD' => $formulaUsd, 'Comisión BS' => $formulaComision]),
    'xl/tables/table3.xml' => tabla(3, 'Cuentas', 'A1:A2', ['Cuenta']),
    'xl/tables/table4.xml' => tabla(4, 'Bancos', 'B1:B2', ['Banco']),
    'xl/tables/table5.xml' => tabla(5, 'Metodos', 'C1:C2', ['Método']),
    'xl/tables/table6.xml' => tabla(6, 'Empleados', 'D1:D2', ['Empleado']),
    'xl/tables/table7.xml' => tabla(7, 'Productores', 'E1:E2', ['Productor']),
    'xl/tables/table8.xml' => tabla(8, 'Transportistas', 'F1:F2', ['Transportista']),
    'xl/tables/table9.xml' => tabla(9, 'Gas', 'G1:G2', ['Proveedor Gas']),
];

if (is_file($destino)) {
    unlink($destino);
}
$zip = new ZipArchive();
if ($zip->open($destino, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "no pude crear el xlsx\n");
    exit(1);
}
foreach ($files as $nombre => $contenido) {
    $zip->addFromString($nombre, $contenido);
}
$zip->close();
echo "OK $destino\n";

/** @param list<int> $indices */
function encabezado(array $indices): string
{
    $xml = '<row r="1">';
    foreach ($indices as $i => $ss) {
        $xml .= '<c r="' . colLetra($i + 1) . '1" s="1" t="s"><v>' . $ss . '</v></c>';
    }
    return $xml . '</row>';
}

function colLetra(int $n): string
{
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

function hoja(string $dimension, string $rows, ?string $validacion, int $tablas, string $cols = ''): string
{
    $parts = '';
    for ($i = 1; $i <= $tablas; $i++) {
        $parts .= '<tablePart r:id="rId' . $i . '"/>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . $cols
        . '<sheetData>' . $rows . '</sheetData>'
        . ($validacion ?? '')
        . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
        . '<tableParts count="' . $tablas . '">' . $parts . '</tableParts>'
        . '</worksheet>';
}

function validacionMovimientos(): string
{
    return '<dataValidations count="4">'
        . '<dataValidation type="list" allowBlank="1" showDropDown="0" showErrorMessage="1" errorTitle="Cuenta" error="Elige una cuenta de la lista." sqref="A2:A500">'
        . '<formula1>ListaCuentas</formula1></dataValidation>'
        . '<dataValidation type="list" allowBlank="1" showDropDown="0" showErrorMessage="1" errorTitle="Tipo" error="Solo Ingreso o Egreso." sqref="C2:C500">'
        . '<formula1>"Ingreso,Egreso"</formula1></dataValidation>'
        . '<dataValidation type="list" allowBlank="1" showDropDown="0" showErrorMessage="1" errorTitle="Método" error="Elige el método de pago." sqref="I2:I500">'
        . '<formula1>ListaMetodos</formula1></dataValidation>'
        . '<dataValidation type="list" allowBlank="1" showDropDown="0" showErrorMessage="1" errorTitle="Banco" error="Elige el banco origen o destino." sqref="J2:J500">'
        . '<formula1>ListaBancos</formula1></dataValidation>'
        . '</dataValidations>';
}

/** @param list<string> $columnas @param array<string, string> $formulas */
function tabla(int $id, string $nombre, string $ref, array $columnas, array $formulas = []): string
{
    $cols = '';
    foreach ($columnas as $i => $nombreCol) {
        $formula = $formulas[$nombreCol] ?? null;
        $cols .= '<tableColumn id="' . ($i + 1) . '" name="' . htmlspecialchars($nombreCol, ENT_XML1) . '">';
        if ($formula !== null) {
            $cols .= '<calculatedColumnFormula>' . htmlspecialchars($formula, ENT_XML1) . '</calculatedColumnFormula>';
        }
        $cols .= '</tableColumn>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="' . $id . '" name="' . $nombre . '" displayName="' . $nombre . '" ref="' . $ref . '" headerRowCount="1" totalsRowCount="0">'
        . '<autoFilter ref="' . $ref . '"/>'
        . '<tableColumns count="' . count($columnas) . '">' . $cols . '</tableColumns>'
        . '<tableStyleInfo name="TableStyleMedium2" showRowStripes="1"/>'
        . '</table>';
}

/** @param list<array{0:string,1:string,2:string}> $items */
function rels(array $items): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($items as [$id, $tipo, $target]) {
        $xml .= '<Relationship Id="' . $id . '" Type="' . $tipo . '" Target="' . $target . '"/>';
    }
    return $xml . '</Relationships>';
}
