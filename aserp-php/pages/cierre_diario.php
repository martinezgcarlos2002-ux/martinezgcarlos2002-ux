<?php
require_login();
if (!has_permiso('cierre_diario')) {
    flash('Sin permisos para descargar el cierre diario.', 'error');
    header('Location: ?p=dashboard'); exit;
}

$db     = get_db();
$action = $_GET['action'] ?? '';

/* ============================================================
 *  Helpers — descubrir tablas y columnas en MySQL o Postgres
 * ========================================================== */

function bk_listar_tablas(PDO $db): array {
    if (is_mysql()) {
        $rows = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
        return array_map(fn($r) => $r[0], $rows);
    }
    $rows = $db->query(
        "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"
    )->fetchAll(PDO::FETCH_NUM);
    return array_map(fn($r) => $r[0], $rows);
}

/**
 * Devuelve [ ['name','type_mysql','nullable','default','is_auto'], ... ] para MySQL.
 * En MySQL usamos SHOW CREATE TABLE directamente más abajo.
 */
function bk_columnas_pg(PDO $db, string $tabla): array {
    $st = $db->prepare("
        SELECT column_name, data_type, character_maximum_length,
               numeric_precision, numeric_scale, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema='public' AND table_name=?
        ORDER BY ordinal_position
    ");
    $st->execute([$tabla]);
    $cols = [];
    foreach ($st->fetchAll() as $r) {
        $type = strtolower($r['data_type']);
        $len  = $r['character_maximum_length'];
        $prec = $r['numeric_precision'];
        $scale= $r['numeric_scale'];
        $def  = $r['column_default'];
        $is_auto = is_string($def) && str_contains($def, 'nextval(');

        $mysql_type = bk_map_tipo_pg_mysql($type, $len, $prec, $scale);
        $cols[] = [
            'name'     => $r['column_name'],
            'type'     => $mysql_type,
            'nullable' => $r['is_nullable'] === 'YES',
            'default'  => $def,
            'is_auto'  => $is_auto,
        ];
    }
    return $cols;
}

function bk_map_tipo_pg_mysql(string $t, $len, $prec, $scale): string {
    switch ($t) {
        case 'integer': case 'int4':                    return 'INT';
        case 'bigint': case 'int8':                     return 'BIGINT';
        case 'smallint': case 'int2':                   return 'SMALLINT';
        case 'boolean': case 'bool':                    return 'TINYINT(1)';
        case 'text':                                    return 'TEXT';
        case 'character varying': case 'varchar':
            return 'VARCHAR(' . ($len ?: 255) . ')';
        case 'character': case 'char':
            return 'CHAR(' . ($len ?: 1) . ')';
        case 'numeric': case 'decimal':
            if ($prec) return 'DECIMAL(' . $prec . ',' . ((int)$scale) . ')';
            return 'DECIMAL(18,4)';
        case 'real':           return 'FLOAT';
        case 'double precision':return 'DOUBLE';
        case 'date':           return 'DATE';
        case 'time': case 'time without time zone': case 'time with time zone':
            return 'TIME';
        case 'timestamp': case 'timestamp without time zone':
        case 'timestamp with time zone':
            return 'DATETIME';
        case 'json': case 'jsonb':                      return 'LONGTEXT';
        case 'bytea':                                   return 'LONGBLOB';
        case 'uuid':                                    return 'VARCHAR(36)';
    }
    return 'TEXT';
}

/**
 * Detecta clave primaria de una tabla en Postgres.
 */
function bk_pk_pg(PDO $db, string $tabla): array {
    $st = $db->prepare("
        SELECT a.attname
        FROM   pg_index i
        JOIN   pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
        WHERE  i.indrelid = ?::regclass AND i.indisprimary
        ORDER  BY array_position(i.indkey, a.attnum)
    ");
    try { $st->execute(['public.' . $tabla]); }
    catch (Throwable $e) { return []; }
    return array_map(fn($r) => $r[0], $st->fetchAll(PDO::FETCH_NUM));
}

/**
 * Construye CREATE TABLE en dialecto MySQL desde introspección Postgres.
 */
function bk_create_table_desde_pg(PDO $db, string $tabla): string {
    $cols = bk_columnas_pg($db, $tabla);
    $pk   = bk_pk_pg($db, $tabla);
    $lineas = [];
    foreach ($cols as $c) {
        $linea = '  `' . $c['name'] . '` ' . $c['type'];
        if ($c['is_auto']) {
            $linea .= ' NOT NULL AUTO_INCREMENT';
        } else {
            $linea .= $c['nullable'] ? ' NULL' : ' NOT NULL';
            // Default value (sólo casos seguros)
            $def = $c['default'];
            if ($def !== null && !str_contains($def, 'nextval(')) {
                $def_clean = preg_replace('/::[a-zA-Z _]+(\([^)]*\))?$/', '', $def);
                $def_clean = trim($def_clean);
                if ($def_clean === 'true')   $linea .= ' DEFAULT 1';
                elseif ($def_clean === 'false') $linea .= ' DEFAULT 0';
                elseif (in_array(strtoupper($def_clean), ['CURRENT_TIMESTAMP','NOW()'], true))
                    $linea .= ' DEFAULT CURRENT_TIMESTAMP';
                elseif (is_numeric($def_clean)) $linea .= ' DEFAULT ' . $def_clean;
                elseif (preg_match("/^'(.*)'$/s", $def_clean, $m))
                    $linea .= " DEFAULT '" . str_replace("'", "''", $m[1]) . "'";
            }
        }
        $lineas[] = $linea;
    }
    if ($pk) {
        $pk_cols = implode('`,`', $pk);
        $lineas[] = '  PRIMARY KEY (`' . $pk_cols . '`)';
    }
    return "CREATE TABLE `$tabla` (\n" . implode(",\n", $lineas)
         . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
}

/**
 * Devuelve CREATE TABLE original desde MySQL (más fiel).
 */
function bk_create_table_desde_mysql(PDO $db, string $tabla): string {
    $row = $db->query("SHOW CREATE TABLE `$tabla`")->fetch(PDO::FETCH_ASSOC);
    return $row ? ($row['Create Table'] ?? $row['Create View'] ?? '') . ';' : '';
}

/**
 * Escapa un valor para una sentencia INSERT MySQL.
 */
function bk_escapar($v): string {
    if ($v === null) return 'NULL';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_int($v) || is_float($v)) return (string)$v;
    // Postgres devuelve booleanos como 't'/'f' a veces
    if ($v === 't') return '1';
    if ($v === 'f') return '0';
    $s = (string)$v;
    // Escape MySQL: \ ' " \0 \n \r \x1a
    $s = str_replace(
        ['\\',   "\0",  "\n",  "\r",  "'",   '"',   "\x1a"],
        ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
        $s
    );
    return "'$s'";
}

/* ============================================================
 *  Descarga: genera el .sql y lo envía como attachment
 * ========================================================== */

if ($action === 'descargar') {
    $fecha     = date('Y-m-d');
    $hora      = date('H-i-s');
    $filename  = "cierre_{$fecha}_{$hora}.sql";

    // Sin buffering, tipo de contenido SQL
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $is_mysql = is_mysql();
    $tablas   = bk_listar_tablas($db);

    echo "-- =====================================================\n";
    echo "-- ASERP - El Castillo del Queso\n";
    echo "-- CIERRE DIARIO - Respaldo completo del sistema\n";
    echo "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Motor origen: " . ($is_mysql ? 'MySQL' : 'PostgreSQL') . "\n";
    echo "-- Total tablas: " . count($tablas) . "\n";
    echo "-- =====================================================\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n";
    echo "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach ($tablas as $tabla) {
        echo "-- -----------------------------------------------------\n";
        echo "-- Tabla: $tabla\n";
        echo "-- -----------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `$tabla`;\n";

        try {
            $create = $is_mysql
                ? bk_create_table_desde_mysql($db, $tabla)
                : bk_create_table_desde_pg($db, $tabla);
        } catch (Throwable $e) {
            echo "-- ERROR al crear definición: " . $e->getMessage() . "\n\n";
            continue;
        }
        echo $create . "\n\n";

        // Volcado de datos
        try {
            $count = (int)$db->query("SELECT COUNT(*) FROM " . ($is_mysql ? "`$tabla`" : "\"$tabla\""))->fetchColumn();
        } catch (Throwable $e) { $count = 0; }

        if ($count === 0) {
            echo "-- (sin datos)\n\n";
            continue;
        }

        echo "-- Datos: $count fila(s)\n";

        // Obtener nombres de columnas (1 fila para descubrir keys)
        $sql_sel = $is_mysql ? "SELECT * FROM `$tabla`" : "SELECT * FROM \"$tabla\"";
        $stmt    = $db->query($sql_sel);
        $cols    = null;
        $batch   = [];
        $BATCH_SIZE = 200;

        $flush = function() use (&$batch, $tabla, &$cols) {
            if (!$batch) return;
            $col_list = '`' . implode('`,`', $cols) . '`';
            echo "INSERT INTO `$tabla` ($col_list) VALUES\n";
            echo implode(",\n", $batch) . ";\n";
            $batch = [];
            if (function_exists('ob_flush')) @ob_flush();
            @flush();
        };

        foreach ($stmt as $row) {
            if ($cols === null) {
                $cols = array_keys(array_filter($row, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY));
            }
            $vals = [];
            foreach ($cols as $c) $vals[] = bk_escapar($row[$c] ?? null);
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= $BATCH_SIZE) $flush();
        }
        $flush();
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    echo "-- Fin del respaldo: " . date('Y-m-d H:i:s') . "\n";
    exit;
}

