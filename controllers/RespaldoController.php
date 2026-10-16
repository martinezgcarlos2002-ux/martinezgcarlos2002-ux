<?php
declare(strict_types=1);

/** Respaldo SQL nativo (PDO). Módulo solo_admin. */
final class RespaldoController
{
    public const SESION_AUTO = 'auto_descarga_backup';

    private RespaldoSql $respaldo;
    private Auditoria $auditoria;

    public function __construct()
    {
        $this->respaldo = new RespaldoSql(get_db());
        $this->auditoria = new Auditoria(get_db());
    }

    public function index(): void
    {
        $inv = $this->respaldo->inventario();
        $mapaCodigo = null;
        $integridad = [];
        if (is_maestro()) {
            $rutaMapa = ADARA_ROOT . '/MAPA_ADARA.md';
            $mapaCodigo = is_readable($rutaMapa) ? (string) file_get_contents($rutaMapa) : '';
            $nucleo = [
                'index.php'            => ADARA_ROOT . '/index.php',
                'config/bootstrap.php' => ADARA_ROOT . '/config/bootstrap.php',
                'security/rbac.php'    => ADARA_ROOT . '/security/rbac.php',
                'MAPA_ADARA.md'        => $rutaMapa,
            ];
            foreach ($nucleo as $nombre => $ruta) {
                $integridad[] = [
                    'archivo' => $nombre,
                    'sha256'  => is_readable($ruta) ? hash_file('sha256', $ruta) : 'no encontrado',
                ];
            }
        }
        view('respaldo', [
            'motor'         => $inv['motor'],
            'tablas'        => $inv['tablas'],
            'vistas'        => $inv['vistas'],
            'ultimo'        => $this->auditoria->ultimoRespaldo(),
            'descargadoHoy' => $this->auditoria->respaldoDescargadoHoy(),
            'ahora'         => date('Y-m-d H:i'),
            'mapaCodigo'    => $mapaCodigo,
            'integridad'    => $integridad,
            'phpVersion'    => PHP_VERSION,
            'pdoOk'         => extension_loaded('pdo_mysql'),
        ]);
    }

    /** Descarga MAPA_ADARA.md. Exclusivo del rol maestro. */
    public function descargarMapaCodigo(): void
    {
        $user = current_user();
        if (!is_maestro($user)) {
            audit_log('seguridad', 'acceso_denegado', ['p' => 'respaldo_mapa_codigo']);
            render_forbidden();
            exit;
        }

        $ruta = ADARA_ROOT . '/MAPA_ADARA.md';
        if (!is_readable($ruta)) {
            http_response_code(404);
            exit('MAPA_ADARA.md no encontrado.');
        }

        $bytes = (int) filesize($ruta);
        audit_log('respaldo', 'descargar_mapa_codigo', [
            'archivo' => 'MAPA_ADARA.md',
            'bytes'   => $bytes,
            'sha256'  => hash_file('sha256', $ruta),
        ], 'MAPA_ADARA.md', null, $user['id']);

        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="MAPA_ADARA.md"');
        header('Content-Length: ' . $bytes);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        readfile($ruta);
    }

    /**
     * Descarga el volcado completo en streaming. POST + CSRF (lo valida index.php): una descarga
     * pesada no debe poder dispararse con un enlace o una imagen desde otro sitio.
     * La auditoría se registra al terminar, con el tamaño y el SHA-256 del archivo entregado.
     */
    public function descargarSql(): void
    {
        $user = current_user();
        $automatica = ($_POST['origen'] ?? '') === 'auto';
        unset($_SESSION[self::SESION_AUTO]);
        // Libera el bloqueo de sesión: el resto de pestañas sigue funcionando mientras se genera el archivo.
        session_write_close();

        set_time_limit(0);
        ignore_user_abort(false);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $archivo = sprintf('adara_respaldo_%s.sql', date('Y-m-d_His'));
        header('Content-Type: application/sql; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');

        $hash = hash_init('sha256');
        $bytes = 0;
        $emitir = static function (string $sql) use ($hash, &$bytes): void {
            hash_update($hash, $sql);
            $bytes += strlen($sql);
            echo $sql;
            flush();
        };

        $resumen = $this->respaldo->generar($emitir);

        if (connection_aborted()) {
            return;
        }
        audit_log('respaldo', Auditoria::ACCION_RESPALDO, $resumen + [
            'archivo'    => $archivo,
            'bytes'      => $bytes,
            'sha256'     => hash_final($hash),
            'automatica' => $automatica,
        ], null, null, $user['id']);
    }

    /**
     * Hook de login: marca la sesión para que el layout dispare la descarga una sola vez.
     * Solo administradores, solo en PC (no en teléfonos) y solo si hoy nadie descargó el respaldo.
     */
    public static function marcarAutoDescarga(array $user): void
    {
        if (!is_admin($user) || self::esMovil($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            return;
        }
        if (!(new Auditoria(get_db()))->respaldoDescargadoHoy()) {
            $_SESSION[self::SESION_AUTO] = true;
        }
    }

    /** Lee y limpia la marca: el layout la consume en la primera página que renderiza. */
    public static function consumirAutoDescarga(): bool
    {
        $pendiente = !empty($_SESSION[self::SESION_AUTO]);
        unset($_SESSION[self::SESION_AUTO]);
        return $pendiente;
    }

    private static function esMovil(string $userAgent): bool
    {
        return (bool) preg_match('/Mobi|Android|iPhone|iPad|iPod|Windows Phone/i', $userAgent);
    }
}
