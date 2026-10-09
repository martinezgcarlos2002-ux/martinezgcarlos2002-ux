<?php
// Página de cierres removida. Las semanas ahora se gestionan dentro de la ficha
// de cada productor (?p=productor_detalle&tab=cierres). Mantenemos este archivo
// como redirector por compatibilidad con enlaces antiguos.
require_login();
$pid = (int)($_GET['pid'] ?? 0);
if ($pid > 0) {
    header("Location: ?p=productor_detalle&id=$pid&tab=cierres"); exit;
}
header('Location: ?p=productores'); exit;
