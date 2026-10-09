<?php
declare(strict_types=1);

/** Recursos Humanos: pantalla integrada y ficha del empleado. */
final class EmpleadosController
{
    public const TABS = ['nomina', 'directorio', 'libres', 'cobranzas'];

    private Empleado $empleados;

    public function __construct()
    {
        $this->empleados = new Empleado(get_db());
    }

    public function index(): void
    {
        [$old, $errors] = form_state();
        $db = get_db();
        $user = current_user();

        $tab = $_GET['tab'] ?? 'nomina';
        $tab = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'nomina';

        [$semana] = Nomina::periodo(parse_date($_GET['semana'] ?? null) ?? date('Y-m-d'));
        $anio = parse_int_range($_GET['anio'] ?? null, 2000, 2100) ?? (int) date('Y');
        $mes = parse_int_range($_GET['mes'] ?? null, 1, 12) ?? (int) date('n');
        $mesDesde = sprintf('%04d-%02d-01', $anio, $mes);
        $mesHasta = (new DateTimeImmutable($mesDesde))->format('Y-m-t');

        $tasa = TasaCambio::vigenteEn($db, date('Y-m-d H:i:s'));
        $dias = new DiaLibre($db);
        $cobranzas = new CobranzaTercero($db);
        $estadoCobranza = $_GET['estado'] ?? null;
        $estadoCobranza = is_string($estadoCobranza) && isset(CobranzaTercero::ESTADOS[$estadoCobranza]) ? $estadoCobranza : null;

        $vigentesMes = $this->empleados->vigentesEntre($mesDesde, $mesHasta);
        $cargos = $this->empleados->cargos();
        usort($vigentesMes, static function (array $a, array $b): int {
            return [$a['cargo'] === '' ? 'ZZZ' : $a['cargo'], $a['apellidos'], $a['nombres']]
                <=> [$b['cargo'] === '' ? 'ZZZ' : $b['cargo'], $b['apellidos'], $b['nombres']];
        });
        $marcasMes = $dias->rango($mesDesde, $mesHasta);
        $cupos = array_column($vigentesMes, 'dias_libres_mes', 'id');

        $nomina = new Nomina($db);
        view('empleados', [
            'tab'            => $tab,
            'empresa'        => APP_EMPRESA,
            'rif'            => 'V-26011525-8',
            'tipoNomina'     => 'Semanal',
            'nomina'         => $nomina->semana($semana, $tasa['tasa'] ?? null),
            'deudas'         => $nomina->saldosDeuda(),
            'empleados'      => $this->empleados->listar(),
            'cargos'         => $cargos,
            'anio'           => $anio,
            'mes'            => $mes,
            'libres'         => $vigentesMes,
            'marcasMes'      => $marcasMes,
            'excedidosMes'   => $dias->excedidos($cupos, $mesDesde, $mesHasta, $marcasMes),
            'alertas'        => $dias->alertasCobertura($mesDesde, $mesHasta),
            'cobranzas'      => $cobranzas->listar($estadoCobranza),
            'totalesCobro'   => $cobranzas->totales(),
            'totalesPrestamo'=> (new PrestamoEmpleado($db))->totales(),
            'tiposPrestamo'  => PrestamoEmpleado::TIPOS,
            'estadoCobranza' => $estadoCobranza,
            'puedeCrear'     => has_permiso($user, 'empleados', 'crear'),
            'puedeEditar'    => has_permiso($user, 'empleados', 'editar'),
            'esAdmin'        => $user['rol'] === 'admin',
            'old'            => $old,
            'errors'         => $errors,
        ]);
    }

    public function diasLibres(): void
    {
        $_GET['tab'] = 'libres';
        $this->index();
    }

