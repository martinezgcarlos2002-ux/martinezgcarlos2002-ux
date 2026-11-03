<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP de correos transaccionales. Lo carga solo EmailService (después de PHPMailer).
 * La clave real va en la variable ADARA_MAIL_PASS o en config/mail.local.php
 * (ignorado por git). Este archivo no lleva secretos.
 */
$config = [
    'host'       => 'smtp.hostinger.com',
    'port'       => 465,
    'username'   => 'notificaciones@adarasystem.online',
    'password'   => getenv('ADARA_MAIL_PASS') ?: 'CAMBIAR_POR_CLAVE_SMTP',
    'encryption' => PHPMailer::ENCRYPTION_SMTPS,
    'from_email' => 'notificaciones@adarasystem.online',
    'from_name'  => 'ADARA ERP',
    // Base de los enlaces de los correos. Nunca se toma del Host de la petición (lo controla el cliente).
    'app_url'    => getenv('ADARA_APP_URL') ?: 'http://localhost/ADARA/',
];

$local = __DIR__ . '/mail.local.php';
if (is_file($local)) {
    $extra = require $local;
    if (is_array($extra)) {
        $config = array_merge($config, $extra);
    }
}

return $config;
