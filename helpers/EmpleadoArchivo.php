<?php
declare(strict_types=1);

/** Subida de fotos de ficha (JPG/PNG). Rutas relativas a ADARA_ROOT. */
final class EmpleadoArchivo
{
    public const DIR_REL = 'public/uploads/empleados';
    public const MAX = 3145728;
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    /**
     * @param array<string, mixed> $files $_FILES
     * @return array{foto_perfil:?array, foto_documento:?array}
     */
    public static function leer(array $files): array
    {
        return [
            'foto_perfil'    => self::uno($files['foto_perfil'] ?? null, 'foto_perfil'),
            'foto_documento' => self::uno($files['foto_documento'] ?? null, 'foto_documento'),
        ];
    }

    /**
     * @param array{foto_perfil:?array, foto_documento:?array} $pendientes
     * @return array<string, string> campo => ruta relativa
     */
    public static function guardar(int $empleadoId, array $pendientes): array
    {
        $dir = ADARA_ROOT . '/' . self::DIR_REL;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ValidationException(['foto_perfil' => 'No se pudo crear la carpeta de fotos.']);
        }
        $out = [];
        foreach (['foto_perfil', 'foto_documento'] as $campo) {
            $f = $pendientes[$campo] ?? null;
            if ($f === null) {
                continue;
            }
            $ext = self::MIMES[$f['mime']];
            $nombre = $empleadoId . '-' . $campo . '-' . str_replace('.', '', uniqid('', true)) . '.' . $ext;
            $rel = self::DIR_REL . '/' . $nombre;
            $dest = ADARA_ROOT . '/' . $rel;
            if (!move_uploaded_file($f['tmp'], $dest)) {
                throw new ValidationException([$campo => 'No se pudo guardar la imagen.']);
            }
            $out[$campo] = $rel;
        }
        return $out;
    }

    public static function borrar(?string $rel): void
    {
        if ($rel === null || $rel === '' || !str_starts_with($rel, self::DIR_REL . '/')) {
            return;
        }
        $abs = ADARA_ROOT . '/' . $rel;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    public static function url(?string $rel): string
    {
        if ($rel === null || $rel === '') {
            return '';
        }
        return base_path() . ltrim($rel, '/');
    }

    public static function dataUri(?string $rel): string
    {
        $abs = self::absoluto($rel);
        if ($abs === '') {
            return '';
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($abs);
        if (!isset(self::MIMES[$mime])) {
            return '';
        }
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($abs));
    }

    public static function absoluto(?string $rel): string
    {
        if ($rel === null || $rel === '') {
            return '';
        }
        $abs = ADARA_ROOT . '/' . $rel;
        return is_file($abs) ? $abs : '';
    }

    /** @param mixed $archivo */
    private static function uno(mixed $archivo, string $campo): ?array
    {
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new ValidationException([$campo => 'No se recibió la imagen.']);
        }
        if (($archivo['size'] ?? 0) <= 0 || $archivo['size'] > self::MAX) {
            throw new ValidationException([$campo => 'La imagen debe pesar entre 1 byte y 3 MB.']);
        }
        $tmp = (string) $archivo['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            throw new ValidationException([$campo => 'Archivo de carga inválido.']);
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::MIMES[$mime])) {
            throw new ValidationException([$campo => 'Solo se aceptan imágenes JPG o PNG.']);
        }
        return ['tmp' => $tmp, 'mime' => $mime];
    }
}
