<?php
declare(strict_types=1);

/** Términos y Condiciones del cliente. El administrador no entra al ERP hasta aceptarlos. */
final class TerminosController
{
    public function ver(): void
    {
        $ctx = $this->contexto();
        $contrato = $ctx['comercial']->contrato($ctx['clienteId']);
        view('terminos', [
            'aceptado'  => $contrato['aceptado'],
            'fecha'     => $contrato['fecha'],
            'esAdmin'   => $ctx['esAdmin'],
            'enSoporte' => $ctx['enSoporte'],
            'errores'   => [],
        ]);
    }

    public function aceptar(): void
    {
        $ctx = $this->contexto();
        if ($ctx['enSoporte']) {
            flash('error', 'El modo soporte no firma los términos en nombre del cliente.');
            redirect('terminos');
        }
        if (!$ctx['esAdmin']) {
            flash('error', 'Solo un administrador de la empresa puede aceptar los términos.');
            redirect('terminos');
        }
        if (($_POST['acepto'] ?? '') !== '1') {
            $contrato = $ctx['comercial']->contrato($ctx['clienteId']);
            view('terminos', [
                'aceptado'  => $contrato['aceptado'],
                'fecha'     => $contrato['fecha'],
                'esAdmin'   => true,
                'enSoporte' => false,
                'errores'   => ['acepto' => 'Marca la casilla para aceptar los Términos y Condiciones.'],
            ]);
            return;
        }

        $ctx['comercial']->aceptarContrato($ctx['clienteId']);
        $user = $ctx['user'];
        (new Saas(Database::master()))->auditar(null, 'contrato_aceptado', $ctx['clienteId'], [
            'texto'   => 'El administrador ' . $user['nombre'] . ' aceptó los Términos y Condiciones.',
            'usuario' => (int) $user['id'],
            'email'   => (string) $user['email'],
        ]);
        audit_log('contrato', 'aceptado', ['email' => $user['email']], 'clientes_saas', $ctx['clienteId']);
        flash('success', 'Términos aceptados. Ya puedes usar ADARA.');
        redirect('dashboard');
    }

    /** @return array{comercial:SaasComercial, clienteId:int, esAdmin:bool, enSoporte:bool, user:array{id:int,nombre:string,email:string,rol:string}} */
    private function contexto(): array
    {
        if (!Database::multiempresa()) {
            flash('info', 'Esta instalación no registra la aceptación en una base maestra.');
            redirect('dashboard');
        }
        $clienteId = $_SESSION['tenant_id'] ?? null;
        $user = current_user();
        if (!is_int($clienteId) || $user === null) {
            flash('error', 'No hay una empresa activa para mostrar los términos.');
            redirect('dashboard');
        }
        return [
            'comercial' => new SaasComercial(Database::master()),
            'clienteId' => $clienteId,
            'esAdmin'   => ($user['rol'] ?? '') === 'admin',
            'enSoporte' => is_int($_SESSION['impersonado_por'] ?? null),
            'user'      => $user,
        ];
    }
}
