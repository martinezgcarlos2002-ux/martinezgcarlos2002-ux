<?php
declare(strict_types=1);

/**
 * Planilla de recepción de leche en .xlsx.
 * Oficio vertical, ajustada a una página. En Excel se cambia el alto
 * en Diseño de página → Ajustar a, sin tocar el sistema.
 */
final class PlanillaRecepcionExcel
{
    /**
     * @param array{
     *   fechaHoja:string,
     *   filasHoja:list<array<string, mixed>>,
     *   mermaHoja:array<string, mixed>,
     *   totalRecibido:float
     * } $datos
     */
    public function enviar(array $datos): never
    {
        $fecha = $datos['fechaHoja'];
        $filas = [];
        foreach ($datos['filasHoja'] as $fila) {
            if (trim((string) ($fila['nombre'] ?? '')) === '') {
                continue;
            }
            $filas[] = $fila;
        }
        for ($i = 0; $i < 6; $i++) {
            $filas[] = ['n' => count($filas) + 1, 'nombre' => '', 'litros' => '', 'densidad' => '', 'faltante' => ''];
        }

        $zip = new ZipStore();
        $zip->agregar('[Content_Types].xml', self::contentTypes());
        $zip->agregar('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->agregar('xl/workbook.xml', self::workbook());
        $zip->agregar('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->agregar('xl/styles.xml', self::estilos());
        $zip->agregar('xl/worksheets/sheet1.xml', self::hoja($datos, $filas));
        $bytes = $zip->cerrar();

        $nombre = 'planilla_recepcion_' . $fecha . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    /** @param list<array<string, mixed>> $filas */
    private static function hoja(array $datos, array $filas): string
    {
        $merma = $datos['mermaHoja'];
        $primera = 5;
        $ultima = $primera + count($filas) - 1;
        $fechaTxt = (new DateTimeImmutable($datos['fechaHoja']))->format('d/m/Y');
        /** @var array<int, list<string>> $porFila */
        $porFila = [];
        $altos = [1 => 18, 2 => 14, 4 => 16];

        $porFila[1] = [
            self::texto('A1', APP_EMPRESA, 2),
            self::texto('C1', 'CONTROL DIARIO DE LECHE', 2),
            self::texto('H1', $fechaTxt, 2),
        ];
        $porFila[2] = [
            self::texto('A2', 'Planilla de recepción. Para imprimir: Diseño de página, ajustar alto si hace falta.', 3),
        ];
        $porFila[4] = [
            self::texto('A4', 'N', 1),
            self::texto('B4', 'NOMBRE Y APELLIDO', 1),
            self::texto('C4', 'LITROS', 1),
            self::texto('D4', 'DENSIDAD', 1),
            self::texto('E4', 'FALTANTE', 1),
            self::texto('G4', 'RESUMEN', 1),
            self::texto('H4', 'LITROS', 1),
        ];

        foreach ($filas as $i => $fila) {
            $r = $primera + $i;
            $altos[$r] = 15;
            $porFila[$r] = [
                self::numero($r, 0, $i + 1, 4),
                self::texto(self::ref(1, $r), mb_strtoupper((string) $fila['nombre']), 5),
                self::opcional($r, 2, $fila['litros'] ?? '', 6),
                self::opcional($r, 3, $fila['densidad'] ?? '', 6),
                self::opcional($r, 4, $fila['faltante'] ?? '', 7),
            ];
        }

        $poner = static function (int $r, string $etiqueta, string $valorXml) use (&$porFila, &$altos): void {
            $altos[$r] = $altos[$r] ?? 15;
            $porFila[$r][] = self::texto(self::ref(6, $r), $etiqueta, 5);
            $porFila[$r][] = $valorXml;
        };
        $venta = self::numOrBlank($merma['ventas_leche'] ?? '');
        $cortesia = self::numOrBlank($merma['cortesias_leche'] ?? '');
        $vales = self::numOrBlank($merma['vales_leche'] ?? '');
        $poner(5, 'VENTAS', $venta === '' ? '<c r="H5" s="8"/>' : self::numero(5, 7, (float) $venta, 8));
        $poner(7, 'CORTESIA', $cortesia === '' ? '<c r="H7" s="8"/>' : self::numero(7, 7, (float) $cortesia, 8));
        $poner(9, 'VALES', $vales === '' ? '<c r="H9" s="8"/>' : self::numero(9, 7, (float) $vales, 8));
        $poner(11, 'TOTAL FALTANTE', self::formula('H11', 'SUM(E' . $primera . ':E' . $ultima . ')', 9));
        $poner(13, 'TOTAL RECIBIDO', self::formula('H13', 'SUM(C' . $primera . ':C' . $ultima . ')', 9));
        $poner(15, 'NETO A PLANTA', self::formula('H15', 'MAX(0,H13-H5-H7-H9-H11)', 9));
        $poner(17, 'SELLO', '<c r="H17" s="5"/>');
        for ($r = 18; $r <= 22; $r++) {
            $altos[$r] = $altos[$r] ?? 15;
            $porFila[$r] = $porFila[$r] ?? [];
        }

        ksort($porFila);
        $rows = '';
        foreach ($porFila as $r => $celdas) {
            $rows .= self::fila($r, $altos[$r] ?? 15, $celdas);
        }

        $cols = '<col min="1" max="1" width="5" customWidth="1"/>'
            . '<col min="2" max="2" width="34" customWidth="1"/>'
            . '<col min="3" max="3" width="12" customWidth="1"/>'
            . '<col min="4" max="4" width="12" customWidth="1"/>'
            . '<col min="5" max="5" width="12" customWidth="1"/>'
            . '<col min="6" max="6" width="3" customWidth="1"/>'
            . '<col min="7" max="7" width="20" customWidth="1"/>'
            . '<col min="8" max="8" width="14" customWidth="1"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:H' . max($ultima, 22) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . $rows . '</sheetData>'
            . '<mergeCells count="4">'
            . '<mergeCell ref="A1:B1"/>'
            . '<mergeCell ref="C1:G1"/>'
            . '<mergeCell ref="A2:H2"/>'
            . '<mergeCell ref="G17:H22"/>'
            . '</mergeCells>'
            . '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.35" right="0.35" top="0.4" bottom="0.4" header="0.15" footer="0.15"/>'
            . '<pageSetup paperSize="5" orientation="portrait" fitToWidth="1" fitToHeight="1" horizontalDpi="300" verticalDpi="300"/>'
            . '<headerFooter><oddFooter>Recepción de leche ' . htmlspecialchars($fechaTxt, ENT_XML1) . ' · El Castillo del Queso</oddFooter></headerFooter>'
            . '</worksheet>';
    }

    /** @param list<string> $celdas */
    private static function fila(int $r, int $alto, array $celdas): string
    {
        return '<row r="' . $r . '" ht="' . $alto . '" customHeight="1">' . implode('', $celdas) . '</row>';
    }

    private static function texto(string $ref, string $valor, int $estilo): string
    {
        return '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">'
            . htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
    }

    private static function numero(int $fila, int $col, float $valor, int $estilo): string
    {
        return '<c r="' . self::ref($col, $fila) . '" s="' . $estilo . '"><v>' . $valor . '</v></c>';
    }

    private static function opcional(int $fila, int $col, mixed $valor, int $estilo): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor) || (float) $valor == 0.0) {
            return '<c r="' . self::ref($col, $fila) . '" s="' . $estilo . '"/>';
        }
        return self::numero($fila, $col, (float) $valor, $estilo);
    }

