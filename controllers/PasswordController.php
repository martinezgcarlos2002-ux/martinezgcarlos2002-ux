<?php
declare(strict_types=1);

/**
 * "Olvidé mi contraseña" de los usuarios de una empresa (tabla usuarios de su base física).
 *
 * - El correo recibe el token en claro; la base solo guarda su SHA-256 (reset_token).
 * - La respuesta a la solicitud es siempre la misma: no revela qué correos existen.
 * - Multiempresa: la empresa sale de la dirección (subdominio o dominio propio). Desde una dirección
 *   compartida (localhost) se busca en las empresas con licencia activa, como en el login.
 *   Los super admins no usan este flujo (sus claves las gestiona el propietario).
 */
final class PasswordController
{
    private const MINUTOS_VIGENCIA = 15;
    private const ESPERA_REENVIO_SEGUNDOS = 60;
    private const PATRON_TOKEN = '/^[a-f0-9]{64}$/';
    private const MSG_ENVIADO = 'Si el correo está registrado, te enviamos un enlace para crear una nueva contraseña. Vence en 15 minutos.';
    private const MSG_ENLACE_INVALIDO = 'El enlace no es válido o ya venció. Solicita uno nuevo.';

    public function solicitarForm(): void
    {
        $this->prepararMarca();
        view('olvide_clave', ['email' => '']);
    }

    public function solicitar(): void
    {
        $email = mb_strtolower(post_string('email', 100));
        $ip = client_ip();
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            http_response_code(422);
            flash('error', 'Escribe un correo válido.');
            $this->prepararMarca();
            view('olvide_clave', ['email' => $email]);
            return;
        }

        $intentos = new LoginIntento(Database::multiempresa() ? Database::master() : get_db());
        $clave = 'reset:' . $email;
        if ($intentos->bloqueado($clave, $ip)) {
            flash('error', sprintf('Demasiadas solicitudes. Espera %d minutos e inténtalo de nuevo.', LOGIN_VENTANA_MINUTOS));
            redirect('olvide_clave');
        }
        $intentos->registrar($clave, $ip, false);

        $destino = $this->ubicarPorEmail($email);
        if ($destino !== null) {
            [$empresa, $usuarios, $user] = $destino;
            $token = bin2hex(random_bytes(32));
            if ($usuarios->guardarResetToken($user['id'], hash('sha256', $token), self::MINUTOS_VIGENCIA, self::ESPERA_REENVIO_SEGUNDOS)) {
                $enviado = (new EmailService())->enviar(
                    $user['email'],
                    'Recupera tu contraseña · ' . $empresa,
                    $this->correoHtml($user['nombre'], $empresa, EmailService::enlace('nueva_clave', ['token' => $token]))
                );
                audit_log('auth', 'clave_reset_solicitado', ['email' => $user['email'], 'correo_enviado' => $enviado], 'usuarios', $user['id'], $user['id']);
            }
        }

