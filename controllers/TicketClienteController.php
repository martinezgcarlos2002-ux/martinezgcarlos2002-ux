<?php
declare(strict_types=1);

/** El cliente envía una falla, una asistencia o una sugerencia a la base maestra. */
final class TicketClienteController
{
    public function enviar(): void
    {
        if (!Database::multiempresa()) {
            flash('error', 'El reporte a soporte no está disponible en esta instalación.');
            redirect('dashboard');
        }
        $clienteId = $_SESSION['tenant_id'] ?? null;
        $user = current_user();
        if (!is_int($clienteId) || $user === null) {
            flash('error', 'Inicia sesión en tu empresa para reportar a soporte.');
            redirect('login');
        }
        if (is_int($_SESSION['impersonado_por'] ?? null)) {
            flash('error', 'En modo soporte el reporte lo envía el cliente, no el maestro.');
            redirect('dashboard');
        }

        $datos = [
            'asunto'         => post_string('asunto', 140),
            'tipo_solicitud' => post_string('tipo_solicitud', 20),
            'descripcion'    => post_string('descripcion', 2000),
        ];
        try {
            $id = (new SaasComercial(Database::master()))->crearTicket(
                $clienteId,
                (int) $user['id'],
                $datos['asunto'],
                $datos['tipo_solicitud'],
                $datos['descripcion']
            );
        } catch (ValidationException $e) {
            $_SESSION['_ticket_errores'] = $e->errors;
            $_SESSION['_ticket_form'] = $datos;
            flash('error', 'Revisa el reporte antes de enviarlo.');
            redirect('dashboard');
        } catch (PDOException $e) {
            $mensaje = (int) ($e->errorInfo[1] ?? 0) === 1146
                ? 'El buzón de soporte todavía no está creado. Avisa a ADARA.'
                : 'No se pudo enviar el reporte. Inténtalo de nuevo.';
            flash('error', $mensaje);
            redirect('dashboard');
        }

        audit_log('soporte', 'ticket', ['ticket' => $id, 'tipo' => $datos['tipo_solicitud']], 'saas_tickets_soporte', $id);
        flash('success', 'Reporte enviado a soporte técnico. El número es ' . $id . '.');
        redirect('dashboard');
    }
}

/** @return array{errores:array<string, string>, form:array<string, string>} */
function ticket_form_pendiente(): array
{
    static $datos = null;
    if ($datos === null) {
        $errores = $_SESSION['_ticket_errores'] ?? [];
        $form = $_SESSION['_ticket_form'] ?? [];
        unset($_SESSION['_ticket_errores'], $_SESSION['_ticket_form']);
        $datos = [
            'errores' => is_array($errores) ? $errores : [],
            'form'    => is_array($form) ? $form : [],
        ];
    }
    return $datos;
}
