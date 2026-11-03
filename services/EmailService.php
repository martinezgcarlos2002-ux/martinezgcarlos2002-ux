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
            $mail->Body = $cuerpoHtml;
            $mail->AltBody = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $cuerpoHtml) ?? ''), ENT_QUOTES, 'UTF-8'));

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('[ADARA][mail] Envío fallido: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
