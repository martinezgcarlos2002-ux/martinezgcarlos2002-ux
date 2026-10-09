<?php
declare(strict_types=1);

/**
 * Volcado SQL completo con PDO puro (sin mysqldump ni exec).
 *
 * Orden del archivo, pensado para restaurar sin errores:
 *   1. Tablas (DROP + CREATE) con FOREIGN_KEY_CHECKS=0.
 *   2. Datos por lotes. Las columnas generadas (STORED/VIRTUAL) se omiten: MySQL las recalcula.
 *   3. Vistas.
 *   4. Triggers al final: si se crearan antes, los de "semana cerrada" rechazarían los INSERT históricos.
 */
final class RespaldoSql
{
    private const FILAS_POR_INSERT = 200;

    public function __construct(private PDO $db)
    {
    }

    /** @return array{motor:string, tablas:list<string>, vistas:list<string>} */
    public function inventario(): array
    {
        $tablas = [];
        $vistas = [];
        foreach ($this->db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$nombre, $tipo]) {
            if ($tipo === 'VIEW') {
                $vistas[] = $nombre;
            } else {
                $tablas[] = $nombre;
            }
        }
        return [
            'motor'  => (string) $this->db->query('SELECT VERSION()')->fetchColumn(),
            'tablas' => $tablas,
            'vistas' => $vistas,
        ];
    }

    /**
     * Genera el volcado entregando cada fragmento a $emitir (streaming: memoria constante).
     * @param callable(string):void $emitir
     * @return array{tablas:int, filas:int, vistas:int, triggers:int}
     */
    public function generar(callable $emitir): array
    {
        $inv = $this->inventario();
        $base = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();

        $emitir(sprintf(
            "-- ADARA · Respaldo SQL\n-- Base de datos: %s\n-- Motor: %s\n-- Generado: %s\n\n"
            . "SET NAMES utf8mb4;\nSET time_zone = '-04:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n"
            . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSTART TRANSACTION;\n\n",
            $base,
            $inv['motor'],
            date('Y-m-d H:i:s')
        ));

        foreach ($inv['tablas'] as $tabla) {
            $create = $this->db->query('SHOW CREATE TABLE ' . self::id($tabla))->fetch(PDO::FETCH_NUM)[1];
            $emitir("-- ----------------------------------------------------------\n-- Tabla " . self::id($tabla) . "\n"
                . 'DROP TABLE IF EXISTS ' . self::id($tabla) . ";\n" . $create . ";\n\n");
        }

        $filas = 0;
        foreach ($inv['tablas'] as $tabla) {
            $filas += $this->volcarDatos($base, $tabla, $emitir);
        }

        foreach ($inv['vistas'] as $vista) {
            $create = $this->db->query('SHOW CREATE VIEW ' . self::id($vista))->fetch(PDO::FETCH_NUM)[1];
            $create = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/', '', $create);
            $emitir('DROP VIEW IF EXISTS ' . self::id($vista) . ";\n" . $create . ";\n\n");
        }

        $triggers = $this->db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
        if ($triggers !== []) {
            $emitir("-- ----------------------------------------------------------\n-- Triggers\nDELIMITER ;;\n");
            foreach ($triggers as $t) {
                $sql = $this->db->query('SHOW CREATE TRIGGER ' . self::id($t['Trigger']))->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
                $sql = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/', '', $sql);
                $emitir('DROP TRIGGER IF EXISTS ' . self::id($t['Trigger']) . ";;\n" . $sql . ";;\n");
            }
            $emitir("DELIMITER ;\n\n");
        }

        $emitir("COMMIT;\nSET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n-- Fin del respaldo\n");

        return [
            'tablas'   => count($inv['tablas']),
            'filas'    => $filas,
            'vistas'   => count($inv['vistas']),
            'triggers' => count($triggers),
        ];
    }

    private function volcarDatos(string $base, string $tabla, callable $emitir): int
    {
        $stmt = $this->db->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :base AND TABLE_NAME = :tabla AND EXTRA NOT LIKE '%GENERATED%'
             ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute(['base' => $base, 'tabla' => $tabla]);
        $columnas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($columnas === []) {
            return 0;
        }

        $lista = implode(', ', array_map([self::class, 'id'], $columnas));
        $prefijo = 'INSERT INTO ' . self::id($tabla) . " ($lista) VALUES\n";

        // Sin buffer: las filas se leen de a una y la memoria no crece con el tamaño de la tabla.
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $rs = $this->db->query("SELECT $lista FROM " . self::id($tabla));
            $lote = [];
            $total = 0;
            while (($row = $rs->fetch(PDO::FETCH_NUM)) !== false) {
                $lote[] = '(' . implode(', ', array_map([$this, 'valor'], $row)) . ')';
                if (count($lote) === self::FILAS_POR_INSERT) {
                    $emitir($prefijo . implode(",\n", $lote) . ";\n");
                    $total += count($lote);
                    $lote = [];
                }
            }
            if ($lote !== []) {
                $emitir($prefijo . implode(",\n", $lote) . ";\n");
                $total += count($lote);
            }
            $rs->closeCursor();
        } finally {
            $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        if ($total > 0) {
            $emitir("\n");
        }
        return $total;
    }

    private function valor(mixed $v): string
    {
        return match (true) {
            $v === null   => 'NULL',
            is_int($v)    => (string) $v,
            is_float($v)  => var_export($v, true),
            default       => $this->db->quote((string) $v),
        };
    }

    private static function id(string $nombre): string
    {
        return '`' . str_replace('`', '``', $nombre) . '`';
    }
}