/* ============================================================
 *  UI
 * ========================================================== */

$tablas = bk_listar_tablas($db);
$motor  = is_mysql() ? 'MySQL' : 'PostgreSQL';

layout_start('Cierre Diario — Respaldo SQL', 'cierre_diario');
?>
<div class="container-fluid p-3">
  <h3 class="mb-1">💾 Cierre Diario — Respaldo SQL</h3>
  <p class="text-muted">
    Descarga un respaldo completo del sistema en formato SQL compatible con phpMyAdmin / MySQL.
    Importa el archivo en phpMyAdmin para recrear todas las tablas con sus datos.
  </p>

  <div class="card">
    <div class="card-body">
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="border rounded p-3 text-center bg-light">
            <div class="text-muted small">Motor actual</div>
            <div class="h5 mb-0"><?= h($motor) ?></div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 text-center bg-light">
            <div class="text-muted small">Tablas a respaldar</div>
            <div class="h5 mb-0"><?= count($tablas) ?></div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 text-center bg-light">
            <div class="text-muted small">Fecha del cierre</div>
            <div class="h5 mb-0"><?= date('d/m/Y') ?></div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 text-center bg-light">
            <div class="text-muted small">Hora</div>
            <div class="h5 mb-0"><?= date('H:i') ?></div>
          </div>
        </div>
      </div>

      <div class="alert alert-warning">
        <strong>⚠ Importante:</strong> Guarda este archivo en un lugar seguro (USB, nube, etc).
        Es tu copia de seguridad del día. El archivo se llamará
        <code>cierre_<?= date('Y-m-d') ?>_HH-MM-SS.sql</code>.
      </div>

      <a href="?p=cierre_diario&action=descargar"
         class="btn btn-lg btn-success">
        📥 Descargar Cierre del Día (<?= date('d/m/Y') ?>)
      </a>

      <div class="border rounded p-3 mt-3 bg-light">
        <div class="form-check form-switch mb-1">
          <input class="form-check-input" type="checkbox" id="autoCierreChk" role="switch">
          <label class="form-check-label fw-semibold" for="autoCierreChk">
            Descargar el cierre automáticamente una vez al día
          </label>
        </div>
        <div class="text-muted small">
          Cuando está activado, la primera vez que abras el sistema cada día (en esta
          computadora) el respaldo se descarga solo. <strong>Nota:</strong> el sistema
          debe estar abierto en el navegador; con la computadora apagada no puede descargar.
        </div>
        <div class="small mt-2" id="autoCierreInfo"></div>
      </div>
      <script>
      (function(){
        var chk  = document.getElementById('autoCierreChk');
        var info = document.getElementById('autoCierreInfo');
        chk.checked = localStorage.getItem('aserp_cierre_auto') !== '0';
        function render(){
          var last = localStorage.getItem('aserp_cierre_last');
          info.textContent = '';
          if (last) {
            info.appendChild(document.createTextNode('Última descarga automática: '));
            var b = document.createElement('strong');
            b.textContent = last;
            info.appendChild(b);
          } else {
            var s = document.createElement('span');
            s.className = 'text-muted';
            s.textContent = 'Aún no se ha descargado automáticamente en esta computadora.';
            info.appendChild(s);
          }
        }
        render();
        chk.addEventListener('change', function(){
          localStorage.setItem('aserp_cierre_auto', chk.checked ? '1' : '0');
        });
      })();
      </script>

      <div class="mt-4">
        <h6>Tablas incluidas (<?= count($tablas) ?>):</h6>
        <div style="max-height:240px;overflow:auto;font-family:monospace;font-size:.85rem;
                    background:#f8f9fa;padding:.6rem;border-radius:.3rem;">
          <?php foreach ($tablas as $t): ?>
            <div>• <?= h($t) ?></div>
          <?php endforeach ?>
        </div>
      </div>

      <div class="mt-4">
        <h6>Cómo restaurar en phpMyAdmin:</h6>
        <ol class="text-muted small mb-0">
          <li>Abre phpMyAdmin y crea (o selecciona) una base de datos vacía.</li>
          <li>Entra a la pestaña <strong>Importar</strong>.</li>
          <li>Sube el archivo <code>cierre_YYYY-MM-DD_HH-MM-SS.sql</code>.</li>
          <li>Pulsa <strong>Continuar</strong>. Se recrearán todas las tablas con los datos.</li>
        </ol>
      </div>
    </div>
  </div>
</div>
<?php layout_end();
