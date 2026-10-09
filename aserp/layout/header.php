<?php
$currentUrl = $_GET['url'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ASERP</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>css/estilos.css">
</head>
<body>
<?php if($currentUrl !== 'login'): ?>
<header class="main-header">
    <div class="header-left">
        <img src="<?php echo BASE_URL; ?>images/logo.png" alt="ASERP Logo" class="logo">
        <h1>ASERP - Sistema ERP</h1>
    </div>
    <div class="header-right">
        <a href="?url=dashboard" class="btn-dashboard">Dashboard</a>
        <a href="?url=logout" class="btn-logout">Salir</a>
    </div>
</header>
<?php endif; ?>

<main class="main-content">