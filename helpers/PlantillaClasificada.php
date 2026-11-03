<?php
declare(strict_types=1);

/**
 * Parte de storage/plantillas/conciliacion_clasificada_v4.xlsx.
 * Ese libro es solo la estructura (encabezados y la hoja oculta).
 * Aquí se agregan los cálculos y la configuración que los alimenta:
 *
 *   Parametros (oculta): alias, moneda, bancos, métodos, personas.
 *   Total Bancos: tasa BCV, SUMIF de las hojas operativas y el cuadre.
 *   Hojas operativas: comisión, dólares y tasa, con listas de Parametros.
 *
 * Las fórmulas usan nombres de función en inglés y coma, igual que la v4.
 */
final class PlantillaClasificada
{
    private const NS_OFFICE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const FILAS = 40;
    private const TOPE_LISTA = 500;

    public const METODOS = [
        'Pago Móvil',
        'Transferencia Mismo Banco',
        'Transferencia Otros Bancos',
        'Gasto por Tarjeta',
        'Liquidación Punto de Venta',
    ];

    /** Hoja → columna de Parametros que alimenta el nombre (D). */
    private const LISTA_NOMBRE = [
        'CLIENTES' => 'H',
        'NOMINA' => 'E',
        'PRODUCTORES' => 'F',
        'TRANSPORTE' => 'G',
    ];

    public static function rutaMaestra(): string
    {
        return ADARA_ROOT . '/storage/plantillas/conciliacion_clasificada_v4.xlsx';
    }

    /**
     * @param list<array{alias:string, moneda?:string}> $cuentas
     * @param list<string> $bancos
     * @param array{empleados?:list<string>, productores?:list<string>, transportistas?:list<string>, clientes?:list<string>} $listas
     * @param array<string, string> $saldosIniciales alias => saldo final anterior
     */
    public static function materializar(
        array $cuentas,
        array $bancos,
        ?string $tasa,
        array $listas = [],
        array $saldosIniciales = [],
        ?DateTimeImmutable $fecha = null
    ): string {
        $maestra = self::rutaMaestra();
        if (!is_file($maestra)) {
            throw new RuntimeException('Falta la plantilla conciliacion_clasificada_v4.xlsx.');
        }
        $fecha ??= new DateTimeImmutable('today');
        $tasa = self::tasaPlana($tasa);

        $tmpBase = tempnam(sys_get_temp_dir(), 'adara_cls_');
        if ($tmpBase === false) {
            throw new RuntimeException('No pude crear el temporal de la plantilla.');
        }
        $tmp = $tmpBase . '.xlsx';
        if (!copy($maestra, $tmp)) {
            unlink($tmpBase);
            throw new RuntimeException('No pude copiar la plantilla clasificada.');
        }
        unlink($tmpBase);

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            unlink($tmp);
            throw new RuntimeException('No pude abrir la copia de la plantilla.');
        }

        try {
            $hojas = self::hojas($zip);
            $cuentasLimpias = self::cuentasLimpias($cuentas);
            $listaBancos = self::limpiar($bancos);
            $nombres = [
                'E' => self::limpiar($listas['empleados'] ?? []),
                'F' => self::limpiar($listas['productores'] ?? []),
                'G' => self::limpiar($listas['transportistas'] ?? []),
                'H' => self::limpiar($listas['clientes'] ?? []),
            ];
            self::escribir($zip, $hojas, 'PARAMETROS', self::parametros(
                self::filaEncabezado($zip, $hojas['PARAMETROS']['path']),
                $cuentasLimpias,
                $listaBancos,
                $nombres['E'],
                $nombres['F'],
                $nombres['G'],
                $nombres['H']
            ), 'A1:H' . max(2, self::TOPE_LISTA), null);

            $nCuentas = max(1, count($cuentasLimpias));
            self::escribir($zip, $hojas, 'TOTAL BANCOS', self::totalBancos(
                self::filaEncabezado($zip, $hojas['TOTAL BANCOS']['path']),
                $cuentasLimpias,
                $saldosIniciales,
                $fecha,
                $tasa
            ), 'A1:J' . ($nCuentas + 1), null);

            foreach (['CLIENTES', 'NOMINA', 'PRODUCTORES', 'TRANSPORTE', 'GASTOS GENERALES'] as $clave) {
                if (!isset($hojas[$clave])) {
                    throw new RuntimeException('La plantilla v4 no tiene la hoja ' . $clave . '.');
                }
                $listaNombre = self::LISTA_NOMBRE[$clave] ?? null;
                self::escribir($zip, $hojas, $clave, self::operativa(
                    self::filaEncabezado($zip, $hojas[$clave]['path']),
                    $fecha
                ), 'A1:K' . (self::FILAS + 1), self::validaciones(
                    count($cuentasLimpias),
                    count($listaBancos),
                    $listaNombre,
                    $listaNombre !== null ? count($nombres[$listaNombre]) : 0
                ));
            }
        } catch (Throwable $e) {
            $zip->close();
            unlink($tmp);
            throw $e;
        }

