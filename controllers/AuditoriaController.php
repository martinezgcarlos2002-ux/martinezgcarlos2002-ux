<?php
declare(strict_types=1);

/** Consulta de la bitácora. Solo lectura: la tabla auditoria es inmutable por triggers. Módulo solo_admin. */
final class AuditoriaController
{
    private const MAX_DIAS_FILTRO = 366;

    private Auditoria $auditoria;

    public function __construct()
    {
        $this->auditoria = new Auditoria(get_db());
    }

    public function index(): void
    {
        $filtros = $this->filtros();
        $pagina = parse_int_range($_GET['pagina'] ?? 1, 1, 100000) ?? 1;
        $resultado = $this->auditoria->listar($filtros, $pagina);

        view('auditoria', [
            'filtros'        => $filtros,
            'registros'      => $resultado['filas'],
            'total'          => $resultado['total'],
            'totalGeneral'   => $this->auditoria->contarTodo(),
            'pagina'         => $resultado['pagina'],
            'paginas'        => $resultado['paginas'],
            'porPagina'      => Auditoria::POR_PAGINA,
            'modulos'        => $this->auditoria->modulosRegistrados(),
            'ultimoRespaldo' => $this->obtenerUltimoRespaldo(),
        ]);
    }

    /** @return array{usuario:string, fecha:string, hora:string}|null */
    public function obtenerUltimoRespaldo(): ?array
    {
        $ultimo = $this->auditoria->ultimoRespaldo();
        if ($ultimo === null) {
            return null;
        }
        $momento = new DateTimeImmutable($ultimo['fecha']);
        return [
            'usuario' => $ultimo['usuario'] ?? 'Sistema',
            'fecha'   => $momento->format('d/m/Y'),
            'hora'    => $momento->format('H:i'),
        ];
    }

    /** @return array{q:string, modulo:?string, desde:?string, hasta:?string} */
    private function filtros(): array
    {
        $q = $_GET['q'] ?? '';
        $modulo = $_GET['modulo'] ?? '';
        $desde = is_string($_GET['desde'] ?? null) ? parse_date($_GET['desde']) : null;
        $hasta = is_string($_GET['hasta'] ?? null) ? parse_date($_GET['hasta']) : null;
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        if ($desde !== null && $hasta !== null) {
            $limite = (new DateTimeImmutable($hasta))->modify('-' . self::MAX_DIAS_FILTRO . ' days')->format('Y-m-d');
            $desde = max($desde, $limite);
        }

        return [
            'q'      => is_string($q) ? mb_substr(trim($q), 0, 80) : '',
            'modulo' => is_string($modulo) && preg_match('/^[a-z_]{1,40}$/', $modulo) ? $modulo : null,
            'desde'  => $desde,
            'hasta'  => $hasta,
        ];
    }
}
