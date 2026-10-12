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
    ['productos', 'unidad_secundaria', 'ALTER TABLE productos ADD COLUMN unidad_secundaria VARCHAR(20) NULL AFTER unidad_medida'],
    ['productos', 'stock_secundario', 'ALTER TABLE productos ADD COLUMN stock_secundario INT NOT NULL DEFAULT 0 AFTER stock'],
    ['inventario_almacenes', 'stock_secundario', 'ALTER TABLE inventario_almacenes ADD COLUMN stock_secundario INT NOT NULL DEFAULT 0 AFTER stock'],
    ['produccion_diaria', 'cantidad_secundaria', 'ALTER TABLE produccion_diaria ADD COLUMN cantidad_secundaria INT NOT NULL DEFAULT 0 AFTER cantidad'],
    ['produccion_diaria', 'distribuido_secundario', 'ALTER TABLE produccion_diaria ADD COLUMN distribuido_secundario INT NOT NULL DEFAULT 0 AFTER distribuido'],
    ['venta_items', 'cantidad_secundaria', 'ALTER TABLE venta_items ADD COLUMN cantidad_secundaria INT NOT NULL DEFAULT 0 AFTER cantidad'],
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
