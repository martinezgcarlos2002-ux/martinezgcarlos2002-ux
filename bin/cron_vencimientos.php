<?php
declare(strict_types=1);

/**
 * Avisos de vencimiento de la suscripción SaaS.
 *
 * Revisa el último ciclo de cada empresa. Si faltan 5, 3 o 1 días para el
 * día de corte, deja un aviso en la campana y envía un correo al administrador.
 * Un mismo aviso no se repite el mismo día. Las empresas con el servicio
 * suspendido no reciben este recordatorio.
 *
 * Programador de tareas de Windows, una vez al día:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\ADARA\bin\cron_vencimientos.php adara_master
 *   C:\xampp\php\php.exe C:\xampp\htdocs\ADARA\bin\cron_vencimientos.php u980302731_master
 *
 * El argumento es la base maestra. Sin argumento usa ADARA_MASTER_DB_NAME.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = $argv[1] ?? '';
if ($base !== '' && preg_match('/^[A-Za-z0-9_]+$/', $base) !== 1) {
    fwrite(STDERR, "El nombre de la base maestra no es válido.\n");
    exit(1);
}
if ($base !== '') {
    putenv('ADARA_MASTER_DB_NAME=' . $base);
    $_ENV['ADARA_MASTER_DB_NAME'] = $base;
}

require dirname(__DIR__) . '/config/bootstrap.php';

$saas = new SaasComercial(Database::master());
$correo = null;
try {
    $correo = new EmailService();
} catch (Throwable $e) {
    fwrite(STDERR, "Correo no configurado: {$e->getMessage()}\n");
}

$hoy = (new DateTimeImmutable('today'))->format('Y-m-d');
$insertadas = 0;
$enviados = 0;

foreach ($saas->cortesVigentes() as $fila) {
    if (($fila['estado_suscripcion'] ?? '') === 'Suspendido') {
        continue;
    }
    $dias = SaasComercial::diasRestantes((string) $fila['dia_corte']);
    if (!in_array($dias, [5, 3, 1], true)) {
        continue;
    }
    $cuando = date('d/m/Y', strtotime((string) $fila['dia_corte']));
    $mensaje = 'Tu suscripción de ADARA vence en ' . $dias . ' '
        . ($dias === 1 ? 'día' : 'días') . ' (' . $cuando . ').';
    if (!$saas->notificarCorte((int) $fila['id'], $mensaje, $hoy)) {
        continue;
    }
    $insertadas++;
    $destino = trim((string) ($fila['correo_admin_cliente'] ?? ''));
    $html = '<p>' . htmlspecialchars($mensaje, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '<p>Si ya pagaste, soporte registrará el abono y este aviso deja de aplicar.</p>';
    if ($correo !== null && $destino !== '' && $correo->enviar(
        $destino,
        'ADARA: tu suscripción vence en ' . $dias . ($dias === 1 ? ' día' : ' días'),
        $html
    )) {
        $enviados++;
    }
}

$nombre = $base !== '' ? $base : (getenv('ADARA_MASTER_DB_NAME') ?: 'maestra');
fwrite(STDOUT, "Base {$nombre}. Avisos nuevos: {$insertadas}. Correos enviados: {$enviados}.\n");
