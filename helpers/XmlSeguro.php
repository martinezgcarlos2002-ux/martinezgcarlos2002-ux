<?php
declare(strict_types=1);

final class XmlRechazado extends RuntimeException
{
}

/**
 * Lectura de XML de libros OOXML (xlsx) subidos por usuarios: sin XXE, sin bombas de entidades y
 * sin bombas ZIP.
 *
 *   - Ninguna parte de un xlsx legítimo lleva DTD: cualquier <!DOCTYPE o <!ENTITY se rechaza antes
 *     de parsear, y otra vez sobre el árbol ya parseado (por si viene ofuscado con otra codificación).
 *   - Solo UTF-8: una declaración encoding="UTF-7"/"UTF-16" permite esconder "<!DOCTYPE" a un filtro
 *     de bytes, así que se rechaza.
 *   - libxml sin LIBXML_NOENT / LIBXML_DTDLOAD / LIBXML_DTDATTR / LIBXML_XINCLUDE (no sustituye
 *     entidades ni carga DTD externos) y con LIBXML_NONET (ningún acceso a red). No agregar esos flags.
 *   - Tamaño descomprimido máximo por parte, verificado en el directorio del ZIP antes de extraer.
 */
final class XmlSeguro
{
    public const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * Contenido de una parte del ZIP, o null si no existe.
     *
     * @throws XmlRechazado si la parte supera MAX_BYTES descomprimida
     */
    public static function desdeZip(ZipArchive $zip, string $ruta): ?string
    {
        $stat = $zip->statName($ruta);
        if ($stat === false) {
            return null;
        }
        if ((int) $stat['size'] > self::MAX_BYTES) {
            throw new XmlRechazado('El Excel es demasiado grande: una de sus partes supera '
                . (int) (self::MAX_BYTES / 1048576) . ' MB sin comprimir.');
        }
        $raw = $zip->getFromName($ruta, self::MAX_BYTES);
        return $raw === false ? null : $raw;
    }

    /** @throws XmlRechazado si el XML trae DTD/entidades, otra codificación o está mal formado */
    public static function cargar(string $raw): SimpleXMLElement
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new XmlRechazado('El XML es demasiado grande.');
        }
        if (str_starts_with($raw, "\xFE\xFF") || str_starts_with($raw, "\xFF\xFE") || str_contains(substr($raw, 0, 4), "\0")) {
            throw new XmlRechazado('El Excel usa una codificación no admitida.');
        }
        if (preg_match('/^\s*(?:\xEF\xBB\xBF)?<\?xml[^>]*\bencoding\s*=\s*["\']([^"\']+)["\']/i', $raw, $m)
            && !in_array(strtolower($m[1]), ['utf-8', 'utf8'], true)) {
            throw new XmlRechazado('El Excel usa una codificación no admitida.');
        }
        if (stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<!ENTITY') !== false) {
            throw new XmlRechazado('El Excel contiene declaraciones XML no permitidas.');
        }

        $previo = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previo);
        }
        if ($xml === false) {
            throw new XmlRechazado('El Excel tiene un XML dañado.');
        }
        $dom = dom_import_simplexml($xml);
        if ($dom->ownerDocument !== null && $dom->ownerDocument->doctype !== null) {
            throw new XmlRechazado('El Excel contiene declaraciones XML no permitidas.');
        }
        return $xml;
    }
}
