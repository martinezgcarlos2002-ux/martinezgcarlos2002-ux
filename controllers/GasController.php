<?php
declare(strict_types=1);

final class GasController
{
    private Gas $gas;

    public function __construct()
    {
        $this->gas = new Gas(get_db());
    }

    public function index(): void
    {
        $user = current_user();
        $tasa = TasaCambio::vigente(get_db());
        view('gas', [
            'puedeCrear'  => has_permiso($user, 'gas', 'crear'),
            'puedeEditar' => has_permiso($user, 'gas', 'editar'),
            'puedeAnular' => has_permiso($user, 'gas', 'anular'),
            'tasa'        => $tasa['tasa'] ?? null,
            'hoy'         => date('Y-m-d'),
        ]);
    }

    public function resumen(): void
    {
        $this->ok($this->gas->resumen());
    }

    public function catalogo(): void
    {
        $this->ok($this->gas->catalogo());
    }

    public function proveedores(): void
    {
        $this->ok(['proveedores' => $this->gas->proveedores()]);
    }

    public function crearProveedor(): void
    {
        $this->guardarProveedor(null);
    }

    public function editarProveedor(): void
    {
        $id = $this->id('id');
        if ($id < 1) {
            json_response(422, ['ok' => false, 'errores' => ['general' => 'Falta el proveedor.']]);
        }
        $this->guardarProveedor($id);
    }

    public function cilindros(): void
    {
        $this->ok(['cilindros' => $this->gas->cilindros()]);
    }

    public function cilindrosDisponibles(): void
    {
        $this->ok(['cilindros' => $this->gas->cilindrosDisponibles()]);
    }

    public function crearCilindro(): void
    {
        $this->guardarCilindro(null);
    }

    public function editarCilindro(): void
    {
        $id = $this->id('id');
        if ($id < 1) {
            json_response(422, ['ok' => false, 'errores' => ['general' => 'Falta la bombona.']]);
        }
        $this->guardarCilindro($id);
    }

