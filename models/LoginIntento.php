<?php
declare(strict_types=1);

final class LoginIntento
{
    public function __construct(private PDO $db)
    {
    }

    public function registrar(string $email, string $ip, bool $exito): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO login_intentos (email, ip, exito) VALUES (:email, :ip, :exito)'
        );
        $stmt->execute(['email' => mb_substr($email, 0, 100), 'ip' => $ip, 'exito' => (int) $exito]);
    }

    public function bloqueado(string $email, string $ip): bool
    {
        $stmt = $this->db->prepare(
            'SELECT
                 COALESCE(SUM(email = :email), 0) AS por_email_ip,
                 COUNT(*)                          AS por_ip
             FROM login_intentos
             WHERE ip = :ip
               AND exito = 0
               AND creado_en >= NOW() - INTERVAL :minutos MINUTE'
        );
        $stmt->execute(['email' => $email, 'ip' => $ip, 'minutos' => LOGIN_VENTANA_MINUTOS]);
        $row = $stmt->fetch();

        return (int) $row['por_email_ip'] >= LOGIN_MAX_FALLOS_EMAIL_IP
            || (int) $row['por_ip'] >= LOGIN_MAX_FALLOS_IP;
    }
}
