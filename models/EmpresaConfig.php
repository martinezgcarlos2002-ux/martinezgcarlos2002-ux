<?php
declare(strict_types=1);

/**
 * Marca blanca. Siempre hay una fila id = 1.
 * Si la migración 048 todavía no corrió, se usan los datos históricos del ERP.
 */
final class EmpresaConfig
{
    private const DIR = 'img/marca';
    private const MAX_BYTES = 2_000_000;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private PDO $db)
    {
    }

    /** @return array{nombre_empresa:string,rif_documento:string,telefono:string,direccion:string,color_primario:string,color_secundario:string,ruta_logo:string,ruta_sello:string,fondo_login:string} */
    public static function actual(PDO $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $base = 'SELECT nombre_empresa, rif_documento, telefono, direccion, color_primario, color_secundario, ruta_logo, ruta_sello';
        try {
            $row = $db->query($base . ', fondo_login FROM empresa_configuracion WHERE id = 1')->fetch();
        } catch (PDOException $e) {
            $row = false;
            // 1054: la migración 059 todavía no corrió. El resto de la marca sigue leyéndose.
            if ((int) ($e->errorInfo[1] ?? 0) === 1054) {
                try {
                    $row = $db->query($base . ' FROM empresa_configuracion WHERE id = 1')->fetch();
                    if (is_array($row)) {
                        $row['fondo_login'] = '';
                    }
                } catch (PDOException) {
                    $row = false;
                }
            }
        }
        $cache = is_array($row) ? self::normalizar($row) : self::defecto();
        return $cache;
    }

    public static function olvidarCache(): void
    {
        // El cache es estático por petición. Un guardado redirige y la siguiente carga lee de nuevo.
    }

    /** @return array{nombre_empresa:string,rif_documento:string,telefono:string,direccion:string,color_primario:string,color_secundario:string,ruta_logo:string,ruta_sello:string,fondo_login:string} */
    public static function defecto(): array
    {
        if (Database::multiempresa()) {
            // Sin empresa elegida (login del dominio principal): marca ADARA, nunca la de un cliente.
            return [
                'nombre_empresa' => APP_NAME . ' ERP',
                'rif_documento' => '',
                'telefono' => '',
                'direccion' => '',
                'color_primario' => '#F97316',
                'color_secundario' => '#1A2E5A',
                'ruta_logo' => 'img/ADARA LOGO.jpg',
                'ruta_sello' => '',
                'fondo_login' => '',
            ];
        }
        return [
            'nombre_empresa' => defined('APP_EMPRESA') ? APP_EMPRESA : 'El Castillo del Queso Artesanal F.P.',
            'rif_documento' => defined('APP_RIF') ? APP_RIF : 'V-286013356',
            'telefono' => '',
            'direccion' => '',
            'color_primario' => '#F97316',
            'color_secundario' => '#1A2E5A',
            'ruta_logo' => 'img/logo.jpg',
            'ruta_sello' => '',
            'fondo_login' => '',
        ];
    }

    /**
     * Variables CSS para el atributo style del <html>.
     * La CSP permite style="" y bloquea un bloque <style> suelto.
     */
    /** Marca de la empresa de la petición; en el login multiempresa (sin empresa aún), la marca ADARA. */
    public static function vigente(): array
    {
        try {
            $db = get_db();
        } catch (SinEmpresaException) {
            return self::defecto();
        }
        return self::actual($db);
    }

    public static function estilo(): string
    {
        $m = self::vigente();
        $primario = $m['color_primario'];
        $secundario = $m['color_secundario'];
        return '--main-color:' . $primario
            . ';--secondary-color:' . $secundario
            . ';--color-primario:' . $primario
            . ';--color-secundario:' . $secundario
            . ';--orange:' . $primario
            . ';--orange-hover:' . self::oscurecer($primario)
            . ';--navy:' . $secundario
            . ';--navy-dark:' . self::oscurecer($secundario);
    }

    /**
     * @param array{nombre_empresa:string,rif_documento:string,telefono:string,direccion:string,color_primario:string,color_secundario:string} $datos
     * @param array{logo?:array<string,mixed>,sello?:array<string,mixed>,fondo?:array<string,mixed>} $archivos
     */
    public function guardar(array $datos, array $archivos): void
    {
        $actual = self::actual($this->db);
        $nombre = self::textoPlano($datos['nombre_empresa']);
        $rif = strtoupper(str_replace(' ', '', self::textoPlano($datos['rif_documento'])));
        $telefono = self::textoPlano($datos['telefono']);
        $direccion = self::textoPlano($datos['direccion']);
        $primario = strtoupper(trim($datos['color_primario']));
        $secundario = strtoupper(trim($datos['color_secundario']));
        $errores = [];
        if ($nombre === '' || mb_strlen($nombre) > 120) {
            $errores['nombre_empresa'] = 'El nombre de la empresa es obligatorio (hasta 120 caracteres).';
        }
        if ($rif === '' || mb_strlen($rif) > 20) {
            $errores['rif_documento'] = 'Indica el RIF o documento.';
        } elseif (!preg_match('/^[A-Z0-9][A-Z0-9.\/\-]{1,19}$/', $rif)) {
            $errores['rif_documento'] = 'El RIF solo admite letras, números, punto, barra y guion. Ej.: J-12345678-9.';
        }
        if (mb_strlen($telefono) > 30) {
            $errores['telefono'] = 'El teléfono es demasiado largo.';
        } elseif (!preg_match('/^[0-9+()\-. \/]*$/', $telefono)) {
            $errores['telefono'] = 'El teléfono solo admite números, espacios y + ( ) - . /';
        }
        if (mb_strlen($direccion) > 180) {
            $errores['direccion'] = 'La dirección es demasiado larga.';
        }
        if (!self::esColor($primario)) {
            $errores['color_primario'] = 'El color primario tiene que ser un hexadecimal, por ejemplo #F97316.';
        }
        if (!self::esColor($secundario)) {
            $errores['color_secundario'] = 'El color secundario tiene que ser un hexadecimal, por ejemplo #1A2E5A.';
        }
        $logo = $this->recibirImagen($archivos['logo'] ?? null, 'logo', $errores);
        $sello = $this->recibirImagen($archivos['sello'] ?? null, 'sello', $errores);
        $fondo = $this->recibirImagen($archivos['fondo'] ?? null, 'fondo', $errores);
        if ($errores !== []) {
            if ($logo !== null) {
                self::borrarMarca($logo);
            }
            if ($sello !== null) {
                self::borrarMarca($sello);
            }
            if ($fondo !== null) {
                self::borrarMarca($fondo);
            }
            throw new ValidationException($errores);
        }

        $rutaLogo = $actual['ruta_logo'];
        $rutaSello = $actual['ruta_sello'];
        $rutaFondo = $actual['fondo_login'];
        if ($logo !== null) {
            $rutaLogo = $logo;
        }
        if ($sello !== null) {
            $rutaSello = $sello;
        }
        if ($fondo !== null) {
            $rutaFondo = $fondo;
        }

        try {
            $this->db->prepare(
                'INSERT INTO empresa_configuracion
                    (id, nombre_empresa, rif_documento, telefono, direccion, color_primario, color_secundario, ruta_logo, ruta_sello, fondo_login)
                 VALUES (1, :nombre, :rif, :telefono, :direccion, :primario, :secundario, :logo, :sello, :fondo)
                 ON DUPLICATE KEY UPDATE
                    nombre_empresa = VALUES(nombre_empresa),
                    rif_documento = VALUES(rif_documento),
                    telefono = VALUES(telefono),
                    direccion = VALUES(direccion),
                    color_primario = VALUES(color_primario),
                    color_secundario = VALUES(color_secundario),
                    ruta_logo = VALUES(ruta_logo),
                    ruta_sello = VALUES(ruta_sello),
                    fondo_login = VALUES(fondo_login)'
            )->execute([
                'nombre' => $nombre,
                'rif' => $rif,
                'telefono' => $telefono,
                'direccion' => $direccion,
                'primario' => $primario,
                'secundario' => $secundario,
                'logo' => $rutaLogo !== '' ? $rutaLogo : null,
                'sello' => $rutaSello !== '' ? $rutaSello : null,
                'fondo' => $rutaFondo !== '' ? $rutaFondo : null,
            ]);
        } catch (PDOException $e) {
            $sinColumna = (int) ($e->errorInfo[1] ?? 0) === 1054;
            if ($sinColumna && $fondo === null) {
                $this->db->prepare(
                    'INSERT INTO empresa_configuracion
                        (id, nombre_empresa, rif_documento, telefono, direccion, color_primario, color_secundario, ruta_logo, ruta_sello)
                     VALUES (1, :nombre, :rif, :telefono, :direccion, :primario, :secundario, :logo, :sello)
                     ON DUPLICATE KEY UPDATE
                        nombre_empresa = VALUES(nombre_empresa),
                        rif_documento = VALUES(rif_documento),
                        telefono = VALUES(telefono),
                        direccion = VALUES(direccion),
                        color_primario = VALUES(color_primario),
                        color_secundario = VALUES(color_secundario),
                        ruta_logo = VALUES(ruta_logo),
                        ruta_sello = VALUES(ruta_sello)'
                )->execute([
                    'nombre' => $nombre,
                    'rif' => $rif,
                    'telefono' => $telefono,
                    'direccion' => $direccion,
                    'primario' => $primario,
                    'secundario' => $secundario,
                    'logo' => $rutaLogo !== '' ? $rutaLogo : null,
                    'sello' => $rutaSello !== '' ? $rutaSello : null,
                ]);
            } else {
                if ($logo !== null) {
                    self::borrarMarca($logo);
                }
                if ($sello !== null) {
                    self::borrarMarca($sello);
                }
                if ($fondo !== null) {
                    self::borrarMarca($fondo);
                }
                if ($sinColumna) {
                    throw new ValidationException([
                        'fondo' => 'Esta base todavía no tiene el campo fondo_login. Aplica sql/059_fondo_login.sql.',
                    ]);
                }
                throw $e;
            }
        }

        if ($logo !== null) {
            self::borrarMarca($actual['ruta_logo']);
        }
        if ($sello !== null) {
            self::borrarMarca($actual['ruta_sello']);
        }
        if ($fondo !== null) {
            self::borrarMarca($actual['fondo_login']);
        }
    }

    /** @param array<string, mixed>|null $archivo @param array<string, string> $errores */
    private function recibirImagen(?array $archivo, string $campo, array &$errores): ?string
    {
        if ($archivo === null || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errores[$campo] = 'No se pudo recibir la imagen.';
            return null;
        }
        $tamano = (int) ($archivo['size'] ?? 0);
        if ($tamano <= 0 || $tamano > self::MAX_BYTES) {
            $errores[$campo] = 'La imagen debe pesar hasta 2 MB.';
            return null;
        }
        $tmp = (string) ($archivo['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            $errores[$campo] = 'La carga de la imagen no es válida.';
            return null;
        }
        try {
            $tipo = ImagenSegura::inspeccionar($tmp, self::MIMES);
        } catch (InvalidArgumentException $e) {
            $errores[$campo] = $e->getMessage();
            return null;
        }
        $dir = ADARA_ROOT . '/assets/' . self::DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $errores[$campo] = 'No se pudo crear la carpeta de la marca.';
            return null;
        }
        $rel = self::DIR . '/' . $campo . '-' . bin2hex(random_bytes(16)) . '.' . $tipo['ext'];
        try {
            ImagenSegura::guardar($tmp, $tipo['mime'], ADARA_ROOT . '/assets/' . $rel);
        } catch (RuntimeException $e) {
            $errores[$campo] = $e->getMessage();
            return null;
        }
        return $rel;
    }

    private static function borrarMarca(string $ruta): void
    {
        if (!str_starts_with($ruta, self::DIR . '/')) {
            return;
        }
        $abs = ADARA_ROOT . '/assets/' . $ruta;
        if (is_file($abs)) {
            unlink($abs);
        }
    }

    /** @param array<string, mixed> $row */
    private static function normalizar(array $row): array
    {
        $logo = trim((string) ($row['ruta_logo'] ?? ''));
        $sello = trim((string) ($row['ruta_sello'] ?? ''));
        $fondo = str_replace('\\', '/', trim((string) ($row['fondo_login'] ?? '')));
        $primario = strtoupper(trim((string) ($row['color_primario'] ?? '')));
        $secundario = strtoupper(trim((string) ($row['color_secundario'] ?? '')));
        if ($fondo !== '' && (!str_starts_with($fondo, self::DIR . '/') || str_contains($fondo, '..'))) {
            $fondo = '';
        }
        return [
            'nombre_empresa' => (string) ($row['nombre_empresa'] ?? self::defecto()['nombre_empresa']),
            'rif_documento' => (string) ($row['rif_documento'] ?? ''),
            'telefono' => (string) ($row['telefono'] ?? ''),
            'direccion' => (string) ($row['direccion'] ?? ''),
            'color_primario' => self::esColor($primario) ? $primario : '#F97316',
            'color_secundario' => self::esColor($secundario) ? $secundario : '#1A2E5A',
            'ruta_logo' => $logo !== '' ? $logo : 'img/logo.jpg',
            'ruta_sello' => $sello,
            'fondo_login' => $fondo,
        ];
    }

    /**
     * Una línea de texto sin caracteres de control (\p{Cc}) ni de formato (\p{Cf}: bidi U+202A–U+202E,
     * U+2066–U+2069, ancho cero). Esos caracteres no son HTML y e() no los toca, pero invierten o
     * esconden texto en facturas y PDF (RIF falsificado a la vista). Acentos y ñ se conservan.
     * UTF-8 inválido devuelve '' (preg_* con /u falla), y el campo cae en "obligatorio".
     */
    private static function textoPlano(string $valor): string
    {
        $limpio = preg_replace(['/\s+/u', '/[\p{Cc}\p{Cf}]+/u'], [' ', ''], $valor);
        return trim($limpio ?? '');
    }

    public static function esColor(string $hex): bool
    {
        return (bool) preg_match('/^#[0-9A-F]{6}$/', $hex);
    }

    public static function oscurecer(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r = max(0, (int) round(hexdec(substr($hex, 0, 2)) * 0.82));
        $g = max(0, (int) round(hexdec(substr($hex, 2, 2)) * 0.82));
        $b = max(0, (int) round(hexdec(substr($hex, 4, 2)) * 0.82));
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }
}

