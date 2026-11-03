<?php
declare(strict_types=1);

/**
 * Divide un volcado .sql en sentencias ejecutables una a una con PDO::exec().
 *
 * PDO no entiende la directiva DELIMITER del cliente mysql (triggers, procedimientos y funciones),
 * así que aquí se interpreta: respeta comillas simples/dobles con escapes, backticks, comentarios
 * "-- ", "#" y "/* *\/" (que se descartan) y conserva los comentarios ejecutables "/*! … *\/" de
 * mysqldump, que el servidor sí interpreta.
 */
final class SqlScript
{
    /** @return list<string> */
    public static function sentencias(string $sql): array
    {
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }
        $sql = str_replace("\r\n", "\n", $sql);
        $n = strlen($sql);
        $delimitador = ';';
        $actual = '';
        $salida = [];
        $inicioLinea = true;

        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];

            if ($inicioLinea) {
                $fin = strpos($sql, "\n", $i);
                $linea = substr($sql, $i, $fin === false ? null : $fin - $i);
                if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $linea, $m)) {
                    self::cerrar($actual, $salida);
                    $delimitador = $m[1];
                    $i = $fin === false ? $n : $fin;
                    continue;
                }
            }
            $inicioLinea = false;

            if ($c === "'" || $c === '"' || $c === '`') {
                $j = self::finCita($sql, $i, $c);
                $actual .= substr($sql, $i, $j - $i + 1);
                $i = $j;
                continue;
            }
            if ($c === '#' || ($c === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $n || ctype_space($sql[$i + 2])))) {
                $fin = strpos($sql, "\n", $i);
                $i = ($fin === false ? $n : $fin) - 1;
                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $fin = strpos($sql, '*/', $i + 2);
                $fin = $fin === false ? $n - 2 : $fin;
                if (($sql[$i + 2] ?? '') === '!') {
                    $actual .= substr($sql, $i, $fin + 2 - $i);
                }
                $i = $fin + 1;
                continue;
            }
            if (substr_compare($sql, $delimitador, $i, strlen($delimitador)) === 0) {
                self::cerrar($actual, $salida);
                $i += strlen($delimitador) - 1;
                continue;
            }
            $actual .= $c;
            if ($c === "\n") {
                $inicioLinea = true;
            }
        }
        self::cerrar($actual, $salida);
        return $salida;
    }

    /** Índice de la comilla de cierre; acepta \x y la comilla duplicada ('' dentro de '…'). */
    private static function finCita(string $sql, int $inicio, string $comilla): int
    {
        $n = strlen($sql);
        for ($j = $inicio + 1; $j < $n; $j++) {
            if ($sql[$j] === '\\' && $comilla !== '`') {
                $j++;
                continue;
            }
            if ($sql[$j] === $comilla) {
                if (($sql[$j + 1] ?? '') === $comilla) {
                    $j++;
                    continue;
                }
                return $j;
            }
        }
        throw new RuntimeException('Cadena sin cerrar en el script SQL.');
    }

    /** @param list<string> $salida */
    private static function cerrar(string &$actual, array &$salida): void
    {
        $sentencia = trim($actual);
        $actual = '';
        if ($sentencia !== '') {
            $salida[] = $sentencia;
        }
    }
}
