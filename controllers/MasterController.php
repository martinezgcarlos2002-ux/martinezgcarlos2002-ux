<?php
declare(strict_types=1);

/**
 * Panel Maestro SaaS: empresas, licencias y accesos de super admin. Solo el super admin (adara_master).
 * index.php ya filtra las rutas 'super_admin'; el constructor lo exige de nuevo por si una ruta nueva
 * olvida el flag. Con una clave inicial/temporal solo se permite cambiarla o salir.
 * La gestión de accesos es exclusiva del propietario (Saas::PROPIETARIO_ID), que a su vez es intocable.
 */
final class MasterController
{
    private const RUTAS_CON_CLAVE_TEMPORAL = ['master_clave', 'master_salir', 'master_contrato'];

    private Saas $saas;
    /** @var array{id:int, nombre:string, correo:string, cedula:string, telefono:string, direccion:string, debe_cambiar_clave:bool} */
    private array $admin;

    public function __construct()
    {
        $this->saas = new Saas(Database::master());
        // Salir del modo soporte ocurre SIN rol_maestro: esa sesión es la del admin de la empresa.
        // Si no hay soporte activo, el método responde; no se concede nada.
        if (($_GET['p'] ?? '') === 'master_salir_soporte') {
            $this->admin = [
                'id' => is_int($_SESSION['impersonado_por'] ?? null) ? $_SESSION['impersonado_por'] : 0,
                'nombre' => (string) ($_SESSION['impersonado_por_nombre'] ?? ''),
                'correo' => '',
                'cedula' => '',
                'telefono' => '',
                'direccion' => '',
                'debe_cambiar_clave' => false,
            ];
            return;
        }
        $admin = super_admin_actual();
        if ($admin === null || ($_SESSION['rol_maestro'] ?? null) !== true) {
            http_response_code(403);
            exit;
        }
        $this->admin = $admin;
        if ($admin['debe_cambiar_clave'] && !in_array($_GET['p'] ?? '', self::RUTAS_CON_CLAVE_TEMPORAL, true)) {
            flash('warning', 'Antes de continuar, cambia la clave inicial de tu cuenta.');
            redirect('master_clave');
        }
    }

    public function index(): void
    {
        $this->render('inicio', [], []);
    }

    public function accesos(): void
    {
        redirect('master');
    }

    public function registrar(): void
    {
        $datos = [
            'nombre_empresa'       => post_string('nombre_empresa', 120),
            'dominio_o_slug'       => post_string('dominio_o_slug', 100),
            'correo_admin_cliente' => post_string('correo_admin_cliente', 100),
            'nombre_db_fisica'     => post_string('nombre_db_fisica', 64),
        ];
        $existente = ($_POST['base_existente'] ?? '') === '1';
        $manager = new SaaSManager(Database::master(), $this->saas);
        try {
            if ($existente) {
                $manager->vincularExistente($datos, $this->admin['id']);
                flash('success', 'Empresa ' . $datos['nombre_empresa'] . ' vinculada a su base existente, con licencia activa.');
            } else {
                $r = $manager->aprovisionar($datos, $this->admin['id']);
                flash('success', sprintf('Empresa %s desplegada en %s (%d sentencias, %.1f s), con licencia activa.',
                    $datos['nombre_empresa'], strtolower($datos['nombre_db_fisica']), $r['sentencias'], $r['segundos']));
                $_SESSION['_clave_temporal'] = [
                    'titulo' => 'Administrador de ' . $datos['nombre_empresa'],
                    'correo' => mb_strtolower($datos['correo_admin_cliente']),
                    'clave'  => $r['clave_temporal'],
                ];
            }
        } catch (ValidationException $e) {
            http_response_code(422);
            $this->render('empresas', $e->errors, $datos + ['base_existente' => $existente ? '1' : '']);
            return;
        } catch (AprovisionamientoException $e) {
            http_response_code(500);
            $this->render('empresas', ['general' => $e->getMessage()], $datos);
            return;
        }
        redirect('master');
    }

