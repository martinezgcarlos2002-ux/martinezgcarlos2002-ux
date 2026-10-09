<?php
declare(strict_types=1);

final class AuthController
{
    /**
     * Hash bcrypt de una cadena aleatoria descartada. Se verifica contra él cuando el correo no existe,
     * para que la respuesta tarde lo mismo y no revele qué correos están registrados.
     */
    private const DUMMY_HASH = '$2y$12$n9V.gOm8/nERig7oLaOxoOQssivCZejL/HuJNedJFTGoaSuDIKIhS';

    private const MSG_CREDENCIALES = 'Credenciales incorrectas.';

    private Usuario $usuarios;
    private LoginIntento $intentos;

    public function __construct()
    {
        $this->usuarios = new Usuario(get_db());
        $this->intentos = new LoginIntento(get_db());
    }

    public function showLogin(): void
    {
        if (current_user() !== null) {
            redirect('dashboard');
        }
        $email = $_SESSION['_login_email'] ?? '';
        unset($_SESSION['_login_email']);
        view('login', ['email' => is_string($email) ? $email : '']);
    }

    public function login(): void
    {
        $email = mb_strtolower(post_string('email', 100));
        $ip = client_ip();

        if (!csrf_validate()) {
            audit_log('auth', 'login_fallido', ['email' => $email, 'razon' => 'csrf']);
            csrf_rotate();
            $this->fail($email, 'El formulario expiró o no es válido. Inténtalo de nuevo.');
        }

        $password = $_POST['password'] ?? '';
        if (!is_string($password) || $password === '' || strlen($password) > PASSWORD_MAX_BYTES
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $this->intentos->registrar($email, $ip, false);
            audit_log('auth', 'login_fallido', ['email' => $email, 'razon' => 'formato']);
            $this->fail($email, $email === '' || $password === '' ? 'Completa todos los campos.' : self::MSG_CREDENCIALES);
        }

        if ($this->intentos->bloqueado($email, $ip)) {
            audit_log('auth', 'login_bloqueado', ['email' => $email]);
            $this->fail($email, sprintf(
                'Demasiados intentos fallidos. Espera %d minutos antes de volver a intentarlo.',
                LOGIN_VENTANA_MINUTOS
            ));
        }

        $user = $this->usuarios->findForLogin($email);
        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);

        if (!$valid || $user === null || (int) $user['activo'] !== 1) {
            $razon = match (true) {
                $user === null => 'no_existe',
                !$valid        => 'password',
                default        => 'inactivo',
            };
            $this->intentos->registrar($email, $ip, false);
            audit_log(
                'auth',
                'login_fallido',
                ['email' => $email, 'razon' => $razon],
                'usuarios',
                $user !== null ? (int) $user['id'] : null
            );
            $this->fail($email, self::MSG_CREDENCIALES);
        }

        $userId = (int) $user['id'];
        if (password_needs_rehash($user['password_hash'], PASSWORD_ALGO, PASSWORD_OPTIONS)) {
            $this->usuarios->updatePasswordHash($userId, password_hash($password, PASSWORD_ALGO, PASSWORD_OPTIONS));
        }
        unset($user, $password);

        $this->intentos->registrar($email, $ip, true);
        $this->usuarios->touchLastLogin($userId);
        auth_login($userId);
        audit_log('auth', 'login_exitoso', null, 'usuarios', $userId, $userId);

        $sesion = $this->usuarios->findActiveById($userId);
        if ($sesion !== null) {
            RespaldoController::marcarAutoDescarga($sesion);
        }

        redirect('dashboard');
    }

    public function logout(): void
    {
        $user = current_user();
        if ($user !== null) {
            audit_log('auth', 'logout', null, 'usuarios', $user['id'], $user['id']);
        }
        auth_logout();
        flash('info', 'Sesión cerrada correctamente.');
        redirect('login');
    }

    private function fail(string $email, string $message): never
    {
        $_SESSION['_login_email'] = $email;
        flash('error', $message);
        redirect('login');
    }
}
