<?php
declare(strict_types=1);

/**
 * Conciliación bancaria por Excel.
 *
 * PhpSpreadsheet: el proyecto no trae Composer. El libro se lee en
 * helpers/ConciliacionExcel.php. Si se instala la librería, ese lector la
 * detecta solo. En la raíz de ADARA:
 *
 *   composer require phpoffice/phpspreadsheet
 *
 * Hace falta la extensión zip (php_zip). En XAMPP ya está activa. Después,
 * config/bootstrap.php puede cargar vendor/autoload.php; sin ese archivo el
 * módulo sigue leyendo el xlsx con ZipArchive.
 *
 * Transacción: subir() abre beginTransaction. En el libro clasificado el
 * único bloqueo es la continuidad de caja (saldo inicial = saldo final
 * anterior). Un movimiento sin referencia en ADARA queda huérfano y no
 * revierte la carga. Si algo no puede guardarse, rollBack.
 */
final class BancosController
{
    private Banco $bancos;

    public function __construct()
    {
        $this->bancos = new Banco(get_db());
    }

    public function index(): void
    {
        $user = current_user();
        view('bancos', [
            'puedeCrear' => has_permiso($user, 'bancos', 'crear'),
            'puedeEditar' => has_permiso($user, 'bancos', 'editar'),
            'puedeAnular' => has_permiso($user, 'bancos', 'anular'),
            'cuentas' => $this->bancos->cuentas(),
            'cierres' => $this->bancos->cierresRecientes(),
        ]);
    }

    /**
     * Arma una hoja del día, con el mismo esquema del libro de conciliaciones,
     * y la envía. El temporal se borra al terminar.
     */
    public function descargarPlantilla(): void
    {
        $vigente = TasaCambio::vigenteEn(get_db(), date('Y-m-d H:i:s'));
        $tmp = PlantillaClasificada::materializar(
            $this->bancos->cuentasParaPlantilla(),
            [],
            isset($vigente['tasa']) ? (string) $vigente['tasa'] : null,
            [
                'productores' => $this->bancos->nombresProductores(),
                'transportistas' => $this->bancos->nombresTransportistas(),
                'empleados' => $this->bancos->nombresEmpleados(),
                'clientes' => $this->bancos->nombresClientes(),
            ],
            $this->bancos->saldosParaPlantilla()
        );
        $nombre = 'ADARA_conciliacion_' . date('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('Cache-Control: no-store, private');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    public function subir(): void
    {
        $archivo = $_FILES['archivo'] ?? null;
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            json_response(422, ['ok' => false, 'errores' => ['archivo' => 'Selecciona el Excel de la conciliación.']]);
        }
        if (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            json_response(422, ['ok' => false, 'errores' => ['archivo' => 'No se pudo recibir el archivo. Si pesa mucho, súbelo de nuevo.']]);
        }
        $nombre = (string) ($archivo['name'] ?? 'conciliacion.xlsx');
        $tmp = (string) ($archivo['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            json_response(422, ['ok' => false, 'errores' => ['archivo' => 'La carga del archivo no es válida.']]);
        }
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm'], true)) {
            json_response(422, ['ok' => false, 'errores' => ['archivo' => 'El archivo tiene que ser Excel (.xlsx).']]);
        }
        if (($archivo['size'] ?? 0) > 12 * 1024 * 1024) {
            json_response(422, ['ok' => false, 'errores' => ['archivo' => 'El Excel supera 12 MB.']]);
        }

        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $libro = ConciliacionExcel::leer($tmp);
            $resumen = $this->bancos->aplicar($libro, $nombre, (int) current_user()['id']);
            audit_log('bancos', 'conciliacion', $resumen, 'bancos_cierres_diarios', null);
            $pdo->commit();
            json_response(200, ['ok' => true, 'resumen' => $resumen]);
        } catch (DescuadreBancario $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $primero = $e->descuadres[0]['mensaje'] ?? 'La conciliación no cuadra.';
            json_response(422, [
                'ok' => false,
                'errores' => ['archivo' => $primero],
                'descuadres' => $e->descuadres,
            ]);
        } catch (ValidationException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response(422, ['ok' => false, 'errores' => $e->errors, 'descuadres' => []]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ADARA] conciliacion ' . $e);
            $msg = 'No se pudo guardar la conciliación.';
            if ($e instanceof PDOException && str_contains($e->getMessage(), 'uq_banco_cierre')) {
                $msg = 'Ese día ya estaba cerrado para una de las cuentas. No se cargó nada.';
            } elseif ($e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                $msg = 'Otra conciliación de la misma cuenta se estaba guardando. No se cargó nada; vuelve a subir el Excel.';
            }
            json_response(422, ['ok' => false, 'errores' => ['archivo' => $msg], 'descuadres' => []]);
        }
    }

    public function auditoria(): void
    {
        json_response(200, ['ok' => true] + $this->bancos->auditoria());
    }

    public function crearCuenta(): void
    {
        $this->guardarCuenta(null);
    }

    public function editarCuenta(): void
    {
        $id = $this->idEntero();
        if ($id < 1) {
            json_response(422, ['ok' => false, 'errores' => ['alias' => 'Falta la cuenta.']]);
        }
        $this->guardarCuenta($id);
    }

    public function desactivarCuenta(): void
    {
        $id = $this->idEntero();
        if ($id < 1) {
            json_response(422, ['ok' => false, 'errores' => ['alias' => 'Falta la cuenta.']]);
        }
        try {
            $this->bancos->desactivarCuenta($id);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        }
        json_response(200, ['ok' => true, 'cuentas' => $this->bancos->cuentas()]);
    }

    public function activarCuenta(): void
    {
        $id = $this->idEntero();
        if ($id < 1) {
            json_response(422, ['ok' => false, 'errores' => ['alias' => 'Falta la cuenta.']]);
        }
        try {
            $this->bancos->activarCuenta($id);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        }
        json_response(200, ['ok' => true, 'cuentas' => $this->bancos->cuentas()]);
    }

    private function guardarCuenta(?int $id): void
    {
        try {
            $this->bancos->guardarCuenta($id, [
                'alias' => post_string('alias', 80),
                'nombre_banco' => post_string('nombre_banco', 80),
                'numero_cuenta' => post_string('numero_cuenta', 30),
                'cedula' => post_string('cedula', 24),
                'telefono' => post_string('telefono', 24),
                'moneda' => post_string('moneda', 3),
            ]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        }
        json_response(200, ['ok' => true, 'cuentas' => $this->bancos->cuentas()]);
    }

    private function idEntero(): int
    {
        $raw = post_string('id', 12);
        return preg_match('/^[1-9]\d{0,9}$/', $raw) ? (int) $raw : 0;
    }
}