    public function licencia(): void
    {
        $id = parse_int_range($_POST['id'] ?? '', 1, 4294967295);
        $estado = post_string('estado', 10);
        try {
            if ($id === null) {
                throw new ValidationException(['id' => 'Empresa inválida.']);
            }
            $this->saas->cambiarLicencia($id, $estado, $this->admin['id']);
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('master');
        }
        flash('success', $estado === 'activa'
            ? 'Licencia activada. La empresa ya puede iniciar sesión.'
            : 'Licencia suspendida. Sus usuarios salen del sistema en su próxima acción.');
        redirect('master');
    }

    /**
     * Volcado .sql completo de la base física de una empresa, en streaming al navegador.
     * Usa RespaldoSql (PDO nativo): sin exec() ni credenciales de MySQL en la línea de comandos.
     * Queda en master_auditoria al empezar y, con tamaño y SHA-256, al terminar.
     */
    public function respaldoEmpresa(): void
    {
        $id = parse_int_range($_POST['id'] ?? '', 1, 4294967295);
        $cliente = $id === null ? null : $this->saas->clientePorId($id);
        if ($cliente === null) {
            flash('error', 'La empresa no existe.');
            redirect('master');
        }
        $base = (string) $cliente['nombre_db_fisica'];
        try {
            $pdo = Database::conectarA($base);
        } catch (DatabaseException) {
            flash('error', 'La base de ' . $cliente['nombre_empresa'] . ' no existe o no responde.');
            redirect('master');
        }

        $clienteId = (int) $cliente['id'];
        $archivo = sprintf('%s_%s.sql', $base, date('Y-m-d_His'));
        $this->saas->auditar($this->admin['id'], 'respaldo_empresa', $clienteId, ['base' => $base, 'archivo' => $archivo]);

        session_write_close();
        set_time_limit(0);
        ignore_user_abort(false);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/sql; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');

        $hash = hash_init('sha256');
        $bytes = 0;
        $resumen = (new RespaldoSql($pdo))->generar(static function (string $sql) use ($hash, &$bytes): void {
            hash_update($hash, $sql);
            $bytes += strlen($sql);
            echo $sql;
            flush();
        });

        if (connection_aborted()) {
            return;
        }
        $this->saas->auditar($this->admin['id'], 'respaldo_empresa_fin', $clienteId, $resumen + [
            'archivo' => $archivo,
            'bytes'   => $bytes,
            'sha256'  => hash_final($hash),
        ]);
    }

    public function crearAcceso(): void
    {
        $form = [
            'sa_nombre'      => post_string('sa_nombre', 80),
            'sa_correo'      => post_string('sa_correo', 100),
            'prefijo_cedula' => post_string('prefijo_cedula', 2),
            'cedula_numero'  => post_string('cedula_numero', 12),
            'sa_telefono'    => post_string('sa_telefono', 30),
            'sa_direccion'   => post_string('sa_direccion', 500),
        ];
        $cedula_completa = $form['prefijo_cedula'] . $form['cedula_numero'];
        try {
            $r = $this->saas->crearSuperAdmin(
                $form['sa_nombre'],
                $form['sa_correo'],
                $cedula_completa,
                $form['sa_telefono'],
                $form['sa_direccion'],
                $this->admin['id']
            );
        } catch (ValidationException $e) {
            http_response_code(422);
            $this->render('accesos', $e->errors, $form);
            return;
        }
        $_SESSION['_clave_temporal'] = [
            'titulo' => 'Super admin ' . $form['sa_nombre'] . ' (id ' . $r['id'] . ')',
            'correo' => mb_strtolower($form['sa_correo']),
            'clave'  => $r['clave'],
        ];
        flash('success', 'Acceso creado. Deberá cambiar la clave temporal en su primer ingreso.');
        redirect('master');
    }

    public function estadoAcceso(): void
    {
        $id = parse_int_range($_POST['id'] ?? '', 1, 4294967295) ?? 0;
        $activo = post_string('estado', 10) === 'activo';
        $this->accionAcceso(function () use ($id, $activo): string {
            $this->saas->cambiarEstadoSuperAdmin($id, $activo, $this->admin['id']);
            return $activo ? 'Acceso reactivado.' : 'Acceso suspendido: su sesión termina en su próxima acción.';
        });
    }

    public function revocarAcceso(): void
    {
        $id = parse_int_range($_POST['id'] ?? '', 1, 4294967295) ?? 0;
        $this->accionAcceso(function () use ($id): string {
            $this->saas->revocarSuperAdmin($id, $this->admin['id']);
            return 'Acceso revocado. Solo el propietario puede reactivarlo.';
        });
    }

