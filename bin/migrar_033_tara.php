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
    ['produccion_diaria', 'peso_bruto', 'ALTER TABLE produccion_diaria ADD COLUMN peso_bruto DECIMAL(12,3) NULL AFTER cantidad_secundaria'],
    ['produccion_diaria', 'tara_unitaria', 'ALTER TABLE produccion_diaria ADD COLUMN tara_unitaria DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER peso_bruto'],
    ['produccion_diaria', 'tara_total', 'ALTER TABLE produccion_diaria ADD COLUMN tara_total DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER tara_unitaria'],
    ['venta_items', 'peso_bruto', 'ALTER TABLE venta_items ADD COLUMN peso_bruto DECIMAL(12,3) NULL AFTER cantidad_secundaria'],
    ['venta_items', 'tara_unitaria', 'ALTER TABLE venta_items ADD COLUMN tara_unitaria DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER peso_bruto'],
    ['venta_items', 'tara_total', 'ALTER TABLE venta_items ADD COLUMN tara_total DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER tara_unitaria'],
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
