<?php
declare(strict_types=1);

/**
 * Genera .xlsx (OOXML) sin dependencias: el servidor no tiene la extensión zip ni Composer,
 * así que el paquete se arma con entradas ZIP sin compresión (método STORE).
 * Cada hoja se ajusta a 1 página de ancho (fitToWidth=1, fitToHeight=0) en horizontal
 * y los anchos de columna se calculan a partir del contenido.
 */
final class XlsxWriter
{
    public const S_TEXTO = 2;
    public const S_USD = 3;
    public const S_USD4 = 4;
    public const S_DIAS = 5;
    public const S_BS = 6;
    public const S_TOTAL_TEXTO = 7;
    public const S_TOTAL_USD = 8;
    public const S_TOTAL_BS = 9;
    private const S_ENCABEZADO = 1;
    private const S_TITULO = 10;
    private const ANCHO_MIN = 6;
    private const ANCHO_MAX = 45;

    /** @var list<array{nombre:string, titulo:string, encabezados:list<string>, filas:list<list<array{0:mixed,1:int}>>}> */
    private array $hojas = [];

    /**
     * @param list<string> $encabezados
     * @param list<list<array{0:mixed, 1:int}>> $filas celdas [valor, estilo]; valor numérico o texto
     */
    public function hoja(string $nombre, string $titulo, array $encabezados, array $filas): self
    {
        $this->hojas[] = [
            'nombre'      => mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $nombre), 0, 31),
            'titulo'      => $titulo,
            'encabezados' => $encabezados,
            'filas'       => $filas,
        ];
        return $this;
    }

    public function enviar(string $archivo): never
    {
        $zip = new ZipStore();
        $zip->agregar('[Content_Types].xml', $this->contentTypes());
        $zip->agregar('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->agregar('xl/workbook.xml', $this->workbook());
        $zip->agregar('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->agregar('xl/styles.xml', self::estilos());
        foreach ($this->hojas as $i => $hoja) {
            $zip->agregar('xl/worksheets/sheet' . ($i + 1) . '.xml', self::hojaXml($hoja));
        }
        $bytes = $zip->cerrar();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    /** @param array{nombre:string, titulo:string, encabezados:list<string>, filas:list<list<array{0:mixed,1:int}>>} $hoja */
    private static function hojaXml(array $hoja): string
    {
        $nCols = count($hoja['encabezados']);
        $anchos = array_map(static fn (string $h): int => mb_strlen($h), $hoja['encabezados']);
        foreach ($hoja['filas'] as $fila) {
            foreach ($fila as $c => [$valor, $estilo]) {
                $anchos[$c] = max($anchos[$c] ?? 0, mb_strlen(self::comoTexto($valor, $estilo)));
            }
        }

        $cols = '';
        foreach ($anchos as $c => $w) {
            $ancho = min(self::ANCHO_MAX, max(self::ANCHO_MIN, $w + 2));
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $c + 1, $ancho);
        }

        $rows = '<row r="1">' . self::celda('A1', $hoja['titulo'], self::S_TITULO) . '</row>';
        $rows .= '<row r="2">';
        foreach ($hoja['encabezados'] as $c => $h) {
            $rows .= self::celda(self::ref($c, 2), $h, self::S_ENCABEZADO);
        }
        $rows .= '</row>';
        foreach ($hoja['filas'] as $i => $fila) {
            $r = $i + 3;
            $rows .= '<row r="' . $r . '">';
            foreach ($fila as $c => [$valor, $estilo]) {
                $rows .= self::celda(self::ref($c, $r), $valor, $estilo);
            }
            $rows .= '</row>';
        }
        $ultima = self::ref($nCols - 1, 1);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . $rows . '</sheetData>'
            . '<mergeCells count="1"><mergeCell ref="A1:' . $ultima . '"/></mergeCells>'
            . '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . '</worksheet>';
    }

    private static function celda(string $ref, mixed $valor, int $estilo): string
    {
        if ($valor === null || $valor === '') {
            return '<c r="' . $ref . '" s="' . $estilo . '"/>';
        }
        if (is_int($valor) || is_float($valor) || (is_string($valor) && in_array($estilo, [self::S_USD, self::S_USD4, self::S_DIAS, self::S_BS, self::S_TOTAL_USD, self::S_TOTAL_BS], true) && is_numeric($valor))) {
            return '<c r="' . $ref . '" s="' . $estilo . '"><v>' . (0 + $valor) . '</v></c>';
        }
        return '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">'
            . htmlspecialchars((string) $valor, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
    }

    private static function comoTexto(mixed $valor, int $estilo): string
    {
        if (!is_numeric($valor)) {
            return (string) $valor;
        }
        return match ($estilo) {
            self::S_USD4 => number_format((float) $valor, 4),
            self::S_DIAS => number_format((float) $valor, 1),
            self::S_BS, self::S_TOTAL_BS => 'Bs ' . number_format((float) $valor, 2),
            self::S_USD, self::S_TOTAL_USD => '$' . number_format((float) $valor, 2),
            default => (string) $valor,
        };
    }

    private static function ref(int $col, int $fila): string
    {
        $letras = '';
        for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + ($n - 1) % 26) . $letras;
        }
        return $letras . $fila;
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach (array_keys($this->hojas) as $i) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheets . '</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        $nombres = '';
        foreach ($this->hojas as $i => $hoja) {
            $nombre = htmlspecialchars($hoja['nombre'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $sheets .= '<sheet name="' . $nombre . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
            $nombres .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">\'' . $nombre . '\'!$1:$2</definedName>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets><definedNames>' . $nombres . '</definedNames></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach (array_keys($this->hojas) as $i) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $n = count($this->hojas) + 1;
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    /** Índices de cellXfs = constantes S_*. */
    private static function estilos(): string
    {
        $borde = ' borderId="1" applyBorder="1"';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="4">'
            . '<numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="&quot;$&quot;#,##0.0000"/>'
            . '<numFmt numFmtId="166" formatCode="0.0"/>'
            . '<numFmt numFmtId="167" formatCode="&quot;Bs &quot;#,##0.00"/>'
            . '</numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="13"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1A2E5A"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF3CD"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2"><border/>'
            . '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
            . '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="11">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2"' . $borde . ' xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0"' . $borde . ' xfId="0"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0"' . $borde . ' xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0"' . $borde . ' xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0"' . $borde . ' xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="167" fontId="0" fillId="0"' . $borde . ' xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="3"' . $borde . ' xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right"/></xf>'
            . '<xf numFmtId="164" fontId="2" fillId="3"' . $borde . ' xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="167" fontId="2" fillId="3"' . $borde . ' xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';
    }
}

/** Contenedor ZIP mínimo (sin compresión) para el paquete OOXML. */
final class ZipStore
{
    private string $datos = '';
    private string $central = '';
    private int $entradas = 0;

    public function agregar(string $nombre, string $contenido): void
    {
        $crc = crc32($contenido) & 0xFFFFFFFF;
        $len = strlen($contenido);
        [$hora, $fecha] = self::dosFecha();
        $offset = strlen($this->datos);
        $comun = pack('vvvvvVVVvv', 20, 0x0800, 0, $hora, $fecha, $crc, $len, $len, strlen($nombre), 0);
        $this->datos .= "PK\x03\x04" . $comun . $nombre . $contenido;
        $this->central .= "PK\x01\x02" . pack('v', 20) . $comun . pack('vvvVV', 0, 0, 0, 0, $offset) . $nombre;
        $this->entradas++;
    }

    public function cerrar(): string
    {
        return $this->datos . $this->central . "PK\x05\x06"
            . pack('vvvvVVv', 0, 0, $this->entradas, $this->entradas, strlen($this->central), strlen($this->datos), 0);
    }

    /** @return array{0:int, 1:int} */
    private static function dosFecha(): array
    {
        $t = getdate();
        return [
            ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2),
            (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'],
        ];
    }
}
