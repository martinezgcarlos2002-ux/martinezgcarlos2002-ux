<?php
declare(strict_types=1);

/**
 * Login.
 *
 * Una empresa: valida contra usuarios de ADARA_DB_NAME (comportamiento original).
 * Multiempresa (ADARA_MASTER_DB_NAME definida), enrutado desde adara_master:
 *   1. Correo de super_admins           → Panel Maestro SaaS ($_SESSION['rol_maestro'] = true).
 *   2. Empresa por Host (subdominio o dominio propio). Si la dirección no elige empresa,
 *      vale el correo_admin_cliente y, si no, cualquier usuario activo de alguna empresa.
 *   3. PDO se reconecta a nombre_db_fisica y la contraseña se valida contra usuarios de esa base.
 *   4. Con credenciales correctas y licencia inactiva, el acceso se bloquea con aviso explícito.
 *      Con credenciales incorrectas el mensaje es siempre el genérico (no revela empresas ni licencias).
 * Siempre se ejecuta un único password_verify (hash real o DUMMY_HASH): el tiempo de respuesta no
 * distingue super admin, usuario de empresa ni correo inexistente.
 */
final class AuthController
{
    /**
     * Hash bcrypt de una cadena aleatoria descartada. Se verifica contra él cuando el correo no existe,
     * para que la respuesta tarde lo mismo y no revele qué correos están registrados.
     */
    private const DUMMY_HASH = '$2y$12$n9V.gOm8/nERig7oLaOxoOQssivCZejL/HuJNedJFTGoaSuDIKIhS';

    private const MSG_CREDENCIALES = 'Credenciales incorrectas.';

    private ?LoginIntento $intentos = null;

    public function showLogin(): void
    {
        self::sinCache();
        if (super_admin_actual() !== null) {
            redirect('master');
        }
        if (current_user() !== null) {
            redirect('dashboard');
        }
        if (Database::multiempresa() && Database::empresaActiva() === null) {
            // Marca de la empresa en su propio subdominio; en el dominio principal, la marca ADARA.
            $cliente = (new Saas(Database::master()))->clienteDePeticionPublica();
            if ($cliente !== null && $cliente['estado_licencia'] === 'activa') {
                Database::usarEmpresa((string) $cliente['nombre_db_fisica']);
            }
        }
        $email = $_SESSION['_login_email'] ?? '';
        unset($_SESSION['_login_email']);
        view('login', ['email' => is_string($email) ? $email : '']);
    }

    public function login(): void
    {
        self::sinCache();
        $email = mb_strtolower(post_string('email', 100));
        $ip = client_ip();

        if (!csrf_validate()) {
            $this->auditar('login_fallido', ['email' => $email, 'razon' => 'csrf']);
            csrf_rotate();
            $this->fail($email, 'El formulario expiró o no es válido. Inténtalo de nuevo.');
        }

        $password = $_POST['password'] ?? '';
        if (!is_string($password) || $password === '' || strlen($password) > PASSWORD_MAX_BYTES
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $this->intentos()->registrar($email, $ip, false);
            $this->auditar('login_fallido', ['email' => $email, 'razon' => 'formato']);
            $this->fail($email, $email === '' || $password === '' ? 'Completa todos los campos.' : self::MSG_CREDENCIALES);
        }

        if ($this->intentos()->bloqueado($email, $ip)) {
            $this->auditar('login_bloqueado', ['email' => $email]);
            $this->fail($email, sprintf(
                'Demasiados intentos fallidos. Espera %d minutos antes de volver a intentarlo.',
                LOGIN_VENTANA_MINUTOS
            ));
        }

        if (Database::multiempresa()) {
            $this->loginMultiempresa($email, $password, $ip);
        }

        $usuarios = new Usuario(get_db());
        [$user, $consultaMs] = self::medir(static fn () => $usuarios->findForLogin($email));
        [$valid, $verifyMs] = self::medir(static fn () => password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH));
        self::logTiempos('empresa', $consultaMs, $verifyMs, $user !== null);

        if (!$valid || $user === null || (int) $user['activo'] !== 1) {
            $razon = match (true) {
                $user === null => 'no_existe',
                !$valid        => 'password',
                default        => 'inactivo',
            };
            $this->intentos()->registrar($email, $ip, false);
            audit_log(
                'auth',
                'login_fallido',
                ['email' => $email, 'razon' => $razon],
                'usuarios',
                $user !== null ? (int) $user['id'] : null
            );
            $this->fail($email, self::MSG_CREDENCIALES);
        }