        flash('success', self::MSG_ENVIADO);
        redirect('login');
    }

    public function nuevaClaveForm(): void
    {
        $token = $this->tokenRecibido($_GET['token'] ?? null);
        if ($token === null || $this->ubicarPorToken($token) === null) {
            flash('error', self::MSG_ENLACE_INVALIDO);
            redirect('olvide_clave');
        }
        view('nueva_clave', ['token' => $token, 'errores' => []]);
    }

    public function nuevaClave(): void
    {
        $token = $this->tokenRecibido($_POST['token'] ?? null);
        $destino = $token === null ? null : $this->ubicarPorToken($token);
        if ($destino === null) {
            flash('error', self::MSG_ENLACE_INVALIDO);
            redirect('olvide_clave');
        }
        [, $usuarios, $user] = $destino;

        $nueva = is_string($_POST['nueva'] ?? null) ? $_POST['nueva'] : '';
        $confirmacion = is_string($_POST['confirmacion'] ?? null) ? $_POST['confirmacion'] : '';
        $errores = [];
        if (mb_strlen($nueva) < PASSWORD_MIN_LEN || strlen($nueva) > PASSWORD_MAX_BYTES) {
            $errores['nueva'] = sprintf('La contraseña debe tener al menos %d caracteres y como máximo %d bytes.', PASSWORD_MIN_LEN, PASSWORD_MAX_BYTES);
        } elseif (!hash_equals($nueva, $confirmacion)) {
            $errores['confirmacion'] = 'La confirmación no coincide.';
        }
        if ($errores !== []) {
            http_response_code(422);
            view('nueva_clave', ['token' => $token, 'errores' => $errores]);
            return;
        }

        $hash = password_hash($nueva, PASSWORD_ALGO, PASSWORD_OPTIONS);
        if (!$usuarios->restablecerClave($user['id'], hash('sha256', $token), $hash)) {
            flash('error', self::MSG_ENLACE_INVALIDO);
            redirect('olvide_clave');
        }
        audit_log('auth', 'clave_restablecida', ['email' => $user['email']], 'usuarios', $user['id'], $user['id']);

        flash('success', 'Contraseña actualizada. Ya puedes iniciar sesión.');
        redirect('login');
    }

    /**
     * @return array{0:string, 1:Usuario, 2:array{id:int, nombre:string, email:string}}|null
     *         [nombre de la empresa, modelo sobre su base, usuario]
     */
    private function ubicarPorEmail(string $email): ?array
    {
        return $this->ubicar(static fn (Usuario $u): ?array => $u->findActivoPorEmail($email), $email);
    }

    /** @return array{0:string, 1:Usuario, 2:array{id:int, nombre:string, email:string}}|null */
    private function ubicarPorToken(string $token): ?array
    {
        $hash = hash('sha256', $token);
        return $this->ubicar(static fn (Usuario $u): ?array => $u->findPorResetToken($hash), null);
    }

    /**
     * Busca en la base de la empresa que corresponde y deja PDO apuntando a ella (audit_log escribe ahí).
     * Si el usuario aparece en dos empresas, no se elige ninguna: debe entrar por la dirección de la suya.
     *
     * @param callable(Usuario): ?array $buscar
     * @return array{0:string, 1:Usuario, 2:array{id:int, nombre:string, email:string}}|null
     */
    private function ubicar(callable $buscar, ?string $email): ?array
    {
        if (!Database::multiempresa()) {
            $usuarios = new Usuario(get_db());
            $user = $buscar($usuarios);
            return $user === null ? null : [marca()['nombre_empresa'], $usuarios, $user];
        }

        $saas = new Saas(Database::master());
        if ($email !== null && $saas->superAdminPorCorreo($email) !== null) {
            return null;
        }
        $porHost = $saas->clientePorHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $empresas = $porHost !== null ? [$porHost] : $saas->empresasParaLogin();

        $hallados = [];
        foreach ($empresas as $empresa) {
            if ($empresa['estado_licencia'] !== 'activa') {
                continue;
            }
            try {
                $user = $buscar(new Usuario(Database::conectarA((string) $empresa['nombre_db_fisica'])));
            } catch (DatabaseException|PDOException $e) {
                error_log('[ADARA][reset] empresa ' . $empresa['id'] . ': ' . $e->getMessage());
                continue;
            }
            if ($user !== null) {
                $hallados[] = [$empresa, $user];
            }
        }
        if (count($hallados) !== 1) {
            return null;
        }

        [$empresa, $user] = $hallados[0];
        Database::usarEmpresa((string) $empresa['nombre_db_fisica']);
        return [(string) $empresa['nombre_empresa'], new Usuario(get_db()), $user];
    }

    private function tokenRecibido(mixed $valor): ?string
    {
        return is_string($valor) && preg_match(self::PATRON_TOKEN, $valor) === 1 ? $valor : null;
    }

    /** Marca de la empresa en su propio subdominio; en la dirección principal, la de ADARA. */
    private function prepararMarca(): void
    {
        if (!Database::multiempresa() || Database::empresaActiva() !== null) {
            return;
        }
        $cliente = (new Saas(Database::master()))->clientePorHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($cliente !== null && $cliente['estado_licencia'] === 'activa') {
            Database::usarEmpresa((string) $cliente['nombre_db_fisica']);
        }
    }

    private function correoHtml(string $nombre, string $empresa, string $enlace): string
    {
        $minutos = self::MINUTOS_VIGENCIA;
        ob_start();
        require ADARA_ROOT . '/pages/emails/recuperar_clave.php';
        return (string) ob_get_clean();
    }
}