    public function eliminarCilindro(): void
    {
        $id = $this->id('id');
        if ($id < 1) {
            json_response(422, ['ok' => false, 'errores' => ['general' => 'Falta la bombona.']]);
        }
        try {
            $foto = $this->gas->eliminarCilindro($id);
            if ($foto !== null) {
                $this->borrarFotoCilindro($foto);
            }
            audit_log('gas', 'cilindro_eliminar', ['id' => $id], 'gas_cilindros', $id);
            $this->ok([
                'mensaje'   => 'Bombona eliminada.',
                'cilindros' => $this->gas->cilindros(),
            ]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    public function despachos(): void
    {
        $this->ok(['despachos' => $this->gas->despachos()]);
    }

    public function despacho(): void
    {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!is_int($id) || $id < 1) {
            json_response(422, ['ok' => false, 'errores' => ['general' => 'Falta el despacho.']]);
        }
        try {
            $this->ok($this->gas->despacho($id));
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        }
    }

    public function crearDespacho(): void
    {
        try {
            $id = $this->gas->crearDespacho($_POST, $this->userId());
            audit_log('gas', 'despacho_crear', ['id' => $id], 'gas_despachos', $id);
            $this->ok(['id' => $id, 'mensaje' => 'Despacho abierto.'] + $this->gas->despacho($id));
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    public function editarDespacho(): void
    {
        $id = $this->id('id');
        if ($id < 1) {
            json_response(422, ['ok' => false, 'errores' => ['general' => 'Falta el despacho.']]);
        }
        try {
            $this->gas->editarDespacho($id, $_POST);
            audit_log('gas', 'despacho_editar', null, 'gas_despachos', $id);
            $this->ok(['mensaje' => 'Despacho actualizado.'] + $this->gas->despacho($id));
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    public function agregarCilindros(): void
    {
        $id = $this->id('despacho_id');
        $ids = json_decode((string) ($_POST['cilindros'] ?? ''), true);
        if (!is_array($ids)) {
            json_response(422, ['ok' => false, 'errores' => ['cilindros' => 'Selecciona las bombonas del viaje.']]);
        }
        try {
            $n = Database::transaction(fn (): int => $this->gas->agregarCilindros($id, $ids));
            audit_log('gas', 'despacho_cilindros', ['cantidad' => $n], 'gas_despachos', $id);
            $this->ok(['mensaje' => $n === 1 ? '1 bombona cargada.' : $n . ' bombonas cargadas.'] + $this->gas->despacho($id));
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    public function eliminarDespacho(): void
    {
        $id = $this->id('id');
        if ($id < 1) {
            json_response(422, ['ok' => false, 'errores' => ['general' => 'Falta el despacho.']]);
        }
        try {
            $this->gas->eliminarDespacho($id);
            audit_log('gas', 'despacho_quitar', null, 'gas_despachos', $id);
            $this->ok(['mensaje' => 'Viaje vacío eliminado.', 'despachos' => $this->gas->despachos()]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    public function quitarCilindro(): void
    {
        $despachoId = $this->id('despacho_id');
        $cilindroId = $this->id('cilindro_id');
        try {
            $this->gas->quitarCilindro($despachoId, $cilindroId);
            audit_log('gas', 'despacho_quitar', ['cilindro_id' => $cilindroId], 'gas_despachos', $despachoId);
            $this->ok(['mensaje' => 'Bombona retirada del viaje.'] + $this->gas->despacho($despachoId));
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    public function registrarPago(): void
    {
        try {
            $r = $this->gas->registrarPago($_POST, $this->userId());
            audit_log('gas', 'pago', ['monto_usd' => $r['monto_usd']], 'gas_pagos', $r['id']);
            $this->ok(['mensaje' => 'Abono registrado en caja.', 'pago_id' => $r['id']] + $r['despacho']);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    public function reparaciones(): void
    {
        $this->ok([
            'reparaciones' => $this->gas->reparaciones(),
            'disponibles'  => $this->gas->cilindrosParaReparar(),
        ]);
    }

    public function enviarReparacion(): void
    {
        $ids = json_decode((string) ($_POST['cilindros'] ?? ''), true);
        if (!is_array($ids)) {
            json_response(422, ['ok' => false, 'errores' => ['cilindros' => 'Selecciona las bombonas.']]);
        }
        try {
            $n = $this->gas->enviarReparacion($ids, post_string('responsable', 120), post_string('fecha_salida', 10), $this->userId());
            audit_log('gas', 'reparacion_enviar', ['cantidad' => $n], 'gas_reparaciones', null);
            $this->ok([
                'mensaje'      => $n === 1 ? 'Bombona enviada a reparación.' : $n . ' bombonas enviadas a reparación.',
                'reparaciones' => $this->gas->reparaciones(),
                'disponibles'  => $this->gas->cilindrosParaReparar(),
            ]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    public function cerrarReparacion(): void
    {
        $id = $this->id('id');
        try {
            $this->gas->cerrarReparacion($id, post_string('costo_usd', 16), $this->userId());
            audit_log('gas', 'reparacion_cerrar', null, 'gas_reparaciones', $id);
            $this->ok([
                'mensaje'      => 'Reparación cerrada y egreso registrado en caja.',
                'reparaciones' => $this->gas->reparaciones(),
                'disponibles'  => $this->gas->cilindrosParaReparar(),
            ]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    public function anularPago(): void
    {
        $id = $this->id('id');
        try {
            $detalle = $this->gas->anularPago($id, post_string('motivo', 255), $this->userId());
            audit_log('gas', 'pago_anular', null, 'gas_pagos', $id);
            $this->ok(['mensaje' => 'Abono anulado y asiento revertido.'] + $detalle);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    private function guardarProveedor(?int $id): void
    {
        try {
            $guardado = $this->gas->guardarProveedor($id, $_POST, $this->userId());
            audit_log('gas', $id === null ? 'proveedor_crear' : 'proveedor_editar', null, 'gas_proveedores', $guardado);
            $this->ok([
                'id'          => $guardado,
                'mensaje'     => $id === null ? 'Proveedor registrado.' : 'Proveedor actualizado.',
                'proveedores' => $this->gas->proveedores(),
            ]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    private function guardarCilindro(?int $id): void
    {
        if ($id === null) {
            unset($_POST['serial']);
        }
        try {
            $foto = $this->recibirFotoCilindro();
            if ($foto !== null) {
                $anterior = $id === null ? null : $this->gas->fotoCilindro($id);
                if ($anterior !== null) {
                    $this->borrarFotoCilindro($anterior);
                }
                $_POST['foto_url'] = $foto;
            }
            $guardado = $this->gas->guardarCilindro($id, $_POST, $this->userId());
            audit_log('gas', $id === null ? 'cilindro_crear' : 'cilindro_editar', null, 'gas_cilindros', $guardado);
            $cilindros = $this->gas->cilindros();
            $serial = '';
            foreach ($cilindros as $cilindro) {
                if ((int) $cilindro['id'] === $guardado) {
                    $serial = (string) $cilindro['serial'];
                    break;
                }
            }
            $this->ok([
                'id'        => $guardado,
                'serial'    => $serial,
                'mensaje'   => $id === null ? 'Bombona registrada: ' . $serial : 'Bombona actualizada.',
                'cilindros' => $cilindros,
            ]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $this->falloSql($e);
        }
    }

    private function falloSql(PDOException $e): never
    {
        $msg = Gas::mensajePdo($e);
        if ($msg !== null) {
            json_response(422, ['ok' => false, 'errores' => ['general' => $msg]]);
        }
        throw $e;
    }

    /** @param array<string, mixed> $data */
    private function ok(array $data): never
    {
        json_response(200, ['ok' => true] + $data);
    }

    private function id(string $key): int
    {
        $raw = post_string($key, 12);
        return preg_match('/^[1-9]\d{0,9}$/', $raw) ? (int) $raw : 0;
    }

    private function userId(): int
    {
        return (int) current_user()['id'];
    }

    /**
     * Valida JPG, PNG o WEBP y deja el archivo nuevo en assets/img/gas/.
     * Devuelve null si el formulario no trae foto.
     */
    private function recibirFotoCilindro(): ?string
    {
        $archivo = $_FILES['foto'] ?? null;
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['foto' => 'No se pudo recibir la foto. Inténtalo de nuevo.']);
        }
        if ((int) ($archivo['size'] ?? 0) > 2_000_000) {
            throw new ValidationException(['foto' => 'La foto debe pesar hasta 2 MB.']);
        }
        $tmp = (string) ($archivo['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw new ValidationException(['foto' => 'La carga de la foto no es válida.']);
        }
        try {
            $tipo = ImagenSegura::inspeccionar($tmp, ['image/jpeg', 'image/png', 'image/webp']);
        } catch (InvalidArgumentException $e) {
            throw new ValidationException(['foto' => $e->getMessage()]);
        }
        $dir = ADARA_ROOT . '/assets/img/gas';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ValidationException(['foto' => 'No se pudo crear la carpeta de fotos.']);
        }
        $rel = 'img/gas/cil-' . bin2hex(random_bytes(16)) . '.' . $tipo['ext'];
        try {
            ImagenSegura::guardar($tmp, $tipo['mime'], ADARA_ROOT . '/assets/' . $rel);
        } catch (RuntimeException $e) {
            throw new ValidationException(['foto' => $e->getMessage()]);
        }
        return $rel;
    }

    /** Borra solo una foto previa que viva en assets/img/gas/. */
    private function borrarFotoCilindro(string $rel): void
    {
        $rel = str_replace('\\', '/', $rel);
        if (!str_starts_with($rel, 'img/gas/') || str_contains($rel, '..')) {
            return;
        }
        $abs = ADARA_ROOT . '/assets/' . $rel;
        if (is_file($abs)) {
            unlink($abs);
        }
    }
}
