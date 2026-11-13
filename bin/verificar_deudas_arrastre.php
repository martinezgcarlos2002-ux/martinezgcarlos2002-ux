<?php
declare(strict_types=1);

/**
 * Compara, al centavo, el total de las tarjetas del dashboard con el detalle
 * que muestran Productores, Nómina y Transporte cuando llega ?filtro=con_deuda.
 *
 * Uso (XAMPP, base ya importada):
 *   C:\xampp\php\php.exe bin\verificar_deudas_arrastre.php adara
 */
$base = $argv[1] ?? 'adara';
if (!preg_match('/^[a-z0-9_]{1,64}$/', $base)) {
    fwrite(STDERR, "Nombre de base inválido.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/models/Caja.php';
require_once dirname(__DIR__) . '/services/DeudasArrastreDetalle.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=' . $base . ';charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$corte = DeudasArrastreDetalle::corte();
$detalle = [
    'Productores' => DeudasArrastreDetalle::productores($pdo, $corte),
    'Nómina'      => DeudasArrastreDetalle::nomina($pdo, $corte),
    'Transporte'  => DeudasArrastreDetalle::transporte($pdo, $corte),
];

$sqlDashboard = "WITH ultimo AS (
        SELECT productor_id, MAX(semana_inicio) AS semana_inicio
        FROM productores_cierres
        WHERE estado <> 'anulada'
        GROUP BY productor_id
    )
    SELECT categoria, deuda FROM (
        SELECT 'Productores' AS categoria, SUM(c.saldo_usd - c.pagado_usd) AS deuda
        FROM productores_cierres c
        JOIN ultimo u ON u.productor_id = c.productor_id AND u.semana_inicio = c.semana_inicio
        WHERE c.semana_fin < :corte_p AND c.saldo_usd > c.pagado_usd
        UNION ALL
        SELECT 'Productores', SUM(c.arrastre_anterior_usd)
        FROM productores_cierres c
        JOIN ultimo u ON u.productor_id = c.productor_id AND u.semana_inicio = c.semana_inicio
        WHERE c.semana_inicio >= :corte_a AND c.arrastre_anterior_usd > 0
        UNION ALL
        SELECT 'Nómina', SUM(n.neto_usd)
        FROM nomina_semanal n
        WHERE n.estado = 'cerrada' AND n.neto_usd > 0 AND n.semana_fin < :corte_n
        UNION ALL
        SELECT 'Transporte', SUM(p.total_pagar - COALESCE(a.pagado, 0))
        FROM log_pagos_semanales p
        LEFT JOIN (
            SELECT pago_semanal_id, SUM(monto) AS pagado
            FROM log_pagos_transporte
            GROUP BY pago_semanal_id
        ) a ON a.pago_semanal_id = p.id
        WHERE p.fecha_fin < :corte_t AND p.total_pagar > COALESCE(a.pagado, 0)
    ) d";

$stmt = $pdo->prepare($sqlDashboard);
$stmt->execute([
    'corte_p' => $corte,
    'corte_a' => $corte,
    'corte_n' => $corte,
    'corte_t' => $corte,
]);
$tarjeta = ['Productores' => 0, 'Nómina' => 0, 'Transporte' => 0];
foreach ($stmt as $fila) {
    $tarjeta[$fila['categoria']] += Caja::aCentavos($fila['deuda'] ?? 0);
}

$fallo = false;
echo "Corte (sábado de la semana operativa): $corte\n";
foreach ($detalle as $categoria => $filas) {
    $suma = Caja::aCentavos(DeudasArrastreDetalle::total($filas));
    $ok = $suma === $tarjeta[$categoria];
    $fallo = $fallo || !$ok;
    printf(
        "%s  detalle=%s  tarjeta=%s  cuentas=%d  %s\n",
        str_pad($categoria, 12),
        Caja::desdeCentavos($suma),
        Caja::desdeCentavos($tarjeta[$categoria]),
        count($filas),
        $ok ? 'OK' : 'NO COINCIDE'
    );
}

exit($fallo ? 1 : 0);
