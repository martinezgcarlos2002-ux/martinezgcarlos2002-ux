<?php
declare(strict_types=1);

/**
 * Correo de recuperación. Estilos en línea y tablas: los clientes de correo ignoran <style> y flexbox.
 * Outlook de escritorio no pinta gradientes ni sombras: ve el naranja sólido de bgcolor/background-color.
 * @var string $nombre
 * @var string $empresa
 * @var string $enlace
 * @var int $minutos
 * @var string $url_logo_adara
 * @var string $url_logo_empresa
 */
$fuente = "font-family:Arial,Helvetica,Roboto,'Segoe UI',sans-serif;";
$texto = $fuente . 'color:#374151;line-height:1.6;';
$url_logo_adara = $url_logo_adara ?? '';
$url_logo_empresa = $url_logo_empresa ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recupera tu contraseña</title>
<style>
@media only screen and (max-width:480px) {
  .marca-celda { display:block !important; width:100% !important; text-align:left !important; padding:6px 0 !important; }
  .marca-sep { display:none !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;<?= $fuente ?>color:#374151;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 6px 24px rgba(15,23,42,.08);">
      <tr><td style="background:#1A2E5A;background-image:linear-gradient(135deg,#1A2E5A 0%,#24407a 100%);padding:20px 28px;color:#ffffff;<?= $fuente ?>">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td class="marca-celda" valign="middle" style="width:58%;">
              <?php if ($url_logo_adara !== ''): ?>
              <img src="<?= e($url_logo_adara) ?>" alt="ADARA ERP" width="156" style="display:block;width:156px;max-width:100%;height:auto;border:0;border-radius:10px;background:#ffffff;">
              <?php else: ?>
              <div style="font-size:20px;font-weight:bold;letter-spacing:2px;">ADARA ERP</div>
              <?php endif; ?>
            </td>
            <?php if ($url_logo_empresa !== ''): ?>
            <td class="marca-sep" valign="middle" align="center" width="36" style="width:36px;color:#94a3b8;font-size:22px;line-height:1;">|</td>
            <td class="marca-celda" valign="middle" align="right" style="width:38%;">
              <div style="font-size:10px;letter-spacing:1px;text-transform:uppercase;color:#cbd5e1;margin-bottom:6px;">operando para</div>
              <img src="<?= e($url_logo_empresa) ?>" alt="<?= e($empresa) ?>" width="92" style="display:inline-block;width:92px;max-width:100%;height:auto;border:0;border-radius:8px;background:#ffffff;padding:4px;">
            </td>
            <?php endif; ?>
          </tr>
        </table>
        <div style="font-size:13px;color:#cbd5e1;margin-top:12px;"><?= e($empresa) ?></div>
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
