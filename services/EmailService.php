<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once ADARA_ROOT . '/lib/PHPMailer/Exception.php';
require_once ADARA_ROOT . '/lib/PHPMailer/PHPMailer.php';
require_once ADARA_ROOT . '/lib/PHPMailer/SMTP.php';

/** Correos transaccionales por SMTP (config/mail.php). Los fallos van al log, nunca al usuario. */
final class EmailService
{
    private const CLAVE_PENDIENTE = 'CAMBIAR_POR_CLAVE_SMTP';
    private const CLAVES = ['host', 'port', 'username', 'password', 'encryption', 'from_email', 'from_name', 'app_url'];

    /** @var array{host:string, port:int, username:string, password:string, encryption:string, from_email:string, from_name:string, app_url:string} */
    private array $config;

    public function __construct()
    {
        $this->config = self::config();
    }

    /** @return array{host:string, port:int, username:string, password:string, encryption:string, from_email:string, from_name:string, app_url:string} */
    public static function config(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }
        $cargado = require ADARA_ROOT . '/config/mail.php';
        if (!is_array($cargado) || array_diff(self::CLAVES, array_keys($cargado)) !== []) {
            throw new LogicException('config/mail.php está incompleto.');
        }
        return $config = $cargado;
    }

    /** URL absoluta de una pantalla para usar dentro de un correo. */
    public static function enlace(string $page, array $query = []): string
    {
        return rtrim(self::config()['app_url'], '/') . '/?' . http_build_query(['p' => $page] + $query);
    }

    /**
     * URL absoluta de un archivo de assets/ para un correo.
     * Rechaza rutas con .. o absolutas: el correo solo puede citar archivos públicos de la marca.
     */
    public static function urlAsset(string $relativo): string
    {
        $relativo = str_replace('\\', '/', $relativo);
        if ($relativo === '' || str_contains($relativo, '..') || str_starts_with($relativo, '/')) {
            return '';
        }
        $partes = array_map('rawurlencode', explode('/', $relativo));
        return rtrim(self::config()['app_url'], '/') . '/assets/' . implode('/', $partes);
    }

    /**
     * Logo que la empresa subió en Configuración (assets/img/marca/).
     * El logo por defecto no cuenta: sin carga, el correo no reserva ese espacio.
     */
    public static function logoEmpresaSubido(): string
    {
        try {
            $ruta = str_replace('\\', '/', (string) (marca()['ruta_logo'] ?? ''));
        } catch (Throwable) {
            return '';
        }
        if (!str_starts_with($ruta, 'img/marca/') || !is_file(ADARA_ROOT . '/assets/' . $ruta)) {
            return '';
        }
        return self::urlAsset($ruta);
    }

    public function enviar(string $destinatario, string $asunto, string $cuerpoHtml): bool
    {
        if ($this->config['password'] === '' || $this->config['password'] === self::CLAVE_PENDIENTE) {
            error_log('[ADARA][mail] Falta la clave SMTP en config/mail.php (o ADARA_MAIL_PASS).');
            return false;
        }
        if (!PHPMailer::validateAddress($destinatario)) {
            error_log('[ADARA][mail] Destinatario inválido.');
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->Port = (int) $this->config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
            $mail->SMTPSecure = $this->config['encryption'];
            $mail->SMTPAutoTLS = false;
            $mail->Timeout = 15;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;

            $mail->setFrom($this->config['from_email'], $this->config['from_name']);
            $mail->addAddress($destinatario);
            $mail->Subject = str_replace(["\r", "\n"], ' ', $asunto);
            $mail->isHTML(true);
            $mail->Body = $this->incrustarImagenesLocales($mail, $cuerpoHtml);
            $mail->AltBody = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $cuerpoHtml) ?? ''), ENT_QUOTES, 'UTF-8'));

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('[ADARA][mail] Envío fallido: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Las imágenes que apuntan a esta instalación se adjuntan al mensaje (cid:).
     * Así el logo se ve aunque el destinatario no pueda abrir localhost.
     */
    private function incrustarImagenesLocales(PHPMailer $mail, string $html): string
    {
        $base = rtrim($this->config['app_url'], '/');
        $resultado = preg_replace_callback(
            '#src="' . preg_quote($base, '#') . '/assets/([^"?]+)#',
            static function (array $m) use ($mail): string {
                $rel = rawurldecode($m[1]);
                if ($rel === '' || str_contains($rel, '..') || str_contains($rel, '\\')) {
                    return $m[0];
                }
                $path = ADARA_ROOT . '/assets/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (!is_file($path)) {
                    return $m[0];
                }
                $cid = 'logo' . substr(hash('sha256', $path), 0, 12);
                $mail->addEmbeddedImage($path, $cid);
                return 'src="cid:' . $cid;
            },
            $html
        );
        return is_string($resultado) ? $resultado : $html;
    }
}
