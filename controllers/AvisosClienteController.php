<?php
declare(strict_types=1);

/** Avisos de vencimiento de la suscripción y pantalla de servicio suspendido. */
final class AvisosClienteController
{
    public function lista(): void
    {
        $clienteId = $this->cliente();
        if ($clienteId === null) {
            json_response(200, ['ok' => true, 'total' => 0, 'avisos' => []]);
        }
        try {
            $filas = (new SaasComercial(Database::master()))->avisosPendientes($clienteId);
        } catch (PDOException) {
            json_response(200, ['ok' => true, 'total' => 0, 'avisos' => []]);
        }
        $avisos = [];
        foreach ($filas as $fila) {
            $avisos[] = [
                'id'      => (int) $fila['id'],
                'mensaje' => (string) $fila['mensaje'],
                'fecha'   => date('d/m/Y', strtotime((string) $fila['fecha'])),
            ];
        }
        json_response(200, ['ok' => true, 'total' => count($avisos), 'avisos' => $avisos]);
    }

    public function leer(): void
    {
        $clienteId = $this->cliente();
        $id = parse_int_range($_POST['id'] ?? '', 1, PHP_INT_MAX);
        if ($clienteId === null || $id === null) {
            json_response(200, ['ok' => true]);
        }
        try {
            (new SaasComercial(Database::master()))->marcarAvisoLeido($clienteId, $id);
        } catch (PDOException) {
            json_response(200, ['ok' => false]);
        }
        json_response(200, ['ok' => true]);
    }

    public function suspendido(): void
    {
        if (!Database::multiempresa()) {
            redirect('dashboard');
        }
        $clienteId = $_SESSION['tenant_id'] ?? null;
        if (!is_int($clienteId)) {
            redirect('login');
        }
        try {
            $suspendido = (new SaasComercial(Database::master()))->estaSuspendido($clienteId);
        } catch (PDOException) {
            $suspendido = false;
        }
        if (!$suspendido) {
            redirect('dashboard');
        }
        view('servicio_suspendido', [
            'enSoporte' => is_int($_SESSION['impersonado_por'] ?? null),
        ]);
    }

    private function cliente(): ?int
    {
        if (!Database::multiempresa()) {
            return null;
        }
        $clienteId = $_SESSION['tenant_id'] ?? null;
        return is_int($clienteId) ? $clienteId : null;
    }
}