    /** Solo el propietario. Reactiva un revocado y muestra su clave temporal una sola vez. */
    public function reactivarAcceso(): void
    {
        $id = parse_int_range($_POST['id'] ?? '', 1, 4294967295) ?? 0;
        try {
            $r = $this->saas->reactivarSuperAdmin($id, $this->admin['id']);
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('master');
        } catch (PDOException $e) {
            error_log('[ADARA][master] ' . $e->getMessage());
            flash('error', 'La base maestra rechazó el cambio.');
            redirect('master');
        }
        $_SESSION['_clave_temporal'] = [
            'titulo' => 'Super admin reactivado (id ' . $r['id'] . ')',
            'correo' => $r['correo'],
            'clave'  => $r['clave'],
        ];
        flash('success', 'Acceso reactivado. Deberá cambiar la clave temporal en su primer ingreso.');
        redirect('master');
    }

    public function contrato(): void
    {
        $esPropietario = $this->admin['id'] === Saas::PROPIETARIO_ID;
        view('master_contrato', [
            'admin'         => $this->admin,
            'esPropietario' => $esPropietario,
            'firmas'        => $esPropietario ? $this->saas->firmasAcuerdo() : [],
        ]);
    }

    /** Mapa de las carpetas del MVC. Solo nombres: no lee ni muestra el contenido de ningún archivo. */
    public function arquitectura(): void
    {
        view('master_arquitectura', [
            'admin'   => $this->admin,
            'arbol'   => self::arbolArquitectura(),
            'version' => ADARA_VERSION,
        ]);
    }

    /** Manual técnico: logo, título y el árbol del MVC, descargado con DomPDF::stream(). */
    public function arquitecturaPdf(): void
    {
        $this->saas->auditar($this->admin['id'], 'manual_tecnico', null, ['version' => ADARA_VERSION]);
        $this->emitirPdf(
            $this->htmlManualTecnico(self::arbolArquitectura()),
            'ADARA_manual_tecnico_v' . ADARA_VERSION . '.pdf'
        );
    }

    /** Manual de usuario: la plantilla principal incluye cada capítulo. */
    public function usuarioPdf(): void
    {
        $version = ADARA_VERSION;
        $fecha = date('d/m/Y H:i');
        $logo = self::logoPdf();
        $capitulos = [
            '01_accesos.php',
            '02_productores.php',
            '03_produccion.php',
            '04_ventas.php',
            '05_gas_transporte.php',
        ];
        ob_start();
        require ADARA_ROOT . '/pages/manuales/layout_pdf.php';
        $html = (string) ob_get_clean();
        $this->saas->auditar($this->admin['id'], 'manual_usuario', null, ['version' => ADARA_VERSION]);
        $this->emitirPdf($html, 'ADARA_manual_usuario_v' . ADARA_VERSION . '.pdf');
    }

