<?php
declare(strict_types=1);

/**
 * Lee el Excel de conciliación.
 *
 * PhpSpreadsheet no está en el proyecto: ADARA arma y lee xlsx con ZIP
 * (ver XlsxWriter). Para usarlo más adelante, en la raíz del proyecto:
 *
 *   composer require phpoffice/phpspreadsheet
 *
 * y en config/bootstrap.php, antes de este archivo:
 *
 *   $autoload = ADARA_ROOT . '/vendor/autoload.php';
 *   if (is_file($autoload)) { require_once $autoload; }
 *
 * Si la clase PhpOffice\PhpSpreadsheet\IOFactory existe, este lector la usa.
 * Si no, lee el OOXML con ZipArchive. La validación de la caja no cambia.
 *
     * Formatos aceptados:
     *   1. Libro clasificado: Total Bancos + Clientes, Nómina, Productores,
     *      Transporte, Gastos Generales y Parametros.
     *   2. Plantilla ADARA, hojas "Saldos" y "Movimientos".
     *   3. Libro diario del cash manager: una hoja por fecha (dd-mm-aaaa) con
     *      proveedores, gastos generales, ingresos y el cuadro INICIO / FINAL.
 */
final class ConciliacionExcel
{
    private const ALIAS = [
        'BNC EMRESA' => 'BNC EMPRESA',
    ];

    /**
     * @return array{
     *   formato:string,
     *   saldos:list<array{cuenta:string,fecha:string,saldo_inicial:int,saldo_final:int,hoja:string,fila:int}>,
     *   movimientos:list<array{cuenta:string,fecha:string,tipo:string,categoria:string,descripcion:string,beneficiario:?string,referencia:?string,monto_bs:int,comision_bs:int,monto_usd:?string,tasa_usd_dia:string,hoja:string,fila:int}>
     * }
     */
    public static function leer(string $path): array
    {
        if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            $hojas = self::hojasPhpSpreadsheet($path);
        } else {
            $hojas = self::hojasZip($path);
        }
        if ($hojas === []) {
            throw new ValidationException(['archivo' => 'El Excel no tiene hojas.']);
        }

