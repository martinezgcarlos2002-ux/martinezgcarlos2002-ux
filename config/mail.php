<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP de correos transaccionales. Lo carga solo EmailService (después de PHPMailer).
 * Producción inyecta host, puerto, usuario, clave y remitente con SetEnv en el
 * .htaccess de la raíz (las mismas variables que ya usa la base de datos).
 * En local, config/mail.local.php (ignorado por git) pisa estos valores.
 * Este archivo no lleva secretos.
 */
$env = static function (string $nombre, string $defecto): string {
    $valor = getenv($nombre);
    if ($valor === false || $valor === '') {
        return $defecto;
    }
    return $valor;
};

$puerto = (int) $env('ADARA_MAIL_PORT', '465');
if ($puerto < 1) {
    $puerto = 465;
}

$cifrado = strtolower($env('ADARA_MAIL_ENCRYPTION', $puerto === 587 ? 'tls' : 'ssl'));
$encryption = match ($cifrado) {
    'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
    'none' => '',
    default => PHPMailer::ENCRYPTION_SMTPS,
};

$config = [
    'host'       => $env('ADARA_MAIL_HOST', 'smtp.hostinger.com'),
    'port'       => $puerto,
    'username'   => $env('ADARA_MAIL_USER', 'notificaciones@adarasystem.online'),
    'password'   => $env('ADARA_MAIL_PASS', 'CAMBIAR_POR_CLAVE_SMTP'),
    'encryption'  => $encryption,
    'from_email' => $env('ADARA_MAIL_FROM', 'notificaciones@adarasystem.online'),
    'from_name'  => $env('ADARA_MAIL_FROM_NAME', 'ADARA ERP'),
    // Base de los enlaces de los correos. Nunca se toma del Host de la petición (lo controla el cliente).
    'app_url'    => $env('ADARA_APP_URL', 'http://localhost/ADARA/'),
];

$local = __DIR__ . '/mail.local.php';
if (is_file($local)) {
    $extra = require $local;
    if (is_array($extra)) {
        $config = array_merge($config, $extra);
    }
}

return $config;
