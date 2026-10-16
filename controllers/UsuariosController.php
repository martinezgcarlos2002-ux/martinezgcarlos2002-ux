<?php
declare(strict_types=1);

/**
 * Gestión de usuarios. El módulo es solo_admin: el router ya rechaza a cualquier otro rol.
 * Un admin no gestiona usuarios maestro. El maestro principal (id 6) no se elimina ni se degrada.
 * Siempre queda al menos un maestro activo y al menos un usuario con acceso total.
 */
final class UsuariosController
{
    private const MAESTRO_PRINCIPAL_ID = 6;

    private Usuario $usuarios;

    public function __construct()
    {
        $this->usuarios = new Usuario(get_db());
    }

    public function index(): void
    {
        [$old, $errors] = form_state();
        $q = $_GET['q'] ?? '';
        $q = is_string($q) ? mb_substr(trim($q), 0, 80) : '';

        view('usuarios_lista', [
            'usuarios'   => $this->usuarios->listar($q),
            'q'          => $q,
            'asignables' => self::modulosAsignables(),
            'acciones'   => RBAC_ACCIONES,
            'yo'         => current_user()['id'],
            'old'        => $old,
            'errors'     => $errors,
        ]);
    }

    public function store(): void
    {
        $actorId = current_user()['id'];
        try {
            $data = $this->validar(null);
            $id = Database::transaction(function () use ($data, $actorId): int {
                $id = $this->usuarios->create(
                    $data['nombre'],
                    $data['email'],
                    Usuario::hashPassword($data['password']),
                    $data['rol'],
                    [],
                    $data['acceso_movil'],
                    $data['activo']
                );
                audit_log('usuarios', 'crear', self::sinClave($data), 'usuarios', $id, $actorId);
                return $id;
            });
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, 'usuarios');
        } catch (PDOException $e) {
            $this->failOnDuplicate($e);
        }