    private function emitirPdf(string $html, string $archivo): never
    {
        $autoload = ADARA_ROOT . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        if (!class_exists(\Dompdf\Dompdf::class)) {
            flash('error', 'DomPDF no está instalado. En la raíz del proyecto ejecuta: composer require dompdf/dompdf');
            redirect('master_arquitectura');
        }
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled'      => false,
            'isHtml5ParserEnabled' => true,
            'chroot'               => ADARA_ROOT,
        ]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $dompdf->getCanvas()->page_text(430, 760, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 9, [0.39, 0.45, 0.55]);
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $dompdf->stream($archivo, ['Attachment' => true]);
        exit;
    }

    private static function logoPdf(): string
    {
        $path = ADARA_ROOT . '/assets/' . LOGO_SISTEMA;
        if (!is_file($path)) {
            return '';
        }
        $bin = file_get_contents($path);
        if ($bin === false) {
            return '';
        }
        return '<img src="data:image/jpeg;base64,' . base64_encode($bin) . '" width="92" alt="ADARA">';
    }

    private const ARQUITECTURA_RAICES = ['controllers', 'models', 'pages', 'services', 'config'];
    private const ARQUITECTURA_IGNORAR = ['vendor', 'lib', '.git', 'node_modules'];

    /**
     * Escanea solo las raíces del MVC. RecursiveCallbackFilterIterator no entra
     * en vendor, lib, .git ni node_modules. Un enlace que salga de la carpeta se descarta.
     *
     * @return list<array{nombre:string, tipo:string, hijos:array}>
     */
    private static function arbolArquitectura(): array
    {
        $raices = [];
        foreach (self::ARQUITECTURA_RAICES as $nombre) {
            $abs = ADARA_ROOT . DIRECTORY_SEPARATOR . $nombre;
            $real = realpath($abs);
            if ($real === false || !is_dir($real)) {
                continue;
            }
            $filtro = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $info): bool {
                    return !in_array($info->getFilename(), self::ARQUITECTURA_IGNORAR, true);
                }
            );
            $it = new RecursiveIteratorIterator($filtro, RecursiveIteratorIterator::SELF_FIRST);
            $rutas = [];
            $base = str_replace('\\', '/', $real);
            foreach ($it as $info) {
                if ($info->isLink()) {
                    continue;
                }
                $camino = $info->getRealPath();
                if ($camino === false || !str_starts_with(str_replace('\\', '/', $camino), $base . '/')) {
                    continue;
                }
                $rel = str_replace('\\', '/', $it->getSubPathname());
                if ($rel === '' || self::segmentoIgnorado(explode('/', $rel))) {
                    continue;
                }
                $baseNombre = basename($rel);
                if (str_starts_with($baseNombre, '.') || str_ends_with($baseNombre, '.local.php')) {
                    continue;
                }
                $rutas[] = [$rel, $info->isDir()];
            }
            $raices[] = self::arbolDesdeRutas($nombre, $rutas);
        }
        return $raices;
    }

    /** @param list<string> $partes */
    private static function segmentoIgnorado(array $partes): bool
    {
        foreach ($partes as $parte) {
            if ($parte === '' || $parte === '.' || $parte === '..' || in_array($parte, self::ARQUITECTURA_IGNORAR, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{0:string, 1:bool}> $rutas
     * @return array{nombre:string, tipo:string, hijos:array}
     */
    private static function arbolDesdeRutas(string $raiz, array $rutas): array
    {
        $nodo = ['nombre' => $raiz, 'tipo' => 'dir', 'hijos' => []];
        foreach ($rutas as [$rel, $esDir]) {
            $cursor = &$nodo;
            $partes = explode('/', $rel);
            $ultimo = count($partes) - 1;
            foreach ($partes as $i => $parte) {
                $pos = null;
                foreach ($cursor['hijos'] as $k => $hijo) {
                    if ($hijo['nombre'] === $parte) {
                        $pos = $k;
                        break;
                    }
                }
                if ($pos === null) {
                    $cursor['hijos'][] = [
                        'nombre' => $parte,
                        'tipo'   => ($i === $ultimo && !$esDir) ? 'archivo' : 'dir',
                        'hijos'  => [],
                    ];
                    $pos = array_key_last($cursor['hijos']);
                }
                $cursor = &$cursor['hijos'][$pos];
            }
            unset($cursor);
        }
        self::ordenarNodo($nodo);
        return $nodo;
    }

    /** @param array{nombre:string, tipo:string, hijos:array} $nodo */
    private static function ordenarNodo(array &$nodo): void
    {
        usort($nodo['hijos'], static function (array $a, array $b): int {
            if ($a['tipo'] !== $b['tipo']) {
                return $a['tipo'] === 'dir' ? -1 : 1;
            }
            return strnatcasecmp($a['nombre'], $b['nombre']);
        });
        foreach ($nodo['hijos'] as &$hijo) {
            if ($hijo['tipo'] === 'dir') {
                self::ordenarNodo($hijo);
            }
        }
        unset($hijo);
    }

    /** @param list<array{nombre:string, tipo:string, hijos:array}> $arbol */
    private function htmlManualTecnico(array $arbol): string
    {
        $version = e(ADARA_VERSION);
        $fecha = e(date('d/m/Y H:i'));
        $lista = self::htmlArbolPdf($arbol);
        $logo = self::logoPdf();
        return <<<HTML
        <!DOCTYPE html>
        <html lang="es"><head><meta charset="UTF-8">
        <style>
          body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; }
          h1 { color: #1A2E5A; font-size: 28px; margin: 8px 0 0; }
          h2 { color: #1A2E5A; font-size: 16px; border-bottom: 2px solid #F97316; padding-bottom: 4px; }
          .portada { text-align: center; padding-top: 160px; }
          .franja { height: 6px; background: #F97316; margin: 18px auto; width: 120px; }
          .meta { color: #64748b; }
          ul { list-style: none; padding-left: 14px; margin: 2px 0; }
          li { margin: 1px 0; }
          .dir { font-weight: bold; color: #1A2E5A; }
        </style></head><body>
        <div class="portada">
          {$logo}
          <h1>Manual Técnico v{$version}</h1>
          <div class="franja"></div>
          <p style="font-size:16px;">Arquitectura del sistema</p>
          <p class="meta">Generado el {$fecha} desde el Panel Maestro</p>
        </div>
        <div style="page-break-after: always;"></div>
        <h2>Mapa de directorios</h2>
        <p class="meta">controllers, models, pages, services y config. No incluye vendor, lib, .git ni node_modules.</p>
        {$lista}
        </body></html>
        HTML;
    }

    /** @param list<array{nombre:string, tipo:string, hijos:array}> $nodos */
    private static function htmlArbolPdf(array $nodos): string
    {
        if ($nodos === []) {
            return '';
        }
        $html = '<ul>';
        foreach ($nodos as $nodo) {
            $nombre = e($nodo['nombre']);
            if ($nodo['tipo'] === 'dir') {
                $html .= '<li><span class="dir">' . $nombre . '/</span>' . self::htmlArbolPdf($nodo['hijos']) . '</li>';
            } else {
                $html .= '<li>' . $nombre . '</li>';
            }
        }
        return $html . '</ul>';
    }

    public function clave(): void
    {
        view('master_clave', ['admin' => $this->admin, 'errores' => []]);
    }

    public function cambiarClave(): void
    {
        $actual = (string) ($_POST['actual'] ?? '');
        $nueva = (string) ($_POST['nueva'] ?? '');
        $confirmacion = (string) ($_POST['confirmacion'] ?? '');
        $forzado = $this->admin['debe_cambiar_clave'];
        if ($forzado) {
            $erroresAcuerdo = [];
            if (($_POST['acepto_acuerdo'] ?? '') !== '1') {
                $erroresAcuerdo['acepto_acuerdo'] = 'Debes aceptar el acuerdo para continuar.';
            }
            if ((string) ($_POST['palabra_aceptar'] ?? '') !== 'ACEPTAR') {
                $erroresAcuerdo['palabra_aceptar'] = 'Debes escribir la palabra ACEPTAR correctamente.';
            }
            if ($erroresAcuerdo !== []) {
                http_response_code(422);
                view('master_clave', ['admin' => $this->admin, 'errores' => $erroresAcuerdo]);
                return;
            }
        }
        $db = Database::master();
        $db->beginTransaction();
        try {
            $this->saas->cambiarClavePropia($this->admin['id'], $actual, $nueva, $confirmacion);
            if ($forzado) {
                $html = acuerdo_confidencialidad_html();
                $this->saas->auditar($this->admin['id'], 'firma_acuerdo', null, [
                    'texto' => 'Firma digital del Acuerdo de Confidencialidad y Propiedad Intelectual aceptada',
                    'acuerdo_sha256' => hash('sha256', $html),
                ]);
            }
            $db->commit();
        } catch (ValidationException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(422);
            view('master_clave', ['admin' => $this->admin, 'errores' => $e->errors]);
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        session_regenerate_id(true);
        flash('success', $forzado ? 'Clave actualizada y acuerdo firmado.' : 'Clave actualizada.');
        redirect('master');
    }

    public function salir(): void
    {
        $this->saas->auditar($this->admin['id'], 'logout', null, null);
        auth_logout();
        flash('info', 'Sesión del Panel Maestro cerrada.');
        redirect('login');
    }

    /** @param callable(): string $accion */
    private function accionAcceso(callable $accion): never
    {
        try {
            flash('success', $accion());
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
        } catch (PDOException $e) {
            // Último cerrojo: los triggers de adara_master protegen al propietario aunque falle el código.
            error_log('[ADARA][master] ' . $e->getMessage());
            flash('error', 'La base maestra rechazó el cambio.');
        }
        redirect('master');
    }

    /** Entra al ERP de una empresa como su administrador, sin su clave. La sesión del panel se sustituye. */
    public function soporte(): void
    {
        $id = parse_int_range($_POST['id'] ?? '', 1, 4294967295);
        $cliente = $id === null ? null : $this->saas->clientePorId($id);
        if ($cliente === null) {
            flash('error', 'La empresa no existe.');
            redirect('master');
        }
        $base = (string) $cliente['nombre_db_fisica'];
        try {
            $pdo = Database::conectarA($base);
        } catch (DatabaseException) {
            flash('error', 'La base de ' . $cliente['nombre_empresa'] . ' no existe o no responde.');
            redirect('master');
        }
        // El correo guardado en clientes_saas puede no existir todavía en la empresa.
        // En ese caso se entra con el primer administrador activo de esa base.
        $stmt = $pdo->prepare(
            "SELECT id, nombre, email FROM usuarios
             WHERE activo = 1 AND eliminado_en IS NULL AND rol IN ('admin', 'maestro')
             ORDER BY (email = :email) DESC, (rol = 'admin') DESC, id
             LIMIT 1"
        );
        $stmt->execute(['email' => (string) $cliente['correo_admin_cliente']]);
        $adminEmpresa = $stmt->fetch();
        if ($adminEmpresa === false) {
            flash('error', $cliente['nombre_empresa'] . ' no tiene un administrador activo. Crea uno en su tabla usuarios para poder entrar.');
            redirect('master');
        }

        $superId = $this->admin['id'];
        $superNombre = $this->admin['nombre'];
        $this->saas->auditar($superId, 'modo_soporte', (int) $cliente['id'], [
            'texto' => 'El Súper-Admin ' . $superNombre . ' entró en Modo Soporte a la empresa ' . $cliente['nombre_empresa'],
        ]);
        Database::usarEmpresa($base);
        sesion_nueva();
        $_SESSION['uid'] = (int) $adminEmpresa['id'];
        $_SESSION['tenant_id'] = (int) $cliente['id'];
        $_SESSION['db_activa'] = $base;
        $_SESSION['impersonado_por'] = $superId;
        $_SESSION['impersonado_por_nombre'] = $superNombre;
        $_SESSION['auth_at'] = time();
        audit_log('auth', 'modo_soporte', [
            'super_admin_id' => $superId,
            'super_admin' => $superNombre,
            'empresa' => (string) $cliente['nombre_empresa'],
        ]);
        redirect('dashboard');
    }

    /** Vuelve al Panel Maestro. Exige que el super admin que entró siga activo. */
    public function salirSoporte(): void
    {
        $superId = $_SESSION['impersonado_por'] ?? null;
        if (!is_int($superId)) {
            flash('error', 'No estás en modo soporte.');
            redirect('dashboard');
        }
        $admin = $this->saas->superAdminActivo($superId);
        $clienteId = is_int($_SESSION['tenant_id'] ?? null) ? $_SESSION['tenant_id'] : null;
        if ($admin === null) {
            auth_logout();
            flash('error', 'Tu acceso de super admin ya no está vigente.');
            redirect('login');
        }
        $this->saas->auditar($superId, 'modo_soporte_salida', $clienteId, [
            'texto' => 'El Súper-Admin ' . $admin['nombre'] . ' salió del Modo Soporte.',
        ]);
        sesion_nueva();
        $_SESSION['rol_maestro'] = true;
        $_SESSION['super_admin_id'] = $superId;
        $_SESSION['auth_at'] = time();
        flash('info', 'Saliste del modo soporte.');
        redirect('master');
    }

    /** @param array<string, string> $errores @param array<string, string> $form */
    private function render(string $tab, array $errores, array $form): void
    {
        $clave = $_SESSION['_clave_temporal'] ?? null;
        unset($_SESSION['_clave_temporal']);
        $esPropietario = $this->admin['id'] === Saas::PROPIETARIO_ID;
        view('master', [
            'admin'         => $this->admin,
            'tab'           => $tab,
            'esPropietario' => $esPropietario,
            'clientes'      => $this->saas->clientes(),
            'superAdmins'   => $esPropietario ? $this->saas->superAdmins() : [],
            'errores'       => $errores,
            'form'          => $form,
            'claveTemporal' => is_array($clave) ? $clave : null,
        ]);
    }
}
