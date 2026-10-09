<?php
/**
 * Configuración local para XAMPP / MySQL
 *
 * INSTRUCCIONES:
 *  1. Copia este archivo y renómbralo a: local-config.php
 *  2. Edita los datos de conexión de abajo con tu MySQL de XAMPP
 *  3. Importa el archivo "ASERP-BaseDatos-MySQL.sql" en phpMyAdmin
 *  4. Copia la carpeta "aserp-php" dentro de C:\xampp\htdocs\
 *  5. Accede al sistema desde: http://localhost/aserp-php/?p=login
 *
 * DATOS DE ACCESO AL SISTEMA:
 *   Admin:    admin@aserp.com  / admin123
 *   Empleado: empleado@aserp.com / admin123
 */

define('DB_DRIVER', 'mysql');
define('DB_HOST',   'localhost');
define('DB_PORT',   '3306');
define('DB_NAME',   'aserp');        // Nombre de la base de datos que creaste en phpMyAdmin
define('DB_USER',   'root');         // Usuario de MySQL en XAMPP (normalmente "root")
define('DB_PASS',   '');             // Contraseña de MySQL en XAMPP (normalmente vacía)
