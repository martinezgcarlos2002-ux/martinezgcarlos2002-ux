<?php
declare(strict_types=1);

/**
 * Validación y saneamiento de imágenes subidas por usuarios (logos, sellos, fotos de ficha).
 *
 *   1. El nombre que manda el navegador nunca se usa: ni extensión ni ruta (sin doble extensión
 *      "shell.php.jpg" ni null byte). La extensión sale del tipo detectado.
 *   2. Tipo por contenido con dos parsers independientes (libmagic y getimagesize) que deben coincidir.
 *   3. Límite de dimensiones leído de la cabecera, antes de decodificar (bomba de descompresión).
 *   4. Re-codificación con GD desde los píxeles: EXIF, comentarios, chunks extra y datos añadidos al
 *      final (polyglots JPEG/PHP, GIFAR) no llegan al archivo final.
 * Un payload diseñado para sobrevivir a GD sigue siendo posible: la carpeta destino tiene que tener
 * la ejecución deshabilitada (assets/.htaccess).
 */
final class ImagenSegura
{
    public const MAX_LADO = 5000;
    public const MAX_PIXELES = 20_000_000;

    /** @var array<string, array{0:int, 1:string}> MIME => [IMAGETYPE_*, extensión] */
    private const TIPOS = [
        'image/jpeg' => [IMAGETYPE_JPEG, 'jpg'],
        'image/png'  => [IMAGETYPE_PNG, 'png'],
        'image/webp' => [IMAGETYPE_WEBP, 'webp'],
    ];

    /**
     * @param list<string> $permitidos subconjunto de image/jpeg, image/png, image/webp
     * @return array{mime:string, ext:string}
     * @throws InvalidArgumentException con un mensaje apto para el usuario
     */
    public static function inspeccionar(string $tmp, array $permitidos): array
    {
        $nombres = array_map(static fn (string $m): string => strtoupper(self::TIPOS[$m][1] ?? $m), $permitidos);
        $ultimo = array_pop($nombres);
        $formatos = 'Solo se aceptan imágenes ' . ($nombres === [] ? $ultimo : implode(', ', $nombres) . ' o ' . $ultimo) . '.';

        try {
            $mime = is_file($tmp) ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : '';
        } catch (ErrorException) {
            // Archivo bloqueado (p. ej. por el antivirus) o ilegible: no exponer la ruta del servidor.
            $mime = '';
        }
        if (!in_array($mime, $permitidos, true) || !isset(self::TIPOS[$mime])) {
            throw new InvalidArgumentException($formatos);
        }
        try {
            $info = getimagesize($tmp);
        } catch (ErrorException) {
            $info = false;
        }
        if ($info === false || $info[2] !== self::TIPOS[$mime][0]) {
            throw new InvalidArgumentException('El archivo no es una imagen válida. ' . $formatos);
        }
        [$ancho, $alto] = $info;
        if ($ancho < 1 || $alto < 1 || $ancho > self::MAX_LADO || $alto > self::MAX_LADO || $ancho * $alto > self::MAX_PIXELES) {
            throw new InvalidArgumentException('La imagen puede medir hasta ' . self::MAX_LADO . ' × ' . self::MAX_LADO . ' píxeles.');
        }
        return ['mime' => $mime, 'ext' => self::TIPOS[$mime][1]];
    }

    /**
     * Decodifica $tmp y escribe una imagen nueva en $destino. Usar solo después de inspeccionar().
     *
     * @throws RuntimeException si la imagen está dañada o no se pudo escribir
     */
    public static function guardar(string $tmp, string $mime, string $destino): void
    {
        try {
            $img = match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($tmp),
                'image/png'  => imagecreatefrompng($tmp),
                'image/webp' => imagecreatefromwebp($tmp),
            };
        } catch (ErrorException | UnhandledMatchError) {
            $img = false;
        }
        if ($img === false) {
            throw new RuntimeException('La imagen está dañada o no se pudo leer.');
        }

        $parcial = $destino . '.' . bin2hex(random_bytes(4)) . '.part';
        try {
            if ($mime !== 'image/jpeg') {
                imagesavealpha($img, true);
            }
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($img, $parcial, 88),
                'image/png'  => imagepng($img, $parcial, 6),
                'image/webp' => imagewebp($img, $parcial, 85),
            };
            if (!$ok || !rename($parcial, $destino)) {
                throw new RuntimeException('No se pudo guardar la imagen.');
            }
            chmod($destino, 0644);
        } catch (ErrorException $e) {
            throw new RuntimeException('No se pudo guardar la imagen.', 0, $e);
        } finally {
            if (is_file($parcial)) {
                unlink($parcial);
            }
        }
    }
}