        $nombres = array_keys($hojas);
        foreach ($nombres as $nombre) {
            if (self::norm($nombre) === 'TOTAL BANCOS') {
                return self::leerClasificada($hojas);
            }
        }
        foreach ($nombres as $nombre) {
            if (self::norm($nombre) === 'SALDOS') {
                return self::leerPlantilla($hojas);
            }
        }
        return self::leerLibro($hojas);
    }

    public static function plantilla(): XlsxWriter
    {
        $xlsx = new XlsxWriter();
        $xlsx->hoja(
            'Saldos',
            'Saldos — una fila por cuenta y fecha. Borra la fila de ejemplo.',
            ['Cuenta', 'Fecha', 'Saldo Inicial', 'Saldo Final Declarado', 'Nota'],
            [[
                ['BNC EMPRESA', XlsxWriter::S_TEXTO],
                ['2026-10-01', XlsxWriter::S_TEXTO],
                [1000, XlsxWriter::S_BS],
                [850, XlsxWriter::S_BS],
                ['EJEMPLO', XlsxWriter::S_TEXTO],
            ]]
        );
        $xlsx->hoja(
            'Movimientos',
            'Movimientos del día. Tipo: Ingreso o Egreso. Borra la fila de ejemplo.',
            ['Cuenta', 'Fecha', 'Tipo', 'Categoría', 'Descripción', 'Referencia', 'Monto BS', 'Comisión BS', 'Tasa USD', 'Beneficiario', 'Nota'],
            [[
                ['BNC EMPRESA', XlsxWriter::S_TEXTO],
                ['2026-10-01', XlsxWriter::S_TEXTO],
                ['Egreso', XlsxWriter::S_TEXTO],
                ['Gastos generales', XlsxWriter::S_TEXTO],
                ['EJEMPLO: borra esta fila', XlsxWriter::S_TEXTO],
                ['EJEMPLO', XlsxWriter::S_TEXTO],
                [150, XlsxWriter::S_BS],
                [0, XlsxWriter::S_BS],
                [880.11, XlsxWriter::S_USD4],
                ['', XlsxWriter::S_TEXTO],
                ['EJEMPLO', XlsxWriter::S_TEXTO],
            ]]
        );
        return $xlsx;
    }

    /** @return array<string, array{tipo:string, categoria:string, modulo:string}> */
    private const HOJAS_CLASIFICADAS = [
        'CLIENTES' => ['tipo' => 'ingreso', 'categoria' => 'Clientes', 'modulo' => 'clientes'],
        'NOMINA' => ['tipo' => 'egreso', 'categoria' => 'Nómina', 'modulo' => 'nomina'],
        'PRODUCTORES' => ['tipo' => 'egreso', 'categoria' => 'Productores', 'modulo' => 'productores'],
        'TRANSPORTE' => ['tipo' => 'egreso', 'categoria' => 'Transporte', 'modulo' => 'transporte'],
        'GASTOS GENERALES' => ['tipo' => 'egreso', 'categoria' => 'Gastos generales', 'modulo' => 'gastos'],
    ];

    /**
     * El tipo y el módulo salen del nombre de la hoja, no de una columna.
     *
     * @param array<string, array<int, array<int, string>>> $hojas
     */
    private static function leerClasificada(array $hojas): array
    {
        $total = null;
        $nombreTotal = 'Total Bancos';
        $operativas = [];
        foreach ($hojas as $nombre => $grid) {
            $clave = self::norm($nombre);
            if ($clave === 'TOTAL BANCOS') {
                $total = $grid;
                $nombreTotal = $nombre;
            } elseif (isset(self::HOJAS_CLASIFICADAS[$clave])) {
                $operativas[$clave] = ['nombre' => $nombre, 'grid' => $grid];
            }
        }
        if ($total === null) {
            throw new ValidationException(['archivo' => 'El libro clasificado necesita la hoja Total Bancos.']);
        }

        $saldos = [];
        $tasas = [];
        foreach (self::filasTabla($total, [
            'cuenta' => ['CUENTA', 'BANCO', 'ALIAS'],
            'fecha' => ['FECHA', 'DIA'],
            'inicio' => ['SALDO INICIAL', 'SALDO INICIO', 'INICIO'],
            'final' => ['SALDO FINAL BANCO', 'SALDO FINAL DECLARADO'],
            'tasa' => ['TASA USD', 'TASA'],
        ], ['cuenta', 'inicio']) as $fila) {
            $cuentaRaw = trim($fila['cuenta'] ?? '');
            if ($cuentaRaw === '') {
                continue;
            }
            $fecha = self::fecha($fila['fecha'] ?? '', '');
            if ($fecha === null) {
                throw new ValidationException(['archivo' => 'En Total Bancos, fila ' . $fila['_fila'] . ', la fecha no es válida.']);
            }
            $inicioRaw = trim($fila['inicio'] ?? '');
            if ($inicioRaw === '' || !self::esNumero($inicioRaw)) {
                throw new ValidationException(['archivo' => 'En Total Bancos, fila ' . $fila['_fila'] . ', falta el saldo inicial de ' . $cuentaRaw . '.']);
            }
            $finalRaw = trim($fila['final'] ?? '');
            $tieneDeclarado = $finalRaw !== '' && self::esNumero($finalRaw);
            $tasa = self::tasa($fila['tasa'] ?? '');
            $alias = self::alias($cuentaRaw);
            if ($tasa !== null) {
                $tasas[$fecha . '|' . $alias] = $tasa;
                $tasas[$fecha] ??= $tasa;
            }
            $saldos[] = [
                'cuenta' => $alias,
                'fecha' => $fecha,
                'saldo_inicial' => self::centavos($inicioRaw, $nombreTotal, (int) $fila['_fila'], 'saldo inicial'),
                'saldo_final' => $tieneDeclarado
                    ? self::centavos($finalRaw, $nombreTotal, (int) $fila['_fila'], 'saldo final banco')
                    : 0,
                'tiene_declarado' => $tieneDeclarado,
                'hoja' => $nombreTotal,
                'fila' => (int) $fila['_fila'],
            ];
        }
        if ($saldos === []) {
            throw new ValidationException(['archivo' => 'Total Bancos no trae cuentas con saldo inicial.']);
        }

        $tasaUnica = null;
        foreach ($tasas as $clave => $valor) {
            if (!str_contains((string) $clave, '|')) {
                $tasaUnica = $valor;
                break;
            }
        }
        if ($tasaUnica === null && $tasas !== []) {
            $tasaUnica = reset($tasas) ?: null;
        }

        $movimientos = [];
        foreach (self::HOJAS_CLASIFICADAS as $clave => $clase) {
            $bloque = $operativas[$clave] ?? null;
            if ($bloque === null) {
                continue;
            }
            foreach (self::filasTabla($bloque['grid'], [
                'cuenta' => ['CUENTA', 'BANCO'],
                'fecha' => ['FECHA', 'DIA'],
                'referencia' => ['REFERENCIA', 'REF'],
                'entidad' => ['CLIENTE', 'EMPLEADO', 'BENEFICIARIO', 'NOMBRES', 'NOMBRE', 'PRODUCTOR', 'TRANSPORTISTA'],
                'monto' => ['MONTO BS', 'MONTO'],
                'monto_usd' => ['MONTO USD'],
                'metodo' => ['METODO', 'METODO DE PAGO'],
                'banco' => ['BANCO ORIGEN', 'BANCO DESTINO', 'BANCO ORIGEN/DESTINO', 'BANCO ORIGEN DESTINO'],
                'comision' => ['COMISION BS', 'COMISION'],
                'tasa' => ['TASA USD', 'TASA'],
                'descripcion' => ['DESCRIPCION'],
            ], ['cuenta', 'monto']) as $fila) {
                $cuentaRaw = trim($fila['cuenta'] ?? '');
                $montoRaw = trim($fila['monto'] ?? '');
                $entidad = self::opcional($fila['entidad'] ?? '');
                $ref = self::opcional($fila['referencia'] ?? '');
                $desc = trim($fila['descripcion'] ?? '');
                $metodo = self::opcional($fila['metodo'] ?? '');
                $banco = self::opcional($fila['banco'] ?? '');
                $tieneTexto = $cuentaRaw !== '' || $entidad !== null || $ref !== null || $desc !== '' || $metodo !== null || $banco !== null;
                if ($montoRaw === '' || !self::esNumero($montoRaw)) {
                    if ($tieneTexto) {
                        throw new ValidationException(['archivo' => 'En ' . $bloque['nombre'] . ', fila ' . $fila['_fila'] . ', falta el monto.']);
                    }
                    continue;
                }
                if ($cuentaRaw === '') {
                    throw new ValidationException(['archivo' => 'En ' . $bloque['nombre'] . ', fila ' . $fila['_fila'] . ', falta la cuenta.']);
                }
                $fecha = self::fecha($fila['fecha'] ?? '', '');
                if ($fecha === null) {
                    throw new ValidationException(['archivo' => 'En ' . $bloque['nombre'] . ', fila ' . $fila['_fila'] . ', la fecha no es válida.']);
                }
                $monto = self::centavos($montoRaw, $bloque['nombre'], (int) $fila['_fila'], 'monto');
                if ($monto <= 0) {
                    continue;
                }
                $comisionRaw = trim($fila['comision'] ?? '');
                if ($comisionRaw !== '' && self::esNumero($comisionRaw)) {
                    $comision = self::centavos($comisionRaw, $bloque['nombre'], (int) $fila['_fila'], 'comisión');
                } else {
                    $comision = self::comisionDeMetodo($monto, (string) ($metodo ?? ''));
                }
                if ($comision < 0) {
                    throw new ValidationException(['archivo' => 'En ' . $bloque['nombre'] . ', fila ' . $fila['_fila'] . ', la comisión no puede ser negativa.']);
                }
                $alias = self::alias($cuentaRaw);
                $tasa = self::tasa($fila['tasa'] ?? '')
                    ?? ($tasas[$fecha . '|' . $alias] ?? null)
                    ?? ($tasas[$fecha] ?? null)
                    ?? $tasaUnica;
                if ($tasa === null) {
                    throw new ValidationException(['archivo' => 'En ' . $bloque['nombre'] . ', fila ' . $fila['_fila'] . ', falta la tasa BCV. Está en Total Bancos, columna Tasa USD.']);
                }
                $usdRaw = trim($fila['monto_usd'] ?? '');
                $movimientos[] = self::movimiento(
                    $alias,
                    $fecha,
                    $clase['tipo'],
                    $clase['categoria'],
                    $desc,
                    $entidad,
                    $ref,
                    $monto,
                    $comision,
                    ($usdRaw !== '' && self::esNumero($usdRaw)) ? self::decimal($usdRaw, 4) : null,
                    $tasa,
                    $bloque['nombre'],
                    (int) $fila['_fila'],
                    $metodo,
                    $banco,
                    $entidad,
                    $clase['modulo']
                );
            }
        }

        return ['formato' => 'clasificada', 'saldos' => $saldos, 'movimientos' => $movimientos];
    }

    /** Comisión de la Gaceta cuando el Excel no trae el valor ya calculado. */
    private static function comisionDeMetodo(int $montoCentavos, string $metodo): int
    {
        $n = self::norm($metodo);
        $tasa = match ($n) {
            'PAGO MOVIL', 'LIQUIDACION PUNTO DE VENTA' => 0.015,
            'TRANSFERENCIA OTROS BANCOS' => 0.003,
            default => 0.0,
        };
        if ($tasa === 0.0) {
            return 0;
        }
        return (int) round($montoCentavos * $tasa);
    }

    /** @param array<string, array<int, array<int, string>>> $hojas */
    private static function leerPlantilla(array $hojas): array
    {
        $saldosHoja = null;
        $movHoja = null;
        $nombreSaldos = '';
        $nombreMov = '';
        foreach ($hojas as $nombre => $grid) {
            $n = self::norm($nombre);
            if ($n === 'SALDOS') {
                $saldosHoja = $grid;
                $nombreSaldos = $nombre;
            } elseif ($n === 'MOVIMIENTOS') {
                $movHoja = $grid;
                $nombreMov = $nombre;
            }
        }
        if ($saldosHoja === null || $movHoja === null) {
            throw new ValidationException(['archivo' => 'La plantilla necesita las hojas Saldos y Movimientos.']);
        }

        $saldos = [];
        foreach (self::filasTabla($saldosHoja, [
            'cuenta' => ['CUENTA', 'BANCO', 'ALIAS'],
            'fecha' => ['FECHA', 'DIA'],
            'inicio' => ['SALDO INICIAL', 'SALDO INICIO', 'INICIO'],
            'final' => ['SALDO FINAL DECLARADO', 'SALDO FINAL', 'FINAL'],
            'nota' => ['NOTA'],
        ], ['cuenta', 'inicio', 'final']) as $fila) {
            if (self::esEjemplo($fila['nota'] ?? '', $fila['cuenta'])) {
                continue;
            }
            $fecha = self::fecha($fila['fecha'] ?? '', $nombreSaldos);
            if ($fecha === null) {
                throw new ValidationException(['archivo' => 'En Saldos, fila ' . $fila['_fila'] . ', la fecha no es válida.']);
            }
            $saldos[] = [
                'cuenta' => self::alias($fila['cuenta']),
                'fecha' => $fecha,
                'saldo_inicial' => self::centavos($fila['inicio'], 'Saldos', (int) $fila['_fila'], 'saldo inicial'),
                'saldo_final' => self::centavos($fila['final'], 'Saldos', (int) $fila['_fila'], 'saldo final'),
                'hoja' => $nombreSaldos,
                'fila' => (int) $fila['_fila'],
            ];
        }

        $movimientos = [];
        foreach (self::filasTabla($movHoja, [
            'cuenta' => ['CUENTA', 'BANCO'],
            'fecha' => ['FECHA', 'DIA'],
            'tipo' => ['TIPO'],
            'categoria' => ['CATEGORIA'],
            'descripcion' => ['DESCRIPCION'],
            'referencia' => ['REFERENCIA', 'REF'],
            'monto' => ['MONTO BS', 'MONTO'],
            'monto_usd' => ['MONTO USD'],
            'entidad' => ['ENTIDAD', 'ENTIDAD RELACIONADA'],
            'metodo' => ['METODO', 'METODO DE PAGO'],
            'banco' => ['BANCO ORIGEN/DESTINO', 'BANCO ORIGEN DESTINO'],
            'comision' => ['COMISION BS', 'COMISION'],
            'tasa' => ['TASA USD', 'TASA USD DIA', 'TASA'],
            'beneficiario' => ['BENEFICIARIO', 'NOMBRES', 'NOMBRE'],
            'nota' => ['NOTA'],
        ], ['cuenta', 'tipo', 'monto']) as $fila) {
            if (self::esEjemplo($fila['nota'] ?? '', $fila['descripcion'] ?? '', $fila['referencia'] ?? '')) {
                continue;
            }
            // La plantilla trae la tasa BCV en la primera fila vacía. Sin cuenta ni monto no es un movimiento.
            if (trim($fila['cuenta'] ?? '') === '' && trim($fila['monto'] ?? '') === '' && trim($fila['tipo'] ?? '') === '') {
                continue;
            }
            $fecha = self::fecha($fila['fecha'] ?? '', '');
            if ($fecha === null) {
                throw new ValidationException(['archivo' => 'En Movimientos, fila ' . $fila['_fila'] . ', la fecha no es válida.']);
            }
            $tipo = self::tipo($fila['tipo'] ?? '');
            if ($tipo === null) {
                throw new ValidationException(['archivo' => 'En Movimientos, fila ' . $fila['_fila'] . ', el tipo debe ser Ingreso o Egreso.']);
            }
            $monto = self::centavos($fila['monto'], 'Movimientos', (int) $fila['_fila'], 'monto');
            $comisionRaw = trim($fila['comision'] ?? '');
            $comision = $comisionRaw === '' ? 0 : self::centavos($comisionRaw, 'Movimientos', (int) $fila['_fila'], 'comisión');
            if ($monto <= 0) {
                throw new ValidationException(['archivo' => 'En Movimientos, fila ' . $fila['_fila'] . ', el monto debe ser mayor que cero.']);
            }
            if ($comision < 0) {
                throw new ValidationException(['archivo' => 'En Movimientos, fila ' . $fila['_fila'] . ', la comisión no puede ser negativa.']);
            }
            $tasa = self::tasa($fila['tasa'] ?? '');
            if ($tasa === null) {
                throw new ValidationException(['archivo' => 'En Movimientos, fila ' . $fila['_fila'] . ', falta la tasa USD del día.']);
            }
            $usdRaw = trim($fila['monto_usd'] ?? '');
            $montoUsd = ($usdRaw !== '' && self::esNumero($usdRaw)) ? self::decimal($usdRaw, 4) : null;
            $entidad = self::opcional($fila['entidad'] ?? '');
            if ($entidad === null) {
                $entidad = self::opcional($fila['beneficiario'] ?? '');
            }
            $categoria = trim($fila['categoria'] ?? '');
            if ($categoria === '') {
                $categoria = 'General';
            }
            $movimientos[] = self::movimiento(
                self::alias($fila['cuenta']),
                $fecha,
                $tipo,
                $categoria,
                trim($fila['descripcion'] ?? ''),
                self::opcional($fila['beneficiario'] ?? ''),
                self::opcional($fila['referencia'] ?? ''),
                $monto,
                $comision,
                $montoUsd,
                $tasa,
                $nombreMov,
                (int) $fila['_fila'],
                self::opcional($fila['metodo'] ?? ''),
                self::opcional($fila['banco'] ?? ''),
                $entidad
            );
        }

        if ($saldos === []) {
            throw new ValidationException(['archivo' => 'El archivo no trae saldos. Borra la fila de ejemplo y carga el día.']);
        }
        return ['formato' => 'plantilla', 'saldos' => $saldos, 'movimientos' => $movimientos];
    }

    /** @param array<string, array<int, array<int, string>>> $hojas */
    private static function leerLibro(array $hojas): array
    {
        $saldos = [];
        $movimientos = [];
        $vioDia = false;
        foreach ($hojas as $nombre => $grid) {
            $fecha = self::fechaHoja($nombre);
            if ($fecha === null) {
                continue;
            }
            $vioDia = true;
            $tasa = self::tasaBcv($grid);
            $movs = self::movimientosDia($grid, $fecha, $tasa, $nombre);
            $saldosDia = self::saldosDia($grid, $fecha, $nombre);
            if ($movs === [] && $saldosDia === []) {
                continue;
            }
            if ($saldosDia === []) {
                throw new ValidationException([
                    'archivo' => 'La hoja ' . $nombre . ' tiene movimientos y no tiene el cuadro de saldos (INICIO / FINAL).',
                ]);
            }
            if ($movs !== [] && $tasa === null) {
                throw new ValidationException([
                    'archivo' => 'La hoja ' . $nombre . ' no tiene la tasa del Banco Central (celda E3).',
                ]);
            }
            foreach ($saldosDia as $saldo) {
                $saldos[] = $saldo;
            }
            foreach ($movs as $mov) {
                $movimientos[] = $mov;
            }
        }
        if (!$vioDia || $saldos === []) {
            throw new ValidationException([
                'archivo' => 'No reconozco el archivo. Usa la plantilla (hojas Saldos y Movimientos) o el libro diario con una hoja por fecha.',
            ]);
        }
        return ['formato' => 'libro', 'saldos' => $saldos, 'movimientos' => $movimientos];
    }

    /**
     * @param array<int, array<int, string>> $grid
     * @return list<array{cuenta:string,fecha:string,tipo:string,categoria:string,descripcion:string,beneficiario:?string,referencia:?string,monto_bs:int,comision_bs:int,monto_usd:?string,tasa_usd_dia:string,hoja:string,fila:int}>
     */
    private static function movimientosDia(array $grid, string $fecha, ?string $tasa, string $hoja): array
    {
        $max = $grid === [] ? 0 : max(array_keys($grid));
        $seccion = 0;
        $enTabla = false;
        $out = [];
        for ($r = 1; $r <= $max; $r++) {
            $a = self::norm(self::celda($grid, $r, 1));
            $b = self::norm(self::celda($grid, $r, 2));
            $cRaw = self::celda($grid, $r, 3);
            $c = self::norm($cRaw);
            if ($c === 'INICIO') {
                break;
            }
            if ($a === 'BANCO' && str_contains($b, 'REFERENCIA')) {
                $seccion++;
                $enTabla = true;
                continue;
            }
            if (!$enTabla) {
                continue;
            }
            if ($a === '' && self::esNumero($cRaw)) {
                $enTabla = false;
                continue;
            }
            if ($a === '' || str_starts_with($a, 'TOTAL')) {
                continue;
            }
            $montoRaw = $cRaw;
            $comisionRaw = self::celda($grid, $r, 4);
            $usdRaw = self::celda($grid, $r, 5);
            $nombre = trim(self::celda($grid, $r, 6));
            $desc = trim(self::celda($grid, $r, 7));
            $ref = trim(self::celda($grid, $r, 2));
            if (!self::esNumero($montoRaw)) {
                continue;
            }
            $monto = self::centavos($montoRaw, $hoja, $r, 'monto');
            $comision = self::esNumero($comisionRaw) ? self::centavos($comisionRaw, $hoja, $r, 'comisión') : 0;
            if ($monto === 0 && $comision === 0 && $ref === '' && $nombre === '' && $desc === '') {
                continue;
            }
            if ($monto <= 0) {
                continue;
            }
            if ($comision < 0) {
                throw new ValidationException(['archivo' => 'En ' . $hoja . ', fila ' . $r . ', la comisión es negativa.']);
            }
            if ($tasa === null) {
                throw new ValidationException(['archivo' => 'La hoja ' . $hoja . ' no tiene la tasa del Banco Central (celda E3).']);
            }
            [$tipo, $categoria] = self::seccionLibro($seccion, $desc);
            $out[] = self::movimiento(
                self::alias(self::celda($grid, $r, 1)),
                $fecha,
                $tipo,
                $categoria,
                $desc,
                $nombre !== '' ? $nombre : null,
                $ref !== '' ? $ref : null,
                $monto,
                $comision,
                self::esNumero($usdRaw) ? self::decimal($usdRaw, 4) : null,
                $tasa,
                $hoja,
                $r,
                null,
                null
            );
        }
        return $out;
    }

    /** @return array{0:string,1:string} */
    private static function seccionLibro(int $seccion, string $desc): array
    {
        if (self::norm($desc) === 'NOMINA') {
            return ['egreso', 'Nómina'];
        }
        return match ($seccion) {
            1 => ['egreso', 'Proveedores de leche y transportistas'],
            2 => ['egreso', 'Gastos generales'],
            default => ['ingreso', 'Ingresos'],
        };
    }

    /**
     * @param array<int, array<int, string>> $grid
     * @return list<array{cuenta:string,fecha:string,saldo_inicial:int,saldo_final:int,hoja:string,fila:int}>
     */
    private static function saldosDia(array $grid, string $fecha, string $hoja): array
    {
        $max = $grid === [] ? 0 : max(array_keys($grid));
        $filaInicio = null;
        for ($r = 1; $r <= $max; $r++) {
            if (self::norm(self::celda($grid, $r, 3)) === 'INICIO' && self::norm(self::celda($grid, $r, 6)) === 'FINAL') {
                $filaInicio = $r;
                break;
            }
        }
        if ($filaInicio === null) {
            return [];
        }
        $out = [];
        for ($r = $filaInicio + 1; $r <= $max; $r++) {
            $a = trim(self::celda($grid, $r, 1));
            $n = self::norm($a);
            if ($n === '' || str_starts_with($n, 'TOTAL')) {
                if (str_starts_with($n, 'TOTAL')) {
                    break;
                }
                continue;
            }
            $inicioRaw = self::celda($grid, $r, 3);
            $finalRaw = self::celda($grid, $r, 6);
            if (!self::esNumero($inicioRaw) || !self::esNumero($finalRaw)) {
                continue;
            }
            $out[] = [
                'cuenta' => self::alias($a),
                'fecha' => $fecha,
                'saldo_inicial' => self::centavos($inicioRaw, $hoja, $r, 'saldo inicial'),
                'saldo_final' => self::centavos($finalRaw, $hoja, $r, 'saldo final'),
                'hoja' => $hoja,
                'fila' => $r,
            ];
        }
        return $out;
    }

    /** @param array<int, array<int, string>> $grid */
    private static function tasaBcv(array $grid): ?string
    {
        for ($r = 1; $r <= 6; $r++) {
            if (self::norm(self::celda($grid, $r, 4)) === 'BANCO CENTRAL') {
                $tasa = self::tasa(self::celda($grid, $r, 5));
                if ($tasa !== null) {
                    return $tasa;
                }
            }
        }
        return self::tasa(self::celda($grid, 3, 5));
    }

    /**
     * @param array<int, array<int, string>> $grid
     * @param array<string, list<string>> $columnas
     * @param list<string> $requeridas
     * @return list<array<string, string>>
     */
    private static function filasTabla(array $grid, array $columnas, array $requeridas): array
    {
        $max = $grid === [] ? 0 : max(array_keys($grid));
        $mapa = null;
        $filaHeader = 0;
        for ($r = 1; $r <= min($max, 25); $r++) {
            $mapa = self::mapearHeader($grid[$r] ?? [], $columnas);
            if ($mapa !== null) {
                foreach ($requeridas as $req) {
                    if (!isset($mapa[$req])) {
                        $mapa = null;
                        break;
                    }
                }
            }
            if ($mapa !== null) {
                $filaHeader = $r;
                break;
            }
        }
        if ($mapa === null) {
            throw new ValidationException(['archivo' => 'No encuentro los encabezados de la plantilla. Descarga de nuevo el modelo.']);
        }
        $filas = [];
        for ($r = $filaHeader + 1; $r <= $max; $r++) {
            $fila = ['_fila' => (string) $r];
            $vacia = true;
            foreach ($mapa as $campo => $col) {
                $fila[$campo] = trim(self::celda($grid, $r, $col));
                if ($fila[$campo] !== '') {
                    $vacia = false;
                }
            }
            if (!$vacia) {
                $filas[] = $fila;
            }
        }
        return $filas;
    }

    /**
     * @param array<int, string> $celdas
     * @param array<string, list<string>> $columnas
     * @return array<string, int>|null
     */
    private static function mapearHeader(array $celdas, array $columnas): ?array
    {
        $mapa = [];
        foreach ($celdas as $col => $texto) {
            $n = self::norm($texto);
            if ($n === '') {
                continue;
            }
            foreach ($columnas as $campo => $aliases) {
                if (isset($mapa[$campo])) {
                    continue;
                }
                foreach ($aliases as $alias) {
                    if ($n === $alias) {
                        $mapa[$campo] = (int) $col;
                    }
                }
            }
        }
        return $mapa === [] ? null : $mapa;
    }

    /** @return array<string, mixed> */
    private static function movimiento(
        string $cuenta,
        string $fecha,
        string $tipo,
        string $categoria,
        string $descripcion,
        ?string $beneficiario,
        ?string $referencia,
        int $monto,
        int $comision,
        ?string $montoUsd,
        string $tasa,
        string $hoja,
        int $fila,
        ?string $metodo = null,
        ?string $banco = null,
        ?string $entidad = null,
        ?string $modulo = null
    ): array {
        if ($cuenta === '') {
            throw new ValidationException(['archivo' => 'En ' . $hoja . ', fila ' . $fila . ', falta la cuenta.']);
        }
        return [
            'cuenta' => $cuenta,
            'fecha' => $fecha,
            'tipo' => $tipo,
            'categoria' => mb_substr($categoria, 0, 80),
            'metodo_pago' => $metodo !== null ? mb_substr($metodo, 0, 40) : null,
            'banco_origen_destino' => $banco !== null ? mb_substr($banco, 0, 80) : null,
            'descripcion' => mb_substr($descripcion, 0, 255),
            'beneficiario' => $beneficiario !== null ? mb_substr($beneficiario, 0, 120) : null,
            'entidad_relacionada' => $entidad !== null ? mb_substr($entidad, 0, 100) : null,
            'referencia' => $referencia !== null ? mb_substr($referencia, 0, 40) : null,
            'monto_bs' => $monto,
            'comision_bs' => $comision,
            'monto_usd' => $montoUsd,
            'tasa_usd_dia' => $tasa,
            'hoja' => mb_substr($hoja, 0, 40),
            'fila' => $fila,
            'modulo_origen' => $modulo !== null && $modulo !== '' ? mb_substr($modulo, 0, 50) : null,
        ];
    }

    /** @param array<string, array<int, array<int, string>>> $destino */
    private static function hojasZip(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ValidationException(['archivo' => 'No pude abrir el Excel. Guárdalo como .xlsx y vuelve a subirlo.']);
        }
        try {
            $wbRaw = XmlSeguro::desdeZip($zip, 'xl/workbook.xml');
            $relsRaw = XmlSeguro::desdeZip($zip, 'xl/_rels/workbook.xml.rels');
            if ($wbRaw === null || $relsRaw === null) {
                throw new ValidationException(['archivo' => 'El archivo no es un libro de Excel válido.']);
            }
            $wb = XmlSeguro::cargar($wbRaw);
            $rels = XmlSeguro::cargar($relsRaw);
            $ss = self::sharedStrings($zip);
            $map = [];
            foreach ($rels->Relationship as $rel) {
                $map[(string) $rel['Id']] = (string) $rel['Target'];
            }
            $hojas = [];
            foreach ($wb->sheets->sheet as $sheet) {
                $nombre = (string) $sheet['name'];
                $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target = self::target($map[$rid] ?? '');
                $raw = XmlSeguro::desdeZip($zip, $target);
                if ($raw === null) {
                    continue;
                }
                $hojas[$nombre] = self::grid($raw, $ss);
            }
            return $hojas;
        } catch (XmlRechazado $e) {
            throw new ValidationException(['archivo' => $e->getMessage()]);
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, array<int, array<int, string>>> $destino */
    private static function hojasPhpSpreadsheet(string $path): array
    {
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $hojas = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            $grid = [];
            foreach ($sheet->getRowIterator() as $row) {
                $r = $row->getRowIndex();
                $cells = [];
                foreach ($row->getCellIterator() as $cell) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn());
                    $valor = $cell->getCalculatedValue();
                    if ($valor === null || $valor === '') {
                        continue;
                    }
                    $cells[$col] = is_scalar($valor) ? (string) $valor : '';
                }
                if ($cells !== []) {
                    $grid[$r] = $cells;
                }
            }
            $hojas[$sheet->getTitle()] = $grid;
        }
        return $hojas;
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

    /** @param list<string> $ss
     *  @return array<int, array<int, string>>
     */
    private static function grid(string $xml, array $ss): array
    {
        $sx = XmlSeguro::cargar($xml);
        $grid = [];
        foreach ($sx->sheetData->row as $row) {
            $r = (int) $row['r'];
            foreach ($row->c as $c) {
                if (!preg_match('/^([A-Z]+)(\d+)$/', (string) $c['r'], $m)) {
                    continue;
                }
                $col = self::colIndex($m[1]);
            $t = (string) $c['t'];
            // Nunca leer <f>: es el texto de la fórmula, no el monto cacheado.
            $v = isset($c->v) ? (string) $c->v : '';
                if ($t === 's') {
                    $v = $ss[(int) $v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $v = isset($c->is->t) ? (string) $c->is->t : '';
                    if ($v === '' && isset($c->is->r)) {
                        foreach ($c->is->r as $run) {
                            $v .= (string) $run->t;
                        }
                    }
                }
                if ($v !== '') {
                    // Comisión BS es fórmula. Se guarda <v> (resultado) y se ignora <f>.
                    $grid[$r][$col] = $v;
                }
            }
        }
        return $grid;
    }

    /** @param array<int, array<int, string>> $grid */
    private static function celda(array $grid, int $fila, int $col): string
    {
        return $grid[$fila][$col] ?? '';
    }

    private static function colIndex(string $letras): int
    {
        $n = 0;
        foreach (str_split($letras) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n;
    }

    private static function target(string $t): string
    {
        $t = ltrim(str_replace('\\', '/', $t), '/');
        return str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
    }

    public static function alias(string $nombre): string
    {
        $n = self::norm($nombre);
        return self::ALIAS[$n] ?? $n;
    }

    public static function norm(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        $s = mb_strtoupper($s);
        return strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }

    private static function esEjemplo(string ...$textos): bool
    {
        foreach ($textos as $texto) {
            if (str_starts_with(self::norm($texto), 'EJEMPLO')) {
                return true;
            }
        }
        return false;
    }

    private static function fechaHoja(string $nombre): ?string
    {
        $nombre = trim($nombre);
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $nombre, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        return null;
    }

    private static function fecha(string $valor, string $fallbackHoja): ?string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return $fallbackHoja !== '' ? self::fechaHoja($fallbackHoja) : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
            return self::ymd((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $valor, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (is_numeric($valor)) {
            $serial = (int) floor((float) $valor);
            if ($serial > 20000 && $serial < 80000) {
                $base = new DateTimeImmutable('1899-12-30');
                return $base->modify('+' . $serial . ' days')->format('Y-m-d');
            }
        }
        return null;
    }

    private static function ymd(int $d, int $m, int $y): ?string
    {
        if (!checkdate($m, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private static function tipo(string $valor): ?string
    {
        $n = self::norm($valor);
        return match ($n) {
            'INGRESO', 'ENTRADA', 'I', 'ABONO RECIBIDO' => 'ingreso',
            'EGRESO', 'GASTO', 'SALIDA', 'E' => 'egreso',
            default => null,
        };
    }

    private static function tasa(string $valor): ?string
    {
        if (!self::esNumero($valor)) {
            return null;
        }
        $n = (float) self::numeroPlano($valor);
        if ($n <= 0) {
            return null;
        }
        return self::decimal($valor, 6);
    }

    public static function centavos(string $valor, string $hoja, int $fila, string $campo): int
    {
        if (!self::esNumero($valor)) {
            throw new ValidationException([
                'archivo' => 'En ' . $hoja . ', fila ' . $fila . ', ' . $campo . ' no es un monto.',
            ]);
        }
        $plano = self::numeroPlano($valor);
        $neg = str_starts_with($plano, '-');
        $plano = ltrim($plano, '-');
        [$ent, $frac] = array_pad(explode('.', $plano, 2), 2, '0');
        $frac = str_pad(substr($frac . '000', 0, 3), 3, '0');
        $cents = ((int) $ent) * 100 + (int) substr($frac, 0, 2);
        if ((int) $frac[2] >= 5) {
            $cents++;
        }
        if ($cents > 9_000_000_000_000) {
            throw new ValidationException(['archivo' => 'En ' . $hoja . ', fila ' . $fila . ', el monto es demasiado grande.']);
        }
        return $neg ? -$cents : $cents;
    }

    public static function desdeCentavos(int $cents): string
    {
        $neg = $cents < 0;
        $cents = abs($cents);
        return ($neg ? '-' : '') . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function formatoBs(int $cents): string
    {
        $neg = $cents < 0;
        $cents = abs($cents);
        $ent = number_format(intdiv($cents, 100), 0, ',', '.');
        return ($neg ? '-' : '') . $ent . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function decimal(string $valor, int $escala): string
    {
        $plano = self::numeroPlano($valor);
        $neg = str_starts_with($plano, '-');
        $plano = ltrim($plano, '-');
        [$ent, $frac] = array_pad(explode('.', $plano, 2), 2, '0');
        $frac = substr(str_pad($frac, $escala + 1, '0'), 0, $escala + 1);
        $redondeo = (int) substr($frac, $escala, 1) >= 5;
        $frac = substr($frac, 0, $escala);
        if ($redondeo) {
            $unido = (int) ($ent . $frac) + 1;
            $todo = str_pad((string) $unido, $escala + 1, '0', STR_PAD_LEFT);
            $ent = substr($todo, 0, -$escala);
            $frac = substr($todo, -$escala);
        }
        return ($neg ? '-' : '') . $ent . '.' . $frac;
    }

    private static function esNumero(string $valor): bool
    {
        $valor = trim($valor);
        if ($valor === '') {
            return false;
        }
        return (bool) preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$/', $valor)
            || (bool) preg_match('/^-?\d+(\.\d+)?$/', str_replace([' ', 'Bs.', 'Bs', '$'], '', $valor));
    }

    private static function numeroPlano(string $valor): string
    {
        $valor = str_replace([' ', 'Bs.', 'Bs', '$'], '', trim($valor));
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $valor)) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        } else {
            $valor = str_replace(',', '.', $valor);
        }
        return $valor;
    }

    private static function opcional(string $valor): ?string
    {
        $valor = trim($valor);
        return $valor === '' ? null : $valor;
    }
}