    public function store(): void
    {
        $data = $this->validar(null);
        try {
            $fotos = EmpleadoArchivo::leer($_FILES);
            $id = Database::transaction(function () use ($data, $fotos): int {
                $id = $this->empleados->create($data);
                $rutas = EmpleadoArchivo::guardar($id, $fotos);
                if ($rutas !== []) {
                    $this->empleados->actualizarFotos($id, $rutas);
                }
                audit_log('empleados', 'crear', $data + $rutas, 'empleados', $id);
                return $id;
            });
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, 'empleados', ['tab' => 'directorio']);
        } catch (PDOException $e) {
            $this->duplicado($e, null);
        }
        flash('success', 'Empleado registrado: ' . $data['nombres'] . ' ' . $data['apellidos'] . '.');
        redirect('empleados', ['tab' => 'directorio']);
    }

    public function update(): void
    {
        $id = parse_int_range($_POST['id'] ?? null, 1, PHP_INT_MAX);
        if ($id === null) {
            flash('error', 'Empleado no válido.');
            redirect('empleados', ['tab' => 'directorio']);
        }
        $data = $this->validar($id);
        try {
            $fotos = EmpleadoArchivo::leer($_FILES);
            Database::transaction(function () use ($id, $data, $fotos): void {
                $antes = $this->empleados->find($id, true);
                if ($antes === null) {
                    throw new ValidationException(['id' => 'El empleado ya no existe.']);
                }
                $this->empleados->update($id, $data);
                $rutas = EmpleadoArchivo::guardar($id, $fotos);
                foreach ($rutas as $campo => $rel) {
                    EmpleadoArchivo::borrar((string) ($antes[$campo] ?? ''));
                }
                if ($rutas !== []) {
                    $this->empleados->actualizarFotos($id, $rutas);
                }
                $cambios = [];
                foreach ($data + $rutas as $campo => $valor) {
                    if ((string) ($antes[$campo] ?? '') !== (string) $valor) {
                        $cambios[$campo] = ['antes' => $antes[$campo] ?? null, 'despues' => $valor];
                    }
                }
                audit_log('empleados', 'editar', $cambios, 'empleados', $id);
            });
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, 'empleados', ['tab' => 'directorio']);
        } catch (PDOException $e) {
            $this->duplicado($e, $id);
        }
        flash('success', 'Empleado actualizado.');
        redirect('empleados', ['tab' => 'directorio']);
    }

    /** @return array<string, mixed> */
    private function validar(?int $id): array
    {
        $errors = [];

        $cedula = normalize_documento(post_string('cedula', 20));
        if ($cedula === null || !preg_match('/^[VE]-\d{6,9}$/', $cedula)) {
            $errors['cedula'] = 'Cédula inválida. Formato V-12345678 o E-12345678.';
        }
        $nombres = mb_strtoupper(preg_replace('/\s+/u', ' ', post_string('nombres', 80)));
        if (mb_strlen($nombres) < 2 || mb_strlen($nombres) > 60) {
            $errors['nombres'] = 'Los nombres son obligatorios (2 a 60 caracteres).';
        }
        $apellidos = mb_strtoupper(preg_replace('/\s+/u', ' ', post_string('apellidos', 80)));
        if (mb_strlen($apellidos) < 2 || mb_strlen($apellidos) > 60) {
            $errors['apellidos'] = 'Los apellidos son obligatorios (2 a 60 caracteres).';
        }
        $cargo = mb_strtoupper(post_string('cargo', 60));
        if (!isset($this->empleados->cargos()[$cargo])) {
            $errors['cargo'] = 'Selecciona un cargo de la lista.';
        }
        $telefono = post_string('telefono', 30);
        if ($telefono !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $telefono)) {
            $errors['telefono'] = 'Teléfono inválido.';
        }
        $profesion = mb_strtoupper(preg_replace('/\s+/u', ' ', post_string('profesion', 80)));
        if (mb_strlen($profesion) > 80) {
            $errors['profesion'] = 'La profesión admite hasta 80 caracteres.';
        }
        $direccion = preg_replace('/\s+/u', ' ', post_string('direccion', 200));
        if (mb_strlen($direccion) > 160) {
            $errors['direccion'] = 'La dirección admite hasta 160 caracteres.';
        }
        $domicilio = preg_replace('/\s+/u', ' ', post_string('direccion_domicilio', 400));
        if ($domicilio === '') {
            $domicilio = $direccion;
        }
        if (mb_strlen($domicilio) > 400) {
            $errors['direccion_domicilio'] = 'El domicilio admite hasta 400 caracteres.';
        }
        $salario = parse_decimal($_POST['salario_semanal_usd'] ?? null, 2, 100000, true);
        if ($salario === null) {
            $errors['salario_semanal_usd'] = 'Salario inválido: número positivo con hasta 2 decimales.';
        }
        $libres = parse_int_range($_POST['dias_libres_mes'] ?? null, 0, 31);
        if ($libres === null) {
            $errors['dias_libres_mes'] = 'Indica cuántos libres pagados tiene al mes (0 a 31).';
        }

        $fechas = [];
        foreach (['fecha_ingreso', 'fecha_salida', 'fecha_nacimiento'] as $campo) {
            $raw = post_string($campo, 10);
            $fechas[$campo] = $raw === '' ? '' : (parse_date($raw) ?? false);
            if ($fechas[$campo] === false) {
                $errors[$campo] = 'Fecha inválida.';
            }
        }
        if (is_string($fechas['fecha_ingreso']) && is_string($fechas['fecha_salida'])
            && $fechas['fecha_ingreso'] !== '' && $fechas['fecha_salida'] !== ''
            && $fechas['fecha_salida'] < $fechas['fecha_ingreso']) {
            $errors['fecha_salida'] = 'La salida no puede ser anterior al ingreso.';
        }
        if (is_string($fechas['fecha_nacimiento']) && $fechas['fecha_nacimiento'] > date('Y-m-d')) {
            $errors['fecha_nacimiento'] = 'La fecha de nacimiento no puede ser futura.';
        }

        $tipoCuenta = $_POST['tipo_cuenta'] ?? 'propia';
        $tipoCuenta = is_string($tipoCuenta) && isset(Empleado::TIPOS_CUENTA[$tipoCuenta]) ? $tipoCuenta : 'propia';
        $banco = preg_replace('/\s+/u', ' ', post_string('banco', 100));
        if (mb_strlen($banco) > 80) {
            $errors['banco'] = 'El banco admite hasta 80 caracteres.';
        }
        $titularRaw = post_string('titular_cedula', 20);
        $titularCedula = $titularRaw === '' ? '' : normalize_documento($titularRaw);
        if ($titularCedula === null) {
            $errors['titular_cedula'] = 'Cédula/RIF inválido. Ej.: V-12345678 o J-12345678-9.';
        } elseif ($tipoCuenta === 'tercero' && $titularCedula === '') {
            $errors['titular_cedula'] = 'Indica la cédula/RIF del titular de la cuenta de tercero.';
        }
        $titularTelefono = self::telefonoPagoMovil(post_string('titular_telefono', 30));
        if ($titularTelefono === null) {
            $errors['titular_telefono'] = 'Teléfono inválido. Formato 0414-1234567.';
        }
        $numeroCuenta = preg_replace('/\D/', '', post_string('numero_cuenta', 40));
        if ($numeroCuenta !== '' && strlen($numeroCuenta) !== 20) {
            $errors['numero_cuenta'] = 'El número de cuenta debe tener 20 dígitos.';
        }

        $activo = ($_POST['activo'] ?? '1') === '1';
        if ($errors) {
            form_fail($_POST + ['id' => (string) ($id ?? '')], $errors, 'empleados', ['tab' => 'directorio']);
        }

        return [
            'cedula'           => $cedula,
            'nombres'          => $nombres,
            'apellidos'        => $apellidos,
            'cargo'            => $cargo,
            'profesion'        => $profesion,
            'telefono'         => $telefono,
            'direccion'        => $direccion,
            'direccion_domicilio' => $domicilio,
            'fecha_ingreso'    => $fechas['fecha_ingreso'],
            'fecha_salida'     => $fechas['fecha_salida'],
            'fecha_nacimiento' => $fechas['fecha_nacimiento'],
            'salario_semanal'  => $salario,
            'dias_libres_mes'  => $libres,
            'tipo_cuenta'      => $tipoCuenta,
            'banco'            => $banco,
            'titular_cedula'   => $titularCedula,
            'titular_telefono' => $titularTelefono,
            'numero_cuenta'    => $numeroCuenta,
            'expediente_penal' => isset($_POST['expediente_penal']),
            'activo'           => $activo,
        ];
    }

    /** POST AJAX: crea un cargo. Responde JSON con el nombre normalizado (MAYÚSCULAS). */
    public function cargoStore(): void
    {
        $nombre = mb_strtoupper(preg_replace('/\s+/u', ' ', post_string('nombre', 80)));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 60 || !preg_match('/^[\p{L}0-9 .\-\/]+$/u', $nombre)) {
            json_response(422, ['ok' => false, 'errores' => ['nombre' => 'Nombre de cargo inválido (2 a 60 letras, números, espacio, punto o guion).']]);
        }
        $minimo = parse_int_range($_POST['cobertura_minima'] ?? '1', 1, 20) ?? 1;

        $creado = Database::transaction(function () use ($nombre, $minimo): bool {
            $creado = $this->empleados->crearCargo($nombre, $minimo);
            if ($creado) {
                audit_log('empleados', 'crear_cargo', ['nombre' => $nombre, 'cobertura_minima' => $minimo], 'cargos');
            }
            return $creado;
        });
        json_response($creado ? 201 : 200, [
            'ok'               => true,
            'creado'           => $creado,
            'nombre'           => $nombre,
            'cobertura_minima' => $this->empleados->cargos()[$nombre] ?? $minimo,
        ]);
    }

    /** Normaliza a 0414-1234567. '' si viene vacío, null si es inválido. */
    private static function telefonoPagoMovil(string $raw): ?string
    {
        $digitos = preg_replace('/\D/', '', $raw);
        if ($digitos === '') {
            return '';
        }
        if (str_starts_with($digitos, '58') && strlen($digitos) === 12) {
            $digitos = '0' . substr($digitos, 2);
        }
        return preg_match('/^0[24]\d{9}$/', $digitos) ? substr($digitos, 0, 4) . '-' . substr($digitos, 4) : null;
    }

    private function duplicado(PDOException $e, ?int $id): never
    {
        if (duplicate_key($e) === 'uq_empleados_cedula') {
            form_fail($_POST + ['id' => (string) ($id ?? '')], ['cedula' => 'Ya existe un empleado con esa cédula.'], 'empleados', ['tab' => 'directorio']);
        }
        throw $e;
    }

    public function cvPdf(): void
    {
        $id = parse_int_range($_GET['id'] ?? null, 1, PHP_INT_MAX);
        if ($id === null) {
            flash('error', 'Empleado no válido.');
            redirect('empleados', ['tab' => 'directorio']);
        }
        $emp = $this->empleados->find($id);
        if ($emp === null) {
            flash('error', 'Empleado no válido.');
            redirect('empleados', ['tab' => 'directorio']);
        }
        view('rrhh_cv', [
            'emp'         => $emp,
            'empresa'     => APP_EMPRESA,
            'fotoPerfil'  => EmpleadoArchivo::dataUri((string) $emp['foto_perfil']),
            'fotoDoc'     => EmpleadoArchivo::dataUri((string) $emp['foto_documento']),
        ]);
    }
}