        $this->iniciarSesionEmpresa($usuarios, $user, $password, $email, $ip, null);
    }

    public function logout(): void
    {
        self::sinCache();
        $user = current_user();
        if ($user !== null) {
            audit_log('auth', 'logout', null, 'usuarios', $user['id'], $user['id']);
        }
        auth_logout();
        flash('info', 'Sesión cerrada correctamente.');
        redirect('login');
    }

    private function loginMultiempresa(string $email, string $password, string $ip): never
    {
        $saas = new Saas(Database::master());
        $admin = null;
        $cliente = null;
        $usuarios = null;
        $user = null;

        [$hallazgo, $consultaMs] = self::medir(function () use ($saas, $email, &$admin, &$cliente, &$usuarios, &$user): void {
            $admin = $saas->superAdminPorCorreo($email);
            if ($admin !== null) {
                return;
            }
            $porHost = $saas->clientePorHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if ($porHost !== null) {
                // La dirección ya eligió la empresa: no se busca el correo en las demás.
                [$cliente, $usuarios, $user] = $this->usuarioEnEmpresa($porHost, $email);
                return;
            }
            $porCorreo = $saas->clientePorCorreoAdmin($email);
            if ($porCorreo !== null) {
                [$cliente, $usuarios, $user] = $this->usuarioEnEmpresa($porCorreo, $email);
            }
            // Empleados y otros admins no están en correo_admin_cliente. Se buscan en cada empresa.
            if ($user === null) {
                [$cliente, $usuarios, $user] = $this->usuarioEntreEmpresas($saas, $email);
            }
        });
        unset($hallazgo);

        [$valid, $verifyMs] = self::medir(static fn () => password_verify($password, $admin['password_hash'] ?? $user['password_hash'] ?? self::DUMMY_HASH));
        self::logTiempos($admin !== null ? 'maestro' : 'multiempresa', $consultaMs, $verifyMs, $admin !== null || $user !== null);

        if ($admin !== null) {
            if (!$valid || (int) $admin['activo'] !== 1) {
                $this->intentos()->registrar($email, $ip, false);
                $saas->auditar(null, 'login_fallido', null, ['email' => $email, 'razon' => $valid ? 'super_admin_inactivo' : 'password_super_admin']);
                $this->fail($email, self::MSG_CREDENCIALES);
            }
            $adminId = (int) $admin['id'];
            if (password_needs_rehash($admin['password_hash'], PASSWORD_ALGO, PASSWORD_OPTIONS)) {
                $saas->actualizarHashSuperAdmin($adminId, password_hash($password, PASSWORD_ALGO, PASSWORD_OPTIONS));
            }
            $this->intentos()->registrar($email, $ip, true);
            $saas->marcarAccesoSuperAdmin($adminId);
            sesion_nueva();
            $_SESSION['rol_maestro'] = true;
            $_SESSION['super_admin_id'] = $adminId;
            $_SESSION['auth_at'] = time();
            unset($_SESSION['uid'], $_SESSION['tenant_id'], $_SESSION['db_activa'], $_SESSION['impersonado_por']);
            $saas->auditar($adminId, 'login_exitoso', null, null);
            // Nunca al dashboard de una empresa: el Panel Maestro es la única pantalla de esta sesión.
            redirect('master');
        }

        if (!$valid || $cliente === null || $user === null || (int) $user['activo'] !== 1) {
            $this->intentos()->registrar($email, $ip, false);
            $saas->auditar(null, 'login_fallido', $cliente !== null ? (int) $cliente['id'] : null, [
                'email' => $email,
                'razon' => match (true) {
                    $cliente === null => 'sin_empresa',
                    $user === null    => 'no_existe',
                    !$valid           => 'password',
                    default           => 'inactivo',
                },
            ]);
            $this->fail($email, self::MSG_CREDENCIALES);
        }

        if ($cliente['estado_licencia'] !== 'activa') {
            $saas->auditar(null, 'login_licencia_inactiva', (int) $cliente['id'], ['email' => $email]);
            $this->fail($email, MSG_LICENCIA_SUSPENDIDA);
        }

        $this->iniciarSesionEmpresa($usuarios, $user, $password, $email, $ip, $cliente);
    }

    /**
     * @param array{id:int|string, password_hash:string, activo:int|string} $user
     * @param array<string, mixed>|null $cliente null en modo de una sola empresa
     */
    private function iniciarSesionEmpresa(Usuario $usuarios, array $user, string $password, string $email, string $ip, ?array $cliente): never
    {
        $userId = (int) $user['id'];
        if (password_needs_rehash($user['password_hash'], PASSWORD_ALGO, PASSWORD_OPTIONS)) {
            $usuarios->updatePasswordHash($userId, password_hash($password, PASSWORD_ALGO, PASSWORD_OPTIONS));
        }

        $this->intentos()->registrar($email, $ip, true);
        $usuarios->touchLastLogin($userId);
        if ($cliente !== null) {
            sesion_nueva();
        }
        auth_login($userId);
        if ($cliente !== null) {
            $_SESSION['tenant_id'] = (int) $cliente['id'];
            $_SESSION['db_activa'] = (string) $cliente['nombre_db_fisica'];
        }
        audit_log('auth', 'login_exitoso', null, 'usuarios', $userId, $userId);

        $sesion = $usuarios->findActiveById($userId);
        if ($sesion !== null) {
            RespaldoController::marcarAutoDescarga($sesion);
        }

        redirect('dashboard');
    }

    /**
     * @param array<string, mixed> $cliente
     * @return array{0:array<string, mixed>, 1:?Usuario, 2:?array<string, mixed>}
     */
    private function usuarioEnEmpresa(array $cliente, string $email): array
    {
        try {
            Database::usarEmpresa((string) $cliente['nombre_db_fisica']);
            $usuarios = new Usuario(get_db());
            return [$cliente, $usuarios, $usuarios->findForLogin($email)];
        } catch (DatabaseException $e) {
            error_log('[ADARA][login] empresa ' . ($cliente['id'] ?? '') . ': ' . $e->getMessage());
            return [$cliente, null, null];
        }
    }

    /**
     * @return array{0:?array<string, mixed>, 1:?Usuario, 2:?array<string, mixed>}
     */
    private function usuarioEntreEmpresas(Saas $saas, string $email): array
    {
        $hallados = [];
        foreach ($saas->empresasParaLogin() as $empresa) {
            [, $usuarios, $user] = $this->usuarioEnEmpresa($empresa, $email);
            if ($user !== null && $usuarios !== null) {
                $hallados[] = [$empresa, $usuarios, $user];
            }
        }
        if (count($hallados) === 1) {
            return $hallados[0];
        }
        // En dos empresas a la vez no hay forma de saber cuál pidió. Entra por su dirección.
        return [null, null, null];
    }

    /** Multiempresa: los intentos se cuentan en adara_master (todavía no se sabe a qué empresa va el login). */
    private function intentos(): LoginIntento
    {
        return $this->intentos ??= new LoginIntento(Database::multiempresa() ? Database::master() : get_db());
    }

    /** @param array<string, mixed> $detalle */
    private function auditar(string $accion, array $detalle): void
    {
        if (Database::multiempresa() && Database::empresaActiva() === null) {
            (new Saas(Database::master()))->auditar(null, $accion, null, $detalle);
            return;
        }
        audit_log('auth', $accion, $detalle);
    }

    /**
     * @template T
     * @param callable(): T $trabajo
     * @return array{0: T, 1: float} resultado y milisegundos
     */
    private static function medir(callable $trabajo): array
    {
        $inicio = hrtime(true);
        $resultado = $trabajo();
        return [$resultado, (hrtime(true) - $inicio) / 1_000_000];
    }

    /** Cloudflare no guarda ni reta la respuesta del login a partir de lo que cachea el origen. */
    private static function sinCache(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('CDN-Cache-Control: no-store');
        header('Pragma: no-cache');
    }

    /** No registra correo ni contraseña: solo dónde se fue el tiempo del POST. */
    private static function logTiempos(string $via, float $consultaMs, float $verifyMs, bool $encontrado): void
    {
        error_log(sprintf(
            '[ADARA][login-tiempo] via=%s consulta_ms=%.1f verify_ms=%.1f encontrado=%d',
            $via,
            $consultaMs,
            $verifyMs,
            $encontrado ? 1 : 0
        ));
    }

    private function fail(string $email, string $message): never
    {
        $_SESSION['_login_email'] = $email;
        flash('error', $message);
        redirect('login');
    }
}