        flash('success', sprintf('Usuario %s creado%s.', $data['email'], $data['rol'] === 'empleado' ? '. Asígnale sus módulos en Permisos' : ''));
        redirect('usuarios', ['creado' => $id]);
    }

    public function update(): void
    {
        $id = $this->idPost();
        $actorId = current_user()['id'];
        try {
            $data = $this->validar($id);
            Database::transaction(function () use ($id, $data, $actorId): void {
                $antes = $this->existenteOFallar($id, true);
                $this->exigirPuedeGestionarMaestro($antes);

                if ($id === $actorId && (!is_admin(['rol' => $data['rol']]) || !$data['activo'])) {
                    throw new ValidationException(['rol' => 'No puedes quitarte el acceso total ni desactivarte a ti mismo.']);
                }
                if ($id === self::MAESTRO_PRINCIPAL_ID && $data['rol'] !== 'maestro') {
                    throw new ValidationException(['rol' => 'El Usuario Maestro principal no puede ser degradado.']);
                }
                $dejaDeSerAdminActivo = is_admin($antes) && $antes['activo'] && (!is_admin(['rol' => $data['rol']]) || !$data['activo']);
                if ($dejaDeSerAdminActivo && $this->usuarios->contarAdminsActivos($id) === 0) {
                    throw new ValidationException(['rol' => 'Debe quedar al menos un administrador activo.']);
                }
                $dejaDeSerMaestroActivo = $antes['rol'] === 'maestro' && $antes['activo'] && ($data['rol'] !== 'maestro' || !$data['activo']);
                if ($dejaDeSerMaestroActivo && $this->usuarios->contarMaestrosActivos($id) === 0) {
                    throw new ValidationException(['rol' => 'Debe quedar al menos un Usuario Maestro activo.']);
                }

                $this->usuarios->update($id, $data);
                if ($data['password'] !== '') {
                    $this->usuarios->updatePasswordHash($id, Usuario::hashPassword($data['password']));
                }

                $cambios = [];
                foreach (['nombre', 'email', 'rol', 'activo', 'acceso_movil'] as $campo) {
                    if ($antes[$campo] !== $data[$campo]) {
                        $cambios[$campo] = ['antes' => $antes[$campo], 'despues' => $data[$campo]];
                    }
                }
                if ($data['password'] !== '') {
                    $cambios['password'] = 'cambiada';
                }
                audit_log('usuarios', 'editar', $cambios, 'usuarios', $id, $actorId);
            });
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, 'usuarios');
        } catch (PDOException $e) {
            $this->failOnDuplicate($e);
        }

        flash('success', 'Usuario actualizado.');
        redirect('usuarios');
    }

    /** permisos[modulo][] = ver|crear|editar|anular. "ver" se añade solo si se marca cualquier otra acción. */
    public function permisos(): void
    {
        $id = $this->idPost();
        $raw = $_POST['permisos'] ?? [];
        $asignables = self::modulosAsignables();

        $permisos = [];
        if (is_array($raw)) {
            foreach ($raw as $modulo => $acciones) {
                if (!is_string($modulo) || !isset($asignables[$modulo]) || !is_array($acciones)) {
                    continue;
                }
                $validas = array_values(array_intersect(RBAC_ACCIONES, $acciones));
                if ($validas !== [] && !in_array('ver', $validas, true)) {
                    array_unshift($validas, 'ver');
                }
                if ($validas !== []) {
                    $permisos[$modulo] = $validas;
                }
            }
        }

        $actorId = current_user()['id'];
        try {
            $nombre = Database::transaction(function () use ($id, $permisos, $actorId): string {
                $antes = $this->existenteOFallar($id, true);
                if (in_array($antes['rol'], ['maestro', 'admin'], true)) {
                    throw new ValidationException(['permisos' => 'Los administradores tienen acceso total: no llevan permisos por módulo.']);
                }
                $this->usuarios->updatePermisos($id, $permisos);
                audit_log('usuarios', 'permisos', ['antes' => $antes['permisos'], 'despues' => $permisos], 'usuarios', $id, $actorId);
                return $antes['nombre'];
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors);
        }

        flash('success', sprintf('Permisos de %s guardados: %d módulo(s).', $nombre, count($permisos)));
        redirect('usuarios');
    }

    public function movil(): void
    {
        $id = $this->idPost();
        $actorId = current_user()['id'];
        try {
            [$nombre, $permitido] = Database::transaction(function () use ($id, $actorId): array {
                $antes = $this->existenteOFallar($id, true);
                $this->exigirPuedeGestionarMaestro($antes);
                $permitido = !$antes['acceso_movil'];
                $this->usuarios->setAccesoMovil($id, $permitido);
                audit_log('usuarios', 'acceso_movil', ['antes' => $antes['acceso_movil'], 'despues' => $permitido], 'usuarios', $id, $actorId);
                return [$antes['nombre'], $permitido];
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors);
        }

        flash('success', sprintf('Acceso móvil de %s: %s.', $nombre, $permitido ? 'permitido' : 'bloqueado'));
        redirect('usuarios');
    }

    public function destroy(): void
    {
        $id = $this->idPost();
        $actorId = current_user()['id'];
        if ($id === $actorId) {
            $this->fallar(['id' => 'No puedes eliminar tu propio usuario.']);
        }
        if ($id === self::MAESTRO_PRINCIPAL_ID) {
            $this->fallar(['id' => 'El Usuario Maestro principal no puede ser eliminado.']);
        }

        try {
            $nombre = Database::transaction(function () use ($id, $actorId): string {
                $antes = $this->existenteOFallar($id, true);
                $this->exigirPuedeGestionarMaestro($antes);
                if (is_admin($antes) && $antes['activo'] && $this->usuarios->contarAdminsActivos($id) === 0) {
                    throw new ValidationException(['id' => 'Debe quedar al menos un administrador activo.']);
                }
                if ($antes['rol'] === 'maestro' && $antes['activo'] && $this->usuarios->contarMaestrosActivos($id) === 0) {
                    throw new ValidationException(['id' => 'Debe quedar al menos un Usuario Maestro activo.']);
                }
                $this->usuarios->eliminar($id, $actorId);
                audit_log('usuarios', 'eliminar', [
                    'nombre' => $antes['nombre'],
                    'email'  => $antes['email'],
                    'rol'    => $antes['rol'],
                ], 'usuarios', $id, $actorId);
                return $antes['nombre'];
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors);
        }

        flash('success', sprintf('Usuario %s eliminado. Pierde el acceso de inmediato; su historial de auditoría se conserva.', $nombre));
        redirect('usuarios');
    }

    /** @return array{nombre:string, email:string, password:string, rol:string, activo:bool, acceso_movil:bool} */
    private function validar(?int $id): array
    {
        $errors = [];

        $nombre = preg_replace('/\s+/u', ' ', post_string('nombre', 120));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) {
            $errors['nombre'] = 'El nombre debe tener entre 2 y 80 caracteres.';
        }

        $email = mb_strtolower(post_string('email', 150));
        if (mb_strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Correo inválido.';
        }

        $password = $_POST['password'] ?? '';
        $password = is_string($password) ? $password : '';
        if ($id === null || $password !== '') {
            if (mb_strlen($password) < PASSWORD_MIN_LEN || strlen($password) > PASSWORD_MAX_BYTES) {
                $errors['password'] = sprintf('La contraseña debe tener entre %d caracteres y %d bytes.', PASSWORD_MIN_LEN, PASSWORD_MAX_BYTES);
            }
        }

        $rol = $_POST['rol'] ?? '';
        if (!is_string($rol) || !in_array($rol, Usuario::ROLES, true)) {
            $errors['rol'] = 'Rol inválido.';
        } elseif ($rol === 'maestro' && !is_maestro()) {
            $errors['rol'] = 'Solo un Usuario Maestro puede crear o asignar ese rol.';
        }

        $estado = $_POST['estado'] ?? 'activo';
        if (!in_array($estado, ['activo', 'inactivo'], true)) {
            $errors['estado'] = 'Estado inválido.';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return [
            'nombre'       => $nombre,
            'email'        => $email,
            'password'     => $password,
            'rol'          => $rol,
            'activo'       => $estado === 'activo',
            'acceso_movil' => isset($_POST['acceso_movil']),
        ];
    }

    private function exigirPuedeGestionarMaestro(array $usuario): void
    {
        if (($usuario['rol'] ?? '') === 'maestro' && !is_maestro()) {
            throw new ValidationException(['rol' => 'Solo un Usuario Maestro puede gestionar a otro Usuario Maestro.']);
        }
    }

    private function existenteOFallar(int $id, bool $forUpdate): array
    {
        $u = $this->usuarios->find($id, $forUpdate);
        if ($u === null || $u['eliminado_en'] !== null) {
            throw new ValidationException(['id' => 'El usuario no existe o fue eliminado.']);
        }
        return $u;
    }

    private function idPost(): int
    {
        $id = parse_int_range($_POST['id'] ?? null, 1, PHP_INT_MAX);
        if ($id === null) {
            $this->fallar(['id' => 'Usuario inválido.']);
        }
        return $id;
    }

    /** Módulos que se asignan por usuario: los exclusivos de admin y los siempre visibles no llevan permiso. */
    private static function modulosAsignables(): array
    {
        return array_filter(modulos(), static fn (array $m): bool => empty($m['solo_admin']) && empty($m['siempre']));
    }

    private static function sinClave(array $data): array
    {
        unset($data['password']);
        return $data;
    }

    private function failOnDuplicate(PDOException $e): never
    {
        if (duplicate_key($e) === 'uq_usuarios_email') {
            form_fail($_POST, ['email' => 'Ya existe un usuario (activo o eliminado) con ese correo.'], 'usuarios');
        }
        throw $e;
    }

    private function fallar(array $errors): never
    {
        foreach ($errors as $mensaje) {
            flash('error', $mensaje);
        }
        redirect('usuarios');
    }
}
