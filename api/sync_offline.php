<?php
declare(strict_types=1);

/**
 * Punto de entrada de la cola offline. No autentica por su cuenta: delega en
 * index.php para reutilizar sesión, CSRF, modo soporte y la empresa activa.
 *
 * POST /ADARA/api/sync_offline.php
 * Header: Accept: application/json, X-CSRF-TOKEN
 * Body: {"operaciones":[{"id":"<uuid>","tipo":"recepcion_leche","capturado_en":"2026-11-10T18:00:00-04:00","datos":{}}]}
 */
$_GET['p'] = 'sync_offline';
require dirname(__DIR__) . '/index.php';
