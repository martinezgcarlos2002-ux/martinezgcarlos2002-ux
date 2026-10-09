<?php
declare(strict_types=1);

define('ADARA_ROOT', dirname(__DIR__));
define('APP_NAME', 'ADARA');
define('APP_EMPRESA', 'El Castillo del Queso Artesanal F.P.');
define('APP_RIF', 'V-286013356');
define('APP_DEBUG', getenv('ADARA_DEBUG') === '1');
define('APP_TIMEZONE', 'America/Caracas');

// Día ISO en que abre el ciclo de nómina (1 = lunes … 7 = domingo). El ciclo dura 7 días.
define('NOMINA_DIA_INICIO', 1);

define('SESSION_NAME', 'ADARA_SID');
define('SESSION_IDLE_SECONDS', 30 * 60);

define('LOGIN_MAX_FALLOS_EMAIL_IP', 5);
define('LOGIN_MAX_FALLOS_IP', 20);
define('LOGIN_VENTANA_MINUTOS', 15);

// bcrypt solo usa los primeros 72 bytes; más largo se trunca en silencio.
define('PASSWORD_MIN_LEN', 8);
define('PASSWORD_MAX_BYTES', 72);
const PASSWORD_ALGO = PASSWORD_BCRYPT;
const PASSWORD_OPTIONS = ['cost' => 12];
