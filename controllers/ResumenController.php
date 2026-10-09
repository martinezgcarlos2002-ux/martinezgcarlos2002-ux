<?php
declare(strict_types=1);

final class ResumenController
{
    private ResumenService $resumen;

    public function __construct()
    {
        $this->resumen = new ResumenService(get_db());
    }

    public function index(): void
    {
        try {
            [$inicio, $fin] = $this->rangoGet();
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            $hoy = date('Y-m-d');
            [$inicio, $fin] = [$hoy, $hoy];
        }
        view('resumen', [
            'inicio'    => $inicio,
            'fin'       => $fin,
            'hoy'       => date('Y-m-d'),
            'datos'     => $this->jsonDatos($this->resumen->porRango($inicio, $fin)),
            'endpoints' => [
                'datos' => url('resumen_datos_rango'),
                'pdf'   => url('resumen_pdf_rango'),
            ],
        ]);
    }

    public function datos(): void
    {
        try {
            [$inicio, $fin] = $this->rangoGet();
            json_response(200, [
                'ok'    => true,
                'datos' => $this->jsonDatos($this->resumen->porRango($inicio, $fin)),
            ]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        }
    }

    public function pdf(): void
    {
        try {
            [$inicio, $fin] = $this->rangoGet();
            $datos = $this->jsonDatos($this->resumen->porRango($inicio, $fin));
            header('Content-Disposition: inline; filename="resumen_operativo_' . $inicio . '_' . $fin . '.html"');
            view('resumen_pdf', [
                'inicio'  => $inicio,
                'fin'     => $fin,
                'empresa' => APP_EMPRESA,
                'datos'   => $datos,
            ]);
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('resumen');
        }
    }

    /** @return array{0:string, 1:string} */
    private function rangoGet(): array
    {
        $hoy = date('Y-m-d');
        $inicio = parse_date($_GET['inicio'] ?? '') ?? $hoy;
        $fin = parse_date($_GET['fin'] ?? '') ?? $hoy;
        if ($inicio > $fin) {
            throw new ValidationException(['inicio' => 'La fecha inicial no puede ser posterior a la final.']);
        }
        $dias = (new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fin))->days;
        if ($dias > ResumenService::MAX_DIAS) {
            throw new ValidationException(['fin' => 'El rango no puede superar un año.']);
        }
        if ($fin > $hoy) {
            $fin = $hoy;
        }
        return [$inicio, $fin];
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function jsonDatos(array $d): array
    {
        $k = $d['kpis'];
        $pl = $d['pl'] ?? [];
        $bal = $d['balances'] ?? [];
        return [
            'inicio' => $d['inicio'],
            'fin'    => $d['fin'],
            'kpis'   => [
                'litros'            => (float) $k['litros'],
                'kg'                => (float) $k['kg'],
                'lts'               => (float) ($k['lts'] ?? 0),
                'und'               => (float) ($k['und'] ?? 0),
                'rendimiento'       => (float) ($k['rendimiento'] ?? 0),
                'margen_pct'        => (float) ($k['margen_pct'] ?? 0),
                'valor_produccion'  => (float) $k['valor_produccion'],
                'facturado'         => (float) $k['facturado'],
                'cobrado'           => (float) $k['cobrado'],
                'devoluciones'      => (float) $k['devoluciones'],
                'transporte'        => (float) $k['transporte'],
                'litros_flete'      => (float) $k['litros_flete'],
                'costo_leche'       => (float) ($k['costo_leche'] ?? 0),
                'ingresos_netos'    => (float) ($k['ingresos_netos'] ?? 0),
                'utilidad'          => (float) ($k['utilidad'] ?? 0),
                'cxc_cartera'       => (float) ($k['cxc_cartera'] ?? 0),
                'cxc_vencida'       => (float) ($k['cxc_vencida'] ?? 0),
                'cxp_total'         => (float) ($k['cxp_total'] ?? 0),
            ],
            'pl' => [
                'ingresos_brutos'    => (float) ($pl['ingresos_brutos'] ?? 0),
                'devoluciones'       => (float) ($pl['devoluciones'] ?? 0),
                'ingresos_netos'     => (float) ($pl['ingresos_netos'] ?? 0),
                'costo_leche'        => (float) ($pl['costo_leche'] ?? 0),
                'costo_fletes'       => (float) ($pl['costo_fletes'] ?? 0),
                'costos_operativos'  => (float) ($pl['costos_operativos'] ?? 0),
                'utilidad'           => (float) ($pl['utilidad'] ?? 0),
                'margen_pct'         => (float) ($pl['margen_pct'] ?? 0),
            ],
            'balances' => [
                'cxc_cartera'          => (float) ($bal['cxc_cartera'] ?? 0),
                'cxc_vencida'          => (float) ($bal['cxc_vencida'] ?? 0),
                'cxc_cobrado_periodo'  => (float) ($bal['cxc_cobrado_periodo'] ?? 0),
                'cxp_productores'      => (float) ($bal['cxp_productores'] ?? 0),
                'cxp_fletes'           => (float) ($bal['cxp_fletes'] ?? 0),
                'cxp_total'            => (float) ($bal['cxp_total'] ?? 0),
            ],
            'produccion' => array_map(static fn (array $p): array => [
                'nombre'         => (string) $p['nombre'],
                'cantidad'       => (float) $p['cantidad'],
                'unidad_medida'  => (string) ($p['unidad_medida'] ?? 'KG'),
                'total_usd'      => (float) $p['total_usd'],
            ], $d['produccion']),
            'cobranzas' => array_map(static fn (array $c): array => [
                'metodo'    => (string) $c['metodo'],
                'etiqueta'  => (string) $c['etiqueta'],
                'total'     => (float) $c['total'],
                'n'         => (int) $c['n'],
            ], $d['cobranzas']),
        ];
    }
}
