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
        view('respaldo', [
            'motor'         => $inv['motor'],
            'tablas'        => $inv['tablas'],
            'vistas'        => $inv['vistas'],
            'ultimo'        => $this->auditoria->ultimoRespaldo(),
            'descargadoHoy' => $this->auditoria->respaldoDescargadoHoy(),
            'ahora'         => date('Y-m-d H:i'),
        ]);
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
        if ($user['rol'] !== 'admin' || self::esMovil($_SERVER['HTTP_USER_AGENT'] ?? '')) {
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
