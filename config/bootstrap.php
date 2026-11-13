<?php
declare(strict_types=1);

// XAMPP: local-config.php (git lo ignora) fija las bases locales. En Hostinger no existe
// y las claves siguen saliendo del SetEnv del .htaccess. Nunca pisa una variable ya definida.
$localConfig = dirname(__DIR__) . '/local-config.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/database.php';

date_default_timezone_set(APP_TIMEZONE);

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', ADARA_ROOT . '/storage/logs/php-error.log');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    error_log('[ADARA] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    if (APP_DEBUG) {
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
        . '<p>Ocurrió un error interno. Si persiste, contacta al administrador.</p>';
});

require_once ADARA_ROOT . '/helpers/http.php';
require_once ADARA_ROOT . '/helpers/form.php';
require_once ADARA_ROOT . '/security/session.php';
require_once ADARA_ROOT . '/security/csrf.php';
require_once ADARA_ROOT . '/security/auth.php';
require_once ADARA_ROOT . '/security/rbac.php';
require_once ADARA_ROOT . '/helpers/vista_movil.php';
require_once ADARA_ROOT . '/security/audit.php';
require_once ADARA_ROOT . '/security/idempotencia.php';
require_once ADARA_ROOT . '/security/tenant.php';
require_once ADARA_ROOT . '/models/Saas.php';
require_once ADARA_ROOT . '/models/SaasComercial.php';
require_once ADARA_ROOT . '/helpers/SqlScript.php';
require_once ADARA_ROOT . '/services/SaaSManager.php';

require_once ADARA_ROOT . '/models/Usuario.php';
require_once ADARA_ROOT . '/models/LoginIntento.php';
require_once ADARA_ROOT . '/models/TasaCambio.php';
require_once ADARA_ROOT . '/models/Productor.php';
require_once ADARA_ROOT . '/models/EntregaLeche.php';
require_once ADARA_ROOT . '/models/Caja.php';
require_once ADARA_ROOT . '/models/Gas.php';
require_once ADARA_ROOT . '/models/Banco.php';
require_once ADARA_ROOT . '/models/PagoProductor.php';
require_once ADARA_ROOT . '/models/CierreSemanal.php';
require_once ADARA_ROOT . '/models/CostoProduccion.php';
require_once ADARA_ROOT . '/models/ControlLecheDiario.php';
require_once ADARA_ROOT . '/helpers/NotificationHelper.php';
require_once ADARA_ROOT . '/models/Auditoria.php';
require_once ADARA_ROOT . '/models/RespaldoSql.php';
require_once ADARA_ROOT . '/models/Produccion.php';
require_once ADARA_ROOT . '/models/Producto.php';
require_once ADARA_ROOT . '/models/Venta.php';
require_once ADARA_ROOT . '/models/Almacen.php';
require_once ADARA_ROOT . '/models/Empleado.php';
require_once ADARA_ROOT . '/models/DiaLibre.php';
require_once ADARA_ROOT . '/models/Nomina.php';
require_once ADARA_ROOT . '/models/CobranzaTercero.php';
require_once ADARA_ROOT . '/models/PrestamoEmpleado.php';
require_once ADARA_ROOT . '/services/NominaService.php';
require_once ADARA_ROOT . '/helpers/XlsxWriter.php';
require_once ADARA_ROOT . '/helpers/XmlSeguro.php';
require_once ADARA_ROOT . '/helpers/ConciliacionExcel.php';
require_once ADARA_ROOT . '/helpers/PlantillaBancos.php';
require_once ADARA_ROOT . '/helpers/PlantillaLibroDiario.php';
require_once ADARA_ROOT . '/helpers/PlantillaClasificada.php';
require_once ADARA_ROOT . '/helpers/PlanillaRecepcionExcel.php';
require_once ADARA_ROOT . '/helpers/PngTexto.php';
require_once ADARA_ROOT . '/helpers/LiquidacionArchivo.php';
require_once ADARA_ROOT . '/helpers/ImagenSegura.php';
require_once ADARA_ROOT . '/helpers/EmpleadoArchivo.php';
require_once ADARA_ROOT . '/services/EmailService.php';
require_once ADARA_ROOT . '/controllers/AuthController.php';
require_once ADARA_ROOT . '/controllers/PasswordController.php';
require_once ADARA_ROOT . '/controllers/DashboardController.php';
require_once ADARA_ROOT . '/controllers/ProductoresController.php';
require_once ADARA_ROOT . '/controllers/ControlLecheController.php';
require_once ADARA_ROOT . '/controllers/ProduccionController.php';
require_once ADARA_ROOT . '/controllers/InventarioController.php';
require_once ADARA_ROOT . '/controllers/VentasController.php';
require_once ADARA_ROOT . '/controllers/FinanzasController.php';
require_once ADARA_ROOT . '/controllers/UsuariosController.php';
require_once ADARA_ROOT . '/controllers/RespaldoController.php';
require_once ADARA_ROOT . '/controllers/AuditoriaController.php';
require_once ADARA_ROOT . '/controllers/EmpleadosController.php';
require_once ADARA_ROOT . '/controllers/NominaController.php';
require_once ADARA_ROOT . '/controllers/DiasLibresController.php';
require_once ADARA_ROOT . '/controllers/CobranzasController.php';
require_once ADARA_ROOT . '/services/CorrelativoService.php';
require_once ADARA_ROOT . '/services/CxCService.php';
require_once ADARA_ROOT . '/services/DeudasArrastreDetalle.php';
require_once ADARA_ROOT . '/controllers/FacturaController.php';
require_once ADARA_ROOT . '/services/CatalogoService.php';
require_once ADARA_ROOT . '/controllers/CatalogoController.php';
require_once ADARA_ROOT . '/services/ProduccionCatalogoService.php';
require_once ADARA_ROOT . '/services/ResumenService.php';
require_once ADARA_ROOT . '/controllers/ResumenController.php';
require_once ADARA_ROOT . '/services/TransporteService.php';
require_once ADARA_ROOT . '/controllers/TransporteController.php';
require_once ADARA_ROOT . '/controllers/GasController.php';
require_once ADARA_ROOT . '/controllers/BancosController.php';
require_once ADARA_ROOT . '/models/EmpresaConfig.php';
require_once ADARA_ROOT . '/controllers/ConfiguracionController.php';
require_once ADARA_ROOT . '/controllers/MasterController.php';
require_once ADARA_ROOT . '/controllers/TerminosController.php';
require_once ADARA_ROOT . '/controllers/TicketClienteController.php';
require_once ADARA_ROOT . '/controllers/AvisosClienteController.php';
require_once ADARA_ROOT . '/controllers/SyncController.php';

require_once ADARA_ROOT . '/layout.php';

if (PHP_SAPI !== 'cli') {
    session_start_secure();
    tenant_restaurar();
} else {
    tenant_cli();
}