/** @return array{nombre_empresa:string,rif_documento:string,telefono:string,direccion:string,color_primario:string,color_secundario:string,ruta_logo:string,ruta_sello:string,fondo_login:string} */
function marca(): array
{
    return EmpresaConfig::vigente();
}

/** URL del fondo de login de la empresa resuelta, o el fondo estándar de ADARA. */
function fondo_login_src(): string
{
    $ruta = marca()['fondo_login'];
    $abs = $ruta === '' ? '' : marca_archivo($ruta);
    if ($abs === '' || !is_file($abs)) {
        return asset('img/fondo_empresa.jpg');
    }
    return asset_v($ruta);
}

/** Atributo style del <body> del login. Ya va escapado. */
function fondo_login_estilo(): string
{
    return "background-image:url('" . e(fondo_login_src()) . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
}

function marca_logo_src(): string
{
    return asset_v(marca()['ruta_logo']);
}

function marca_archivo(string $rel): string
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
        return '';
    }
    return ADARA_ROOT . '/assets/' . $rel;
}

/** RIF, teléfono y dirección en una sola línea. */
function marca_datos(): string
{
    $m = marca();
    $partes = [];
    if ($m['rif_documento'] !== '') {
        $partes[] = 'RIF: ' . $m['rif_documento'];
    }
    if ($m['telefono'] !== '') {
        $partes[] = 'Tel: ' . $m['telefono'];
    }
    if ($m['direccion'] !== '') {
        $partes[] = $m['direccion'];
    }
    return implode(' · ', $partes);
}

/** Sello subido, o el logo con el nombre si todavía no hay sello. */
function marca_sello_html(): string
{
    $m = marca();
    if ($m['ruta_sello'] !== '') {
        return '<div class="sello sello-foto"><img src="' . e(asset_v($m['ruta_sello'])) . '" alt="Sello de ' . e($m['nombre_empresa']) . '" class="sello-foto-img"></div>';
    }
    return '<div class="sello"><img src="' . e(marca_logo_src()) . '" alt="" class="sello-img">'
        . '<div class="sello-txt">Sello</div><div class="sello-txt">' . e($m['nombre_empresa']) . '</div></div>';
}

function marca_data_uri(string $rel): string
{
    $path = marca_archivo($rel);
    if ($path === '' || !is_file($path)) {
        return '';
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!is_string($mime) || !str_starts_with($mime, 'image/')) {
        return '';
    }
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
}