        $zip->close();
        return $tmp;
    }

    /** @param array{path:string, nombre:string} $hoja */
    private static function escribir(ZipArchive $zip, array $hojas, string $clave, string $filas, string $dimension, ?string $validaciones): void
    {
        $path = $hojas[$clave]['path'] ?? '';
        $xml = $path !== '' ? $zip->getFromName($path) : false;
        if ($xml === false) {
            throw new RuntimeException('No encuentro el XML de ' . $clave . '.');
        }
        $xml = preg_replace('/<dimension[^>]*\/>/', '<dimension ref="' . $dimension . '"/>', $xml, 1) ?? $xml;
        // preg_replace trata $2 y $50 como referencias. Las fórmulas traen $A$2.
        $reemplazo = preg_replace_callback(
            '/<sheetData>.*?<\/sheetData>/s',
            static fn (): string => '<sheetData>' . $filas . '</sheetData>',
            $xml,
            1
        );
        if (!is_string($reemplazo) || !str_contains($reemplazo, '<sheetData>')) {
            throw new RuntimeException('No pude reescribir ' . $clave . '.');
        }
        $xml = preg_replace('/<dataValidations\b.*?<\/dataValidations>/s', '', $reemplazo) ?? $reemplazo;
        if ($validaciones !== null) {
            if (str_contains($xml, '<pageMargins')) {
                $xml = preg_replace_callback(
                    '/<pageMargins\b/',
                    static fn (): string => $validaciones . '<pageMargins',
                    $xml,
                    1
                ) ?? $xml;
            } else {
                $xml = str_replace('</worksheet>', $validaciones . '</worksheet>', $xml);
            }
        }
        $zip->deleteName($path);
        $zip->addFromString($path, $xml);
    }

    private static function filaEncabezado(ZipArchive $zip, string $path): string
    {
        $xml = $zip->getFromName($path);
        if ($xml === false || !preg_match('/<row r="1">.*?<\/row>/s', $xml, $m)) {
            throw new RuntimeException('La hoja no tiene fila de encabezados.');
        }
        return $m[0];
    }

    /**
     * @param list<array{alias:string, moneda:string}> $cuentas
     * @param list<string> $bancos
     * @param list<string> $empleados
     * @param list<string> $productores
     * @param list<string> $transportistas
     * @param list<string> $clientes
     */
    private static function parametros(
        string $encabezado,
        array $cuentas,
        array $bancos,
        array $empleados,
        array $productores,
        array $transportistas,
        array $clientes
    ): string {
        $columnas = [
            1 => array_column($cuentas, 'alias'),
            2 => array_column($cuentas, 'moneda'),
            3 => $bancos,
            4 => self::METODOS,
            5 => $empleados,
            6 => $productores,
            7 => $transportistas,
            8 => $clientes,
        ];
        $alto = 1;
        foreach ($columnas as $valores) {
            $alto = max($alto, count($valores) + 1);
        }
        $xml = $encabezado;
        for ($r = 2; $r <= $alto; $r++) {
            $celdas = '';
            foreach ($columnas as $col => $valores) {
                $valor = $valores[$r - 2] ?? '';
                if ($valor !== '') {
                    $celdas .= self::texto($col, $r, $valor);
                }
            }
            if ($celdas !== '') {
                $xml .= '<row r="' . $r . '">' . $celdas . '</row>';
            }
        }
        return $xml;
    }

    /**
     * @param list<array{alias:string, moneda:string}> $cuentas
     * @param array<string, string> $saldosIniciales
     */
    private static function totalBancos(string $encabezado, array $cuentas, array $saldosIniciales, DateTimeImmutable $fecha, ?string $tasa): string
    {
        if ($cuentas === []) {
            $cuentas = [['alias' => '', 'moneda' => 'BS']];
        }
        $xml = $encabezado;
        $fechaIso = $fecha->format('Y-m-d');
        $n = count($cuentas);
        foreach ($cuentas as $i => $cuenta) {
            $r = $i + 2;
            $celdas = '';
            if ($cuenta['alias'] !== '') {
                $celdas .= self::texto(1, $r, $cuenta['alias']);
            }
            $celdas .= self::texto(2, $r, $fechaIso);
            $saldo = self::saldoDe($saldosIniciales, $cuenta['alias']);
            if ($saldo !== null) {
                $celdas .= self::numero(3, $r, $saldo);
            }
            $celdas .= self::formula(4, $r, 'SUMIF(Clientes!A:A,A' . $r . ',Clientes!E:E)');
            $celdas .= self::formula(5, $r, "SUMIF('Nómina'!A:A,A" . $r . ",'Nómina'!E:E)+SUMIF(Productores!A:A,A" . $r . ',Productores!E:E)+SUMIF(Transporte!A:A,A' . $r . ',Transporte!E:E)+SUMIF(\'Gastos Generales\'!A:A,A' . $r . ',\'Gastos Generales\'!E:E)');
            $celdas .= self::formula(6, $r, "SUMIF(Clientes!A:A,A" . $r . ",Clientes!I:I)+SUMIF('Nómina'!A:A,A" . $r . ",'Nómina'!I:I)+SUMIF(Productores!A:A,A" . $r . ',Productores!I:I)+SUMIF(Transporte!A:A,A' . $r . ',Transporte!I:I)+SUMIF(\'Gastos Generales\'!A:A,A' . $r . ',\'Gastos Generales\'!I:I)');
            $celdas .= self::formula(7, $r, 'C' . $r . '+D' . $r . '-E' . $r . '-F' . $r);
            if ($i === 0 && $tasa !== null) {
                $celdas .= self::numero(9, $r, $tasa);
            } elseif ($i > 0 && $tasa !== null) {
                $celdas .= self::formula(9, $r, '$I$2');
            }
            $celdas .= self::formula(10, $r, 'IF(IFERROR(VLOOKUP(A' . $r . ',Parametros!$A$2:$B$' . self::TOPE_LISTA . ',2,FALSE),"BS")="USD",H' . $r . ',IF(I' . $r . '="","",ROUND(H' . $r . '/I' . $r . ',2)))');
            $xml .= '<row r="' . $r . '">' . $celdas . '</row>';
        }
        return $xml;
    }

    private static function operativa(string $encabezado, DateTimeImmutable $fecha): string
    {
        $xml = $encabezado;
        $fechaIso = $fecha->format('Y-m-d');
        $hasta = self::FILAS + 1;
        for ($r = 2; $r <= $hasta; $r++) {
            $xml .= '<row r="' . $r . '">'
                . self::texto(2, $r, $fechaIso)
                . self::formula(6, $r, 'IF(OR(E' . $r . '="",J' . $r . '="",J' . $r . '<=0),"",ROUND(E' . $r . '/J' . $r . ',2))')
                . self::formula(9, $r, 'IF(E' . $r . '="","",IF(OR(G' . $r . '="Pago Móvil",G' . $r . '="Liquidación Punto de Venta"),ROUND(E' . $r . '*0.015,2),IF(G' . $r . '="Transferencia Otros Bancos",ROUND(E' . $r . '*0.003,2),0)))')
                . self::formula(10, $r, 'IF(E' . $r . '="","",\'Total Bancos\'!$I$2)')
                . '</row>';
        }
        return $xml;
    }

    private static function validaciones(int $nCuentas, int $nBancos, ?string $colNombre, int $nNombres): string
    {
        $hasta = self::FILAS + 1;
        $items = [];
        if ($nCuentas > 0) {
            $items[] = self::lista('A2:A' . $hasta, 'Parametros!$A$2:$A$' . ($nCuentas + 1), 'Cuenta', 'Elige una cuenta de Parametros.');
        }
        $items[] = self::lista('G2:G' . $hasta, 'Parametros!$D$2:$D$' . (count(self::METODOS) + 1), 'Método', 'Elige un método de pago.');
        if ($nBancos > 0) {
            $items[] = self::lista('H2:H' . $hasta, 'Parametros!$C$2:$C$' . ($nBancos + 1), 'Banco', 'Elige un banco del catálogo.');
        }
        if ($colNombre !== null && $nNombres > 0) {
            $items[] = self::lista(
                'D2:D' . $hasta,
                'Parametros!$' . $colNombre . '$2:$' . $colNombre . '$' . ($nNombres + 1),
                'Nombre',
                'Puedes elegir de la lista o escribir un nombre que todavía no esté en ADARA.',
                false
            );
        }
        return '<dataValidations count="' . count($items) . '">' . implode('', $items) . '</dataValidations>';
    }

    private static function lista(string $sqref, string $formula, string $titulo, string $error, bool $bloquear = true): string
    {
        $aviso = $bloquear
            ? ' showErrorMessage="1" errorTitle="' . htmlspecialchars($titulo, ENT_XML1) . '" error="' . htmlspecialchars($error, ENT_XML1) . '"'
            : ' showErrorMessage="0" showInputMessage="1" promptTitle="' . htmlspecialchars($titulo, ENT_XML1) . '" prompt="' . htmlspecialchars($error, ENT_XML1) . '"';
        return '<dataValidation type="list" allowBlank="1" showDropDown="0"' . $aviso
            . ' sqref="' . $sqref . '"><formula1>' . $formula . '</formula1></dataValidation>';
    }

    /** @param array<string, string> $saldos */
    private static function saldoDe(array $saldos, string $alias): ?string
    {
        if ($alias === '') {
            return null;
        }
        if (isset($saldos[$alias])) {
            return self::decimalPlano($saldos[$alias]);
        }
        $clave = mb_strtoupper($alias);
        foreach ($saldos as $nombre => $valor) {
            if (mb_strtoupper((string) $nombre) === $clave) {
                return self::decimalPlano((string) $valor);
            }
        }
        return null;
    }

    private static function decimalPlano(string $valor): ?string
    {
        $valor = str_replace([' ', ','], ['', '.'], trim($valor));
        if (!preg_match('/^-?\d+(\.\d+)?$/', $valor)) {
            return null;
        }
        return $valor;
    }

    private static function tasaPlana(?string $tasa): ?string
    {
        if ($tasa === null || trim($tasa) === '') {
            return null;
        }
        $tasa = str_replace([' ', ','], ['', '.'], trim($tasa));
        if (!preg_match('/^\d+(\.\d+)?$/', $tasa) || (float) $tasa <= 0) {
            return null;
        }
        return $tasa;
    }

    /**
     * @param list<array{alias:string, moneda?:string}|string> $cuentas
     * @return list<array{alias:string, moneda:string}>
     */
    private static function cuentasLimpias(array $cuentas): array
    {
        $out = [];
        $vistos = [];
        foreach ($cuentas as $item) {
            if (is_array($item)) {
                $nombre = (string) ($item['alias'] ?? '');
                $moneda = strtoupper(trim((string) ($item['moneda'] ?? 'BS')));
            } else {
                $nombre = (string) $item;
                $moneda = 'BS';
            }
            $nombre = self::limpio($nombre);
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

    /** @param list<string> $nombres @return list<string> */
    private static function limpiar(array $nombres): array
    {
        $out = [];
        $vistos = [];
        foreach ($nombres as $nombre) {
            $nombre = self::limpio((string) $nombre);
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

    private static function limpio(string $nombre): string
    {
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $nombre) ?? '');
    }

    /** @return array<string, array{path:string, nombre:string}> */
    private static function hojas(ZipArchive $zip): array
    {
        $wb = self::xml($zip, 'xl/workbook.xml');
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        $map = [];
        foreach ($rels->Relationship as $rel) {
            $id = (string) $rel['Id'];
            $target = ltrim(str_replace('\\', '/', (string) $rel['Target']), '/');
            $map[$id] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
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
        foreach (['PARAMETROS', 'TOTAL BANCOS', 'CLIENTES', 'NOMINA', 'PRODUCTORES', 'TRANSPORTE', 'GASTOS GENERALES'] as $clave) {
            if (!isset($hojas[$clave])) {
                throw new RuntimeException('La plantilla v4 no tiene la hoja ' . $clave . '.');
            }
        }
        return $hojas;
    }

    private static function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $raw = XmlSeguro::desdeZip($zip, $path);
        if ($raw === null) {
            throw new RuntimeException('No encuentro ' . $path . '.');
        }
        return XmlSeguro::cargar($raw);
    }

    private static function norm(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        $s = mb_strtoupper($s);
        return strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }

    private static function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private static function texto(int $col, int $fila, string $valor): string
    {
        return '<c r="' . self::col($col) . $fila . '" t="inlineStr"><is><t xml:space="preserve">'
            . htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
    }

    private static function numero(int $col, int $fila, string $valor): string
    {
        return '<c r="' . self::col($col) . $fila . '"><v>' . $valor . '</v></c>';
    }

    private static function formula(int $col, int $fila, string $fx): string
    {
        // Dentro de <f> Excel quiere las comillas literales. Solo se escapan & < >.
        $fx = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $fx);
        return '<c r="' . self::col($col) . $fila . '"><f>' . $fx . '</f></c>';
    }
}
