<?php
declare(strict_types=1);

/**
 * Rellena plantilla_maestra.xlsx sin PhpSpreadsheet.
 *
 * Los bancos NO van a sharedStrings.xml: un índice nuevo desplazaría
 * todas las celdas del libro. Van como texto en línea (inlineStr) en el
 * XML de la hoja Parametros, y se alarga la Tabla de Excel que alimenta
 * el desplegable (xl/tables/tableN.xml).
 *
 * La tasa es un número, así que va en el XML de Movimientos, en la celda
 * de la columna "Tasa USD", como <v>, no como cadena compartida.
 */
final class PlantillaBancos
{
    private const NS_OFFICE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public static function rutaMaestra(): string
    {
        return ADARA_ROOT . '/storage/plantillas/plantilla_maestra.xlsx';
    }

    /**
     * @param list<string|array{alias:string, moneda?:string}> $alias cuentas propias
     * @param list<string> $bancos catálogo de la Gaceta (bancos_catalogo)
     * @param array{empleados?:list<string>, productores?:list<string>, transportistas?:list<string>, gas?:list<string>} $listas
     * @return string ruta del xlsx temporal; el llamador lo envía y lo borra
     */
    public static function materializar(string $maestra, array $alias, array $bancos, ?string $tasa, array $listas = []): string
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'adara_bco_');
        if ($tmpBase === false) {
            throw new RuntimeException('No pude crear el temporal de la plantilla.');
        }
        $tmp = $tmpBase . '.xlsx';
        if (!copy($maestra, $tmp)) {
            unlink($tmpBase);
            throw new RuntimeException('No pude copiar la plantilla maestra.');
        }
        unlink($tmpBase);

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            unlink($tmp);
            throw new RuntimeException('No pude abrir la copia de la plantilla.');
        }

        try {
            $ss = self::sharedStrings($zip);
            $hojas = self::hojas($zip);
            $param = $hojas['PARAMETROS'] ?? null;
            $mov = $hojas['MOVIMIENTOS'] ?? null;
            if ($param === null || $mov === null) {
                throw new RuntimeException('La plantilla maestra necesita las hojas Parametros y Movimientos.');
            }

            $cuentas = self::cuentasLimpias($alias);
            $listaBancos = self::aliasLimpios($bancos);
            $fin = self::inyectarParametros($zip, $param, $ss, $cuentas, $listaBancos, [
                'EMPLEADO' => self::aliasLimpios($listas['empleados'] ?? []),
                'PRODUCTOR' => self::aliasLimpios($listas['productores'] ?? []),
                'TRANSPORTISTA' => self::aliasLimpios($listas['transportistas'] ?? []),
                'PROVEEDOR GAS' => self::aliasLimpios($listas['gas'] ?? []),
            ]);
            self::ampliarRangos($zip, $fin);
            if ($tasa !== null && $tasa !== '') {
                self::inyectarTasa($zip, $mov, $ss, self::tasaPlana($tasa));
            }
        } catch (Throwable $e) {
            $zip->close();
            unlink($tmp);
            throw $e;
        }

        $zip->close();
        return $tmp;
    }

    /**
     * @param list<string|array{alias:string, moneda?:string}> $alias
     * @return list<array{alias:string, moneda:string}>
     */
    private static function cuentasLimpias(array $alias): array
    {
        $out = [];
        $vistos = [];
        foreach ($alias as $item) {
            if (is_array($item)) {
                $nombre = (string) ($item['alias'] ?? '');
                $moneda = strtoupper(trim((string) ($item['moneda'] ?? 'BS')));
            } else {
                $nombre = (string) $item;
                $moneda = 'BS';
            }
            $nombre = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $nombre) ?? '');
            if ($nombre === '') {
                continue;
            }
            $clave = mb_strtoupper($nombre);
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $out[] = ['alias' => $nombre, 'moneda' => $moneda === 'USD' ? 'USD' : 'BS'];
        }
        return $out;
    }

    /** @param list<string> $alias @return list<string> */
    private static function aliasLimpios(array $alias): array
    {
        $out = [];
        $vistos = [];
        foreach ($alias as $nombre) {
            $nombre = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $nombre) ?? '');
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
        return $out;
    }

    private static function tasaPlana(string $tasa): string
    {
        $tasa = str_replace([' ', ','], ['', '.'], trim($tasa));
        if (!preg_match('/^\d+(\.\d+)?$/', $tasa) || (float) $tasa <= 0) {
            throw new RuntimeException('La tasa BCV vigente no es un número válido.');
        }
        return $tasa;
    }

    /** @return array<string, array{path:string, nombre:string}> */
    private static function hojas(ZipArchive $zip): array
    {
        $wb = self::xml($zip, 'xl/workbook.xml');
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        $map = [];
        foreach ($rels->Relationship as $rel) {
            $map[(string) $rel['Id']] = self::target((string) $rel['Target']);
        }
        $hojas = [];
        foreach ($wb->sheets->sheet as $sheet) {
            $nombre = (string) $sheet['name'];
            $rid = (string) $sheet->attributes(self::NS_OFFICE)['id'];
            $path = $map[$rid] ?? '';
            if ($path === '') {
                continue;
            }
            $hojas[self::norm($nombre)] = ['path' => $path, 'nombre' => $nombre];
        }
        return $hojas;
    }

    /** Opciones fijas de la columna Método. El tarifario de la Gaceta vive en la fórmula de Comisión BS. */
    public const METODOS = [
        'Pago Móvil',
        'Transferencia Otros Bancos',
        'Transferencia Mismo Banco',
    ];

    /**
     * Columna A = alias de ADARA, B = bancos del catálogo, C = métodos.
     *
     * @param array{path:string, nombre:string} $hoja
     * @param list<string> $ss
     * @param list<array{alias:string, moneda:string}> $cuentas
     * @param list<string> $bancos
     * @param array<string, list<string>> $extra
     * @return array<string, array{desde:int, hasta:int}>
     */
    private static function inyectarParametros(ZipArchive $zip, array $hoja, array $ss, array $cuentas, array $bancos, array $extra = []): array
    {
        $xml = $zip->getFromName($hoja['path']);
        if ($xml === false) {
            throw new RuntimeException('No encuentro el XML de Parametros.');
        }
        $filas = self::filas($xml, $ss);
        $encabezado = null;
        $cols = [];
        foreach ($filas as $fila) {
            foreach ($fila['celdas'] as $c => $valor) {
                $n = self::norm($valor);
                if (in_array($n, ['CUENTA', 'BANCO', 'METODO', 'EMPLEADO', 'PRODUCTOR', 'TRANSPORTISTA', 'PROVEEDOR GAS', 'MONEDA'], true)) {
                    $cols[$n] = $c;
                    $encabezado = $fila;
                }
            }
            if (isset($cols['CUENTA'])) {
                break;
            }
        }
        if ($encabezado === null || !isset($cols['CUENTA'], $cols['BANCO'], $cols['METODO'], $cols['MONEDA'])) {
            throw new RuntimeException('La hoja Parametros necesita los encabezados Cuenta, Banco, Método y Moneda.');
        }

        $listas = [
            'CUENTA' => array_column($cuentas, 'alias'),
            'BANCO' => $bancos,
            'METODO' => self::METODOS,
            'MONEDA' => array_column($cuentas, 'moneda'),
        ];
        foreach ($extra as $clave => $lista) {
            if (isset($cols[$clave])) {
                $listas[$clave] = $lista;
            }
        }
        $desde = $encabezado['r'] + 1;
        $hasta = [];
        foreach ($listas as $clave => $lista) {
            $hasta[$clave] = $desde + max(count($lista), 1) - 1;
        }
        $max = max($hasta);
        $rows = $encabezado['xml'];
        for ($r = $desde; $r <= $max; $r++) {
            $i = $r - $desde;
            $piezas = [];
            foreach ($listas as $clave => $lista) {
                if ($i < count($lista)) {
                    $texto = $lista[$i];
                } elseif ($i === 0 && $lista === []) {
                    $texto = '';
                } else {
                    continue;
                }
                $col = $cols[$clave];
                $piezas[$col] = self::inline(self::columna($col), $r, $texto);
            }
            if ($piezas === []) {
                continue;
            }
            ksort($piezas);
            $rows .= '<row r="' . $r . '">' . implode('', $piezas) . '</row>';
        }
        $xml = self::reemplazarSheetData($xml, $rows);
        $letraFin = self::columna(max($cols));
        $xml = self::ponerDimension($xml, 'A1:' . $letraFin . $max);
        self::reemplazar($zip, $hoja['path'], $xml);

        $finPorLetra = [];
        foreach ($cols as $clave => $col) {
            $finPorLetra[self::columna($col)] = $hasta[$clave];
        }
        foreach (self::tablasDe($zip, $hoja['path']) as $tablaPath) {
            $tabla = $zip->getFromName($tablaPath);
            if ($tabla === false) {
                continue;
            }
            $tabla = preg_replace_callback(
                '/\bref="([A-Z]+)(\d+):([A-Z]+)(\d+)"/',
                static function (array $m) use ($finPorLetra): string {
                    $fin = null;
                    foreach ($finPorLetra as $letra => $hastaFila) {
                        if ($letra >= $m[1] && $letra <= $m[3]) {
                            $fin = max($fin ?? 0, $hastaFila);
                        }
                    }
                    return 'ref="' . $m[1] . $m[2] . ':' . $m[3] . ($fin ?? $m[4]) . '"';
                },
                $tabla
            ) ?? $tabla;
            self::reemplazar($zip, $tablaPath, $tabla);
        }

        $rangos = [];
        foreach ($finPorLetra as $letra => $fila) {
            $rangos[$letra] = ['desde' => $desde, 'hasta' => $fila];
        }
        return $rangos;
    }

    private static function inline(string $letra, int $fila, string $texto): string
    {
        return '<c r="' . $letra . $fila . '" t="inlineStr"><is><t xml:space="preserve">'
            . htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</t></is></c>';
    }

    /** @param array<string, array{desde:int, hasta:int}> $rangos */
    private static function ampliarRangos(ZipArchive $zip, array $rangos): void
    {
        $cambios = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (!is_string($nombre) || !str_ends_with($nombre, '.xml')) {
                continue;
            }
            if (!str_contains($nombre, 'workbook.xml') && !str_contains($nombre, 'worksheets/sheet')) {
                continue;
            }
            $xml = $zip->getFromName($nombre);
            if ($xml === false || !str_contains($xml, 'Parametros')) {
                continue;
            }
            $nuevo = $xml;
            foreach ($rangos as $columna => $rango) {
                $desde = $rango['desde'];
                $hasta = $rango['hasta'];
                $nuevo = preg_replace_callback(
                    "/((?:'Parametros'|Parametros)!\\\$?)([A-Z]+)(\\\$?)(\\d+):(\\\$?)([A-Z]+)(\\\$?)(\\d+)/",
                    static function (array $m) use ($columna, $desde, $hasta): string {
                        if (strtoupper($m[2]) !== $columna || strtoupper($m[6]) !== $columna) {
                            return $m[0];
                        }
                        return $m[1] . $m[2] . $m[3] . $desde . ':' . $m[5] . $m[6] . $m[7] . $hasta;
                    },
                    $nuevo
                ) ?? $nuevo;
            }
            if ($nuevo !== $xml) {
                $cambios[$nombre] = $nuevo;
            }
        }
        foreach ($cambios as $nombre => $nuevo) {
            self::reemplazar($zip, $nombre, $nuevo);
        }
    }

    /**
     * @param array{path:string, nombre:string} $hoja
     * @param list<string> $ss
     */
    private static function inyectarTasa(ZipArchive $zip, array $hoja, array $ss, string $tasa): void
    {
        $xml = $zip->getFromName($hoja['path']);
        if ($xml === false) {
            throw new RuntimeException('No encuentro el XML de Movimientos.');
        }
        $filas = self::filas($xml, $ss);
        $header = 0;
        $col = 0;
        foreach ($filas as $fila) {
            foreach ($fila['celdas'] as $c => $valor) {
                $n = self::norm($valor);
                if ($n === 'TASA USD' || $n === 'TASA USD DIA' || $n === 'TASA') {
                    $header = $fila['r'];
                    $col = $c;
                    break 2;
                }
            }
        }
        if ($header === 0 || $col === 0) {
            throw new RuntimeException('La hoja Movimientos no tiene la columna Tasa USD.');
        }

        $letra = self::columna($col);
        $destino = $header + 1;
        foreach ($filas as $fila) {
            if ($fila['r'] <= $header) {
                continue;
            }
            if (trim($fila['celdas'][$col] ?? '') === '') {
                $destino = $fila['r'];
                break;
            }
            $destino = $fila['r'] + 1;
        }

        $ref = $letra . $destino;
        $estilo = '';
        if (preg_match('/<c\b[^>]*\br="' . $ref . '"[^>]*>/', $xml, $abierta)
            || preg_match('/<c\b[^>]*\br="' . $ref . '"[^>]*\/>/', $xml, $abierta)) {
            if (preg_match('/\ss="(\d+)"/', $abierta[0], $st)) {
                $estilo = ' s="' . $st[1] . '"';
            }
        }
        $celda = '<c r="' . $ref . '"' . $estilo . '><v>' . $tasa . '</v></c>';
        $patron = '/<c\b[^>]*\br="' . $ref . '"[^>]*\/>|<c\b[^>]*\br="' . $ref . '"[^>]*>.*?<\/c>/s';
        $xml2 = preg_replace($patron, $celda, $xml, 1, $hechas);
        if ($hechas === 1) {
            $xml = $xml2 ?? $xml;
        } elseif (preg_match('/<row\b[^>]*\br="' . $destino . '"[^>]*>/', $xml, $row, PREG_OFFSET_CAPTURE)) {
            $pos = $row[0][1] + strlen($row[0][0]);
            $xml = substr($xml, 0, $pos) . $celda . substr($xml, $pos);
        } elseif (preg_match('/<row\b[^>]*\br="' . $destino . '"[^>]*\/>/', $xml)) {
            $xml = preg_replace(
                '/<row\b[^>]*\br="' . $destino . '"[^>]*\/>/',
                '<row r="' . $destino . '">' . $celda . '</row>',
                $xml,
                1
            ) ?? $xml;
        } else {
            $filaXml = '<row r="' . $destino . '">' . $celda . '</row>';
            $xml = str_replace('</sheetData>', $filaXml . '</sheetData>', $xml);
        }

        foreach (self::tablasDe($zip, $hoja['path']) as $tablaPath) {
            $tabla = $zip->getFromName($tablaPath);
            if ($tabla === false) {
                continue;
            }
            $tablaNueva = preg_replace_callback(
                '/\bref="([A-Z]+)(\d+):([A-Z]+)(\d+)"/',
                static function (array $m) use ($destino): string {
                    $fin = max((int) $m[4], $destino);
                    return 'ref="' . $m[1] . $m[2] . ':' . $m[3] . $fin . '"';
                },
                $tabla
            );
            if (is_string($tablaNueva) && $tablaNueva !== $tabla) {
                self::reemplazar($zip, $tablaPath, $tablaNueva);
            }
        }

        self::reemplazar($zip, $hoja['path'], $xml);
    }

    /** @param list<string> $ss @return list<array{r:int, xml:string, celdas:array<int, string>}> */
    private static function filas(string $xml, array $ss): array
    {
        preg_match_all('/<row\b[^>]*\br="(\d+)"[^>]*\/>|<row\b[^>]*\br="(\d+)"[^>]*>.*?<\/row>/s', $xml, $rows, PREG_SET_ORDER);
        $filas = [];
        foreach ($rows as $row) {
            $r = (int) ($row[1] !== '' ? $row[1] : $row[2]);
            $celdas = [];
            preg_match_all('/<c\b([^>]*?)\br="([A-Z]+)\d+"([^>]*)\/>|<c\b([^>]*?)\br="([A-Z]+)\d+"([^>]*)>(.*?)<\/c>/s', $row[0], $cells, PREG_SET_ORDER);
            foreach ($cells as $cell) {
                if ($cell[2] !== '') {
                    continue;
                }
                $attrs = $cell[4] . $cell[6];
                $letra = $cell[5];
                $inner = $cell[7];
                $tipo = '';
                if (preg_match('/\bt="([^"]*)"/', $attrs, $tm)) {
                    $tipo = $tm[1];
                }
                $valor = '';
                if ($tipo === 'inlineStr') {
                    if (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm)) {
                        $valor = html_entity_decode($tm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                } elseif ($tipo === 's' && preg_match('/<v>(\d+)<\/v>/', $inner, $vm)) {
                    $valor = $ss[(int) $vm[1]] ?? '';
                } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                    $valor = $vm[1];
                }
                $celdas[self::indice($letra)] = $valor;
            }
            $filas[] = ['r' => $r, 'xml' => $row[0], 'celdas' => $celdas];
        }
        return $filas;
    }

    private static function reemplazarSheetData(string $xml, string $rows): string
    {
        $bloque = '<sheetData>' . $rows . '</sheetData>';
        $nuevo = preg_replace('/<sheetData\b[^>]*>.*?<\/sheetData>|<sheetData\b[^>]*\/>/s', $bloque, $xml, 1, $n);
        if ($n !== 1 || !is_string($nuevo)) {
            throw new RuntimeException('No pude reescribir la hoja Parametros.');
        }
        return $nuevo;
    }

    private static function ponerDimension(string $xml, string $ref): string
    {
        if (!str_contains($xml, '<dimension')) {
            return $xml;
        }
        return preg_replace('/(<dimension\b[^>]*\bref=")[^"]+(")/', '${1}' . $ref . '$2', $xml, 1) ?? $xml;
    }

    /** @return list<string> */
    private static function tablasDe(ZipArchive $zip, string $sheetPath): array
    {
        $relsPath = 'xl/worksheets/_rels/' . basename($sheetPath) . '.rels';
        $raw = XmlSeguro::desdeZip($zip, $relsPath);
        if ($raw === null) {
            return [];
        }
        $rels = XmlSeguro::cargar($raw);
        $out = [];
        foreach ($rels->Relationship as $rel) {
            $tipo = (string) $rel['Type'];
            if (!str_ends_with($tipo, '/table')) {
                continue;
            }
            $out[] = self::target((string) $rel['Target']);
        }
        return $out;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $raw = XmlSeguro::desdeZip($zip, 'xl/sharedStrings.xml');
        if ($raw === null) {
            return [];
        }
        $xml = XmlSeguro::cargar($raw);
        $ss = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $ss[] = (string) $si->t;
                continue;
            }
            $txt = '';
            foreach ($si->r as $run) {
                $txt .= (string) $run->t;
            }
            $ss[] = $txt;
        }
        return $ss;
    }

    private static function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $raw = XmlSeguro::desdeZip($zip, $path);
        if ($raw === null) {
            throw new RuntimeException('Falta ' . $path . ' en la plantilla.');
        }
        return XmlSeguro::cargar($raw);
    }

    private static function reemplazar(ZipArchive $zip, string $path, string $contenido): void
    {
        if ($zip->deleteName($path) !== true || $zip->addFromString($path, $contenido) !== true) {
            throw new RuntimeException('No pude actualizar ' . $path . '.');
        }
    }

    private static function target(string $t): string
    {
        $t = str_replace('\\', '/', $t);
        if (str_starts_with($t, '/')) {
            return ltrim($t, '/');
        }
        if (str_starts_with($t, 'xl/')) {
            return $t;
        }
        if (str_starts_with($t, '../')) {
            return 'xl/' . substr($t, 3);
        }
        return 'xl/' . ltrim($t, '/');
    }

    private static function columna(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private static function indice(string $letras): int
    {
        $n = 0;
        foreach (str_split($letras) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n;
    }

    private static function norm(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        $s = mb_strtoupper($s);
        return strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }
}
