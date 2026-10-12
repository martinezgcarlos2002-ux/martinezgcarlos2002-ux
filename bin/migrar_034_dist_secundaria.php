<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

$db = get_db();
$faltan = static function (string $tabla, string $columna) use ($db): bool {
    $st = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $st->execute(['t' => $tabla, 'c' => $columna]);
    return $st->fetchColumn() === false;
};

$alts = [
    ['inventario_almacenes', 'stock_secundario', 'ALTER TABLE inventario_almacenes ADD COLUMN stock_secundario INT NOT NULL DEFAULT 0 AFTER stock'],
    ['produccion_distribuciones', 'cantidad_secundaria', 'ALTER TABLE produccion_distribuciones ADD COLUMN cantidad_secundaria INT NOT NULL DEFAULT 0 AFTER cantidad'],
];

foreach ($alts as [$tabla, $col, $sql]) {
    if ($faltan($tabla, $col)) {
        $db->exec($sql);
        echo "OK $tabla.$col\n";
    } else {
        echo "SKIP $tabla.$col\n";
    }
}
echo "Listo.\n";