    private static function formula(string $ref, string $formula, int $estilo): string
    {
        return '<c r="' . $ref . '" s="' . $estilo . '"><f>' . htmlspecialchars($formula, ENT_XML1) . '</f></c>';
    }

    private static function numOrBlank(mixed $valor): float|string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor) || (float) $valor == 0.0) {
            return '';
        }
        return (float) $valor;
    }

    private static function ref(int $col, int $fila): string
    {
        $letras = '';
        for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + ($n - 1) % 26) . $letras;
        }
        return $letras . $fila;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Recepcion" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function estilos(): string
    {
        $borde = ' borderId="1" applyBorder="1"';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="0.000"/>'
            . '<numFmt numFmtId="165" formatCode="0.00"/>'
            . '</numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="9"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="9"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1A2E5A"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2"><border/>'
            . '<border><left style="thin"><color rgb="FFB0B0B0"/></left><right style="thin"><color rgb="FFB0B0B0"/></right>'
            . '<top style="thin"><color rgb="FFB0B0B0"/></top><bottom style="thin"><color rgb="FFB0B0B0"/></bottom></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="10">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2"' . $borde . ' xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0"' . $borde . ' xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0"' . $borde . ' xfId="0"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0"' . $borde . ' xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0"' . $borde . ' xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0"' . $borde . ' xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="2" fillId="0"' . $borde . ' xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '</cellXfs></styleSheet>';
    }
}
