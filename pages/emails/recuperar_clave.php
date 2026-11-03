<?php
declare(strict_types=1);

/**
 * Correo de recuperación. Estilos en línea y tablas: los clientes de correo ignoran <style> y flexbox.
 * Outlook de escritorio no pinta gradientes ni sombras: ve el naranja sólido de bgcolor/background-color.
 * @var string $nombre
 * @var string $empresa
 * @var string $enlace
 * @var int $minutos
 */
$fuente = "font-family:Arial,Helvetica,Roboto,'Segoe UI',sans-serif;";
$texto = $fuente . 'color:#374151;line-height:1.6;';
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recupera tu contraseña</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;<?= $fuente ?>color:#374151;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 6px 24px rgba(15,23,42,.08);">
      <tr><td style="background:#1A2E5A;background-image:linear-gradient(135deg,#1A2E5A 0%,#24407a 100%);padding:24px 32px;color:#ffffff;<?= $fuente ?>">
        <div style="font-size:20px;font-weight:bold;letter-spacing:2px;">ADARA ERP</div>
        <div style="font-size:13px;color:#cbd5e1;margin-top:4px;"><?= e($empresa) ?></div>
      </td></tr>
      <tr><td style="height:4px;line-height:4px;font-size:0;background:#F97316;background-image:linear-gradient(90deg,#FB923C 0%,#F97316 50%,#EA580C 100%);">&nbsp;</td></tr>
      <tr><td style="padding:32px;">
        <p style="margin:0 0 16px;font-size:17px;<?= $fuente ?>color:#1A2E5A;line-height:1.6;">Hola, <strong><?= e($nombre) ?></strong>:</p>
        <p style="margin:0 0 8px;font-size:15px;<?= $texto ?>">Recibimos una solicitud para cambiar la contraseña de tu cuenta. Pulsa el botón para crear una nueva:</p>
        <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:28px auto;">
          <tr><td align="center" bgcolor="#F97316" style="border-radius:50px;background:#F97316;background-image:linear-gradient(135deg,#FB923C 0%,#F97316 55%,#EA580C 100%);box-shadow:0 6px 16px rgba(249,115,22,.35);">
            <a href="<?= e($enlace) ?>" target="_blank" style="display:inline-block;padding:14px 28px;border-radius:50px;<?= $fuente ?>font-size:15px;font-weight:bold;letter-spacing:0.5px;color:#ffffff;text-decoration:none;">Crear nueva contraseña</a>
          </td></tr>
        </table>
        <p style="margin:0 0 16px;font-size:14px;<?= $texto ?>">El enlace vence en <strong><?= (int) $minutos ?> minutos</strong> y solo puede usarse una vez.</p>
        <p style="margin:0 0 6px;font-size:13px;<?= $texto ?>color:#6b7280;">Si el botón no funciona, copia esta dirección en tu navegador:</p>
        <p style="margin:0 0 20px;font-size:12px;<?= $fuente ?>line-height:1.6;word-break:break-all;"><a href="<?= e($enlace) ?>" style="color:#1A2E5A;"><?= e($enlace) ?></a></p>
        <p style="margin:0;padding-top:16px;border-top:1px solid #e5e7eb;font-size:13px;<?= $texto ?>color:#6b7280;">Si no pediste este cambio, ignora este correo: tu contraseña actual sigue funcionando.</p>
      </td></tr>
      <tr><td style="background:#f8fafc;padding:16px 32px;font-size:12px;<?= $fuente ?>color:#94a3b8;line-height:1.6;text-align:center;">
        Mensaje automático de ADARA ERP · No respondas a este correo.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
