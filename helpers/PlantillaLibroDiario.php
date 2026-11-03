<?php
declare(strict_types=1);

/**
 * Hoja del día con la misma estructura del libro de conciliaciones:
 * proveedores, gastos generales, ingresos y el cuadro INICIO / FINAL.
 * La columna $ es el monto dividido entre la tasa de E3.
 */
final class PlantillaLibroDiario
{
    /** Cuentas del cuadro de octubre. Si ADARA ya tiene otras, se agregan debajo. */
    public const CUENTAS = [
        'BNC EMPRESA',
        'BNC CARLOS',
        'BNC JUAN',
        'VENEZUELA JUAN CARLOS',
        'VENEZUELA CARLOS',
        'TESORO EMPRESA',
        'PROVINCIAL',
        'BANESCO',
        'BANCAMIGA CARLOS',
        'BANCAMIGA EMPRESA',
        'BANCAMIGA JUAN C',
    ];

    private const FILAS_BLOQUE = 25;

    /**
     * @param list<string> $cuentasExtra
     * @param array{productores?:list<string>,transportistas?:list<string>,empleados?:list<string>,clientes?:list<string>} $listas
     * @return string ruta temporal; el llamador envía el archivo y lo borra
     */
    public static function crear(array $cuentasExtra, ?string $tasa, ?DateTimeImmutable $fecha = null, array $listas = []): string
    {
        $fecha ??= new DateTimeImmutable('today');
        $tasa = self::tasaPlana($tasa);
        $cuentas = self::cuentas($cuentasExtra);

        $tmpBase = tempnam(sys_get_temp_dir(), 'adara_dia_');
        if ($tmpBase === false) {
            throw new RuntimeException('No pude crear el temporal de la plantilla.');
        }
        $tmp = $tmpBase . '.xlsx';
        unlink($tmpBase);

        $nombre = $fecha->format('d-m-Y');
        $catalogo = [
            'productores' => self::unir($listas['productores'] ?? [], $listas['transportistas'] ?? []),
            'empleados' => self::unir($listas['empleados'] ?? []),
            'clientes' => self::unir($listas['clientes'] ?? []),
        ];
        $hayListas = $catalogo['productores'] !== [] || $catalogo['empleados'] !== [] || $catalogo['clientes'] !== [];
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('No pude crear el Excel del día.');
        }
        $zip->addFromString('[Content_Types].xml', self::contentTypes($hayListas));
        $zip->addFromString('_rels/.rels', self::rels([
            ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'xl/workbook.xml'],
        ]));
        $zip->addFromString('xl/workbook.xml', self::workbook($nombre, $hayListas));
        $rels = [
            ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'worksheets/sheet1.xml'],
            ['rId2', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles', 'styles.xml'],
        ];
        if ($hayListas) {
            $rels[] = ['rId3', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'worksheets/sheet2.xml'];
        }
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::rels($rels));
        $zip->addFromString('xl/styles.xml', self::estilos());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::hoja($fecha, $tasa, $cuentas, $catalogo));
        if ($hayListas) {
            $zip->addFromString('xl/worksheets/sheet2.xml', self::hojaListas($catalogo));
        }
        $zip->close();
        return $tmp;
    }

    /** @param list<string> $extra @return list<string> */
    private static function cuentas(array $extra): array
    {
        $out = self::CUENTAS;
        $vistas = [];
        foreach ($out as $nombre) {
            $vistas[mb_strtoupper($nombre)] = true;
        }
        foreach ($extra as $nombre) {
            $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
            if ($nombre === '') {
                continue;
            }
            $clave = mb_strtoupper($nombre);
            if (isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;
            $out[] = $nombre;
        }
        return $out;
    }

    private static function tasaPlana(?string $tasa): ?string
    {
        if ($tasa === null) {
            return null;
        }
        $tasa = str_replace([' ', ','], ['', '.'], trim($tasa));
        if (!preg_match('/^\d+(\.\d+)?$/', $tasa) || (float) $tasa <= 0) {
            return null;
        }
        return $tasa;
    }

    /** @param list<string> $cuentas @param array{productores:list<string>,empleados:list<string>,clientes:list<string>} $listas */
    private static function hoja(DateTimeImmutable $fecha, ?string $tasa, array $cuentas, array $listas = []): string
    {
        $dias = [1 => 'LUNES', 2 => 'MARTES', 3 => 'MIÉRCOLES', 4 => 'JUEVES', 5 => 'VIERNES', 6 => 'SÁBADO', 7 => 'DOMINGO'];
        $celdas = [];
        $poner = static function (int $fila, int $col, string $xml) use (&$celdas): void {
            $celdas[$fila][$col] = $xml;
        };
        $texto = static function (int $fila, int $col, string $valor, bool $negrita = false) use ($poner): void {
            $poner($fila, $col, '<c r="' . self::ref($col, $fila) . '"' . ($negrita ? ' s="1"' : '') . ' t="inlineStr"><is><t xml:space="preserve">'
                . htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>');
        };
        $numero = static function (int $fila, int $col, string $valor) use ($poner): void {
            $poner($fila, $col, '<c r="' . self::ref($col, $fila) . '"><v>' . $valor . '</v></c>');
        };
        $formula = static function (int $fila, int $col, string $fx) use ($poner): void {
            $poner($fila, $col, '<c r="' . self::ref($col, $fila) . '"><f>' . htmlspecialchars($fx, ENT_XML1) . '</f></c>');
        };
        $encabezadoMov = static function (int $fila, bool $conComision) use ($texto): void {
            $texto($fila, 1, 'BANCO', true);
            $texto($fila, 2, 'REFERENCIA', true);
            $texto($fila, 3, 'MONTO', true);
            if ($conComision) {
                $texto($fila, 4, '% BANC.', true);
            }
            $texto($fila, 5, '$', true);
            $texto($fila, 6, 'NOMBRES', true);
            $texto($fila, 7, 'DESCRIPCION', true);
            if ($conComision) {
                $texto($fila, 8, 'MÉTODO', true);
            }
        };
        $comision = static function (int $fila) use ($formula): void {
            $formula($fila, 4, 'IF(C' . $fila . '="","",IF(H' . $fila . '="Pago Móvil",ROUND(C' . $fila . '*0.015,2),IF(H' . $fila . '="Transferencia Otros Bancos",ROUND(C' . $fila . '*0.003,2),IF(H' . $fila . '="Transferencia Mismo Banco",0,""))))');
        };
        $bloque = static function (int $desde, int $hasta, bool $conComision) use ($formula, $comision): void {
            for ($r = $desde; $r <= $hasta; $r++) {
                if ($conComision) {
                    $comision($r);
                }
                $formula($r, 5, 'IF(C' . $r . '="","",IF($E$3="","",C' . $r . '/$E$3))');
            }
        };
        $metodos = [];
        $bancos = [];

        $texto(1, 6, 'DÍA:', true);
        $texto(1, 7, $dias[(int) $fecha->format('N')], true);
        $texto(2, 7, $fecha->format('d-m-Y'));
        $texto(3, 2, 'PARALELO');
        $texto(3, 4, 'BANCO CENTRAL', true);
        if ($tasa !== null) {
            $numero(3, 5, $tasa);
        }

        $texto(6, 1, 'PROVEEDORES DE LECHE Y TRANSPORTISTAS', true);
        $encabezadoMov(7, true);
        $bloque(8, 7 + self::FILAS_BLOQUE, true);
        $finProv = 7 + self::FILAS_BLOQUE;
        $metodos[] = 'H8:H' . $finProv;
        $bancos[] = 'A8:A' . $finProv;
        $texto($finProv + 1, 1, 'TOTAL', true);
        $formula($finProv + 1, 3, 'SUM(C8:C' . $finProv . ')');
        $formula($finProv + 1, 4, 'SUM(D8:D' . $finProv . ')');
        $formula($finProv + 1, 5, 'SUM(E8:E' . $finProv . ')');

        $gastosTitulo = $finProv + 3;
        $texto($gastosTitulo, 1, 'GASTOS GENERALES', true);
        $encabezadoMov($gastosTitulo + 1, true);
        $gastosDesde = $gastosTitulo + 2;
        $gastosHasta = $gastosDesde + self::FILAS_BLOQUE - 1;
        $bloque($gastosDesde, $gastosHasta, true);
        $metodos[] = 'H' . $gastosDesde . ':H' . $gastosHasta;
        $bancos[] = 'A' . $gastosDesde . ':A' . $gastosHasta;
        $texto($gastosHasta + 1, 1, 'TOTAL', true);
        $formula($gastosHasta + 1, 3, 'SUM(C' . $gastosDesde . ':C' . $gastosHasta . ')');
        $formula($gastosHasta + 1, 4, 'SUM(D' . $gastosDesde . ':D' . $gastosHasta . ')');
        $formula($gastosHasta + 1, 5, 'SUM(E' . $gastosDesde . ':E' . $gastosHasta . ')');

        $ingTitulo = $gastosHasta + 3;
        $texto($ingTitulo, 1, 'INGRESOS', true);
        $encabezadoMov($ingTitulo + 1, false);
        $ingDesde = $ingTitulo + 2;
        $ingHasta = $ingDesde + self::FILAS_BLOQUE - 1;
        $bloque($ingDesde, $ingHasta, false);
        $bancos[] = 'A' . $ingDesde . ':A' . $ingHasta;
        $texto($ingHasta + 1, 1, 'TOTAL', true);
        $formula($ingHasta + 1, 3, 'SUM(C' . $ingDesde . ':C' . $ingHasta . ')');
        $formula($ingHasta + 1, 5, 'SUM(E' . $ingDesde . ':E' . $ingHasta . ')');

        $caja = $ingHasta + 3;
        $texto($caja, 1, 'BANCOS', true);
        $texto($caja, 3, 'INICIO', true);
        $texto($caja, 4, 'inicio en $', true);
        $texto($caja, 6, 'FINAL', true);
        $texto($caja, 7, 'total en $', true);
        $primeraCuenta = $caja + 1;
        foreach ($cuentas as $i => $cuenta) {
            $r = $primeraCuenta + $i;
            $texto($r, 1, $cuenta);
            $formula($r, 4, 'IF(C' . $r . '="","",IF($E$3="","",C' . $r . '/$E$3))');
            $formula($r, 7, 'IF(F' . $r . '="","",IF($E$3="","",F' . $r . '/$E$3))');
        }
        $ultimaCuenta = $primeraCuenta + count($cuentas) - 1;
        $total = $ultimaCuenta + 1;
        $texto($total, 1, 'TOTAL DE INICIO', true);
        $formula($total, 3, 'SUM(C' . $primeraCuenta . ':C' . $ultimaCuenta . ')');
        $formula($total, 4, 'SUM(D' . $primeraCuenta . ':D' . $ultimaCuenta . ')');
        $texto($total, 6, 'TOTAL EN TODOS LOS BANCOS:', true);
        $formula($total, 7, 'SUM(G' . $primeraCuenta . ':G' . $ultimaCuenta . ')');

        $rows = '';
        ksort($celdas);
        foreach ($celdas as $fila => $cols) {
            ksort($cols);
            $rows .= '<row r="' . $fila . '">' . implode('', $cols) . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:H' . $total . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>'
            . '<col min="1" max="1" width="28" customWidth="1"/>'
            . '<col min="2" max="2" width="16" customWidth="1"/>'
            . '<col min="3" max="3" width="18" customWidth="1"/>'
            . '<col min="4" max="4" width="16" customWidth="1"/>'
            . '<col min="5" max="5" width="14" customWidth="1"/>'
            . '<col min="6" max="6" width="28" customWidth="1"/>'
            . '<col min="7" max="7" width="42" customWidth="1"/>'
            . '<col min="8" max="8" width="32" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>' . $rows . '</sheetData>'
            . '<dataValidations count="' . (2 + self::validacionesNombre($listas, $finProv, $gastosDesde, $gastosHasta, $ingDesde, $ingHasta)) . '">'
            . '<dataValidation type="list" allowBlank="1" showDropDown="0" showErrorMessage="1" errorTitle="Banco" error="Elige uno de los bancos del cuadro de inicio y final." sqref="' . implode(' ', $bancos) . '">'
            . '<formula1>$A$' . $primeraCuenta . ':$A$' . $ultimaCuenta . '</formula1></dataValidation>'
            . '<dataValidation type="list" allowBlank="1" showDropDown="0" showErrorMessage="1" errorTitle="Método" error="Elige Pago Móvil, Transferencia Otros Bancos o Transferencia Mismo Banco." sqref="' . implode(' ', $metodos) . '">'
            . '<formula1>"Pago Móvil,Transferencia Otros Bancos,Transferencia Mismo Banco"</formula1></dataValidation>'
            . self::xmlNombres($listas, $finProv, $gastosDesde, $gastosHasta, $ingDesde, $ingHasta)
            . '</dataValidations>'
            . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '</worksheet>';
    }

    private static function ref(int $col, int $fila): string
    {
        $s = '';
        while ($col > 0) {
            $col--;
            $s = chr(65 + ($col % 26)) . $s;
            $col = intdiv($col, 26);
        }
        return $s . $fila;
    }

    private static function workbook(string $hoja, bool $listas = false): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($hoja, ENT_XML1) . '" sheetId="1" r:id="rId1"/>'
            . ($listas ? '<sheet name="Listas" sheetId="2" state="hidden" r:id="rId3"/>' : '')
            . '</sheets>'
            . '</workbook>';
    }

    private static function contentTypes(bool $listas = false): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . ($listas ? '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' : '')
            . '</Types>';
    }

    /** @param list<string> ...$grupos @return list<string> */
    private static function unir(array ...$grupos): array
    {
        $out = [];
        $vistos = [];
        foreach ($grupos as $grupo) {
            foreach ($grupo as $nombre) {
                $nombre = trim(preg_replace('/\s+/u', ' ', (string) $nombre) ?? '');
                if ($nombre === '') {
                    continue;
                }
                $clave = mb_strtoupper($nombre);
                if (isset($vistos[$clave])) {
                    continue;
                }
                $vistos[$clave] = true;
                $out[] = $nombre;
            }
        }
        sort($out, SORT_FLAG_CASE | SORT_STRING);
        return $out;
    }

    /**
     * @param array{productores?:list<string>,empleados?:list<string>,clientes?:list<string>} $listas
     */
    private static function validacionesNombre(array $listas, int $finProv, int $gastosDesde, int $gastosHasta, int $ingDesde, int $ingHasta): int
    {
        return substr_count(self::xmlNombres($listas, $finProv, $gastosDesde, $gastosHasta, $ingDesde, $ingHasta), '<dataValidation ');
    }

    /**
     * @param array{productores?:list<string>,empleados?:list<string>,clientes?:list<string>} $listas
     */
    private static function xmlNombres(array $listas, int $finProv, int $gastosDesde, int $gastosHasta, int $ingDesde, int $ingHasta): string
    {
        $xml = '';
        $prod = $listas['productores'] ?? [];
        $emp = $listas['empleados'] ?? [];
        $cli = $listas['clientes'] ?? [];
        if ($prod !== []) {
            $xml .= self::lista('F8:F' . $finProv, 'Listas!$A$2:$A$' . (count($prod) + 1), true, 'Nombre', 'Elige el productor o transportista de la lista.');
        }
        if ($emp !== []) {
            $xml .= self::lista('F' . $gastosDesde . ':F' . $gastosHasta, 'Listas!$B$2:$B$' . (count($emp) + 1), false, 'Nómina', 'Puedes elegir un empleado o escribir otro gasto.');
        }
        if ($cli !== []) {
            $xml .= self::lista('F' . $ingDesde . ':F' . $ingHasta, 'Listas!$C$2:$C$' . (count($cli) + 1), false, 'Cliente', 'Elige un cliente o escribe el nombre del tercero.');
        }
        return $xml;
    }

    private static function lista(string $sqref, string $rango, bool $estricto, string $titulo, string $error): string
    {
        $errorXml = $estricto
            ? ' showErrorMessage="1" errorTitle="' . htmlspecialchars($titulo, ENT_XML1) . '" error="' . htmlspecialchars($error, ENT_XML1) . '"'
            : ' showErrorMessage="0"';
        return '<dataValidation type="list" allowBlank="1" showDropDown="0"' . $errorXml . ' sqref="' . $sqref . '">'
            . '<formula1>' . $rango . '</formula1></dataValidation>';
    }

    /** @param array{productores:list<string>,empleados:list<string>,clientes:list<string>} $listas */
    private static function hojaListas(array $listas): string
    {
        $columnas = [
            1 => $listas['productores'],
            2 => $listas['empleados'],
            3 => $listas['clientes'],
        ];
        $titulos = [1 => 'Productores y transportistas', 2 => 'Empleados', 3 => 'Clientes'];
        $max = 1;
        foreach ($columnas as $lista) {
            $max = max($max, count($lista) + 1);
        }
        $filas = [];
        for ($c = 1; $c <= 3; $c++) {
            $filas[1][$c] = '<c r="' . self::ref($c, 1) . '" s="1" t="inlineStr"><is><t>'
                . htmlspecialchars($titulos[$c], ENT_XML1, 'UTF-8') . '</t></is></c>';
            foreach ($columnas[$c] as $i => $nombre) {
                $r = $i + 2;
                $filas[$r][$c] = '<c r="' . self::ref($c, $r) . '" t="inlineStr"><is><t xml:space="preserve">'
                    . htmlspecialchars($nombre, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
        }
        ksort($filas);
        $rows = '';
        foreach ($filas as $r => $cols) {
            ksort($cols);
            $rows .= '<row r="' . $r . '">' . implode('', $cols) . '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:C' . $max . '"/>'
            . '<sheetData>' . $rows . '</sheetData>'
            . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '</worksheet>';
    }

    private static function estilos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>';
    }

    /** @param list<array{0:string,1:string,2:string}> $items */
    private static function rels(array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($items as [$id, $tipo, $target]) {
            $xml .= '<Relationship Id="' . $id . '" Type="' . $tipo . '" Target="' . $target . '"/>';
        }
        return $xml . '</Relationships>';
    }
}
