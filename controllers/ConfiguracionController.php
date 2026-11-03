<?php
declare(strict_types=1);

/** Marca blanca: nombre, datos de contacto, colores, logo y sello. Solo administradores. */
final class ConfiguracionController
{
    public function index(): void
    {
        view('configuracion', [
            'marca' => marca(),
            'errores' => [],
        ]);
    }

    public function guardar(): void
    {
        $datos = [
            'nombre_empresa' => post_string('nombre_empresa', 120),
            'rif_documento' => post_string('rif_documento', 20),
            'telefono' => post_string('telefono', 30),
            'direccion' => post_string('direccion', 180),
            'color_primario' => post_string('color_primario', 7),
            'color_secundario' => post_string('color_secundario', 7),
        ];
        try {
            (new EmpresaConfig(get_db()))->guardar($datos, [
                'logo' => $_FILES['logo'] ?? null,
                'sello' => $_FILES['sello'] ?? null,
            ]);
        } catch (ValidationException $e) {
            view('configuracion', [
                'marca' => array_merge(marca(), $datos),
                'errores' => $e->errors,
            ]);
            return;
        }
        audit_log('configuracion', 'marca', [
            'nombre_empresa' => $datos['nombre_empresa'],
            'color_primario' => $datos['color_primario'],
            'color_secundario' => $datos['color_secundario'],
        ], 'empresa_configuracion', 1);
        flash('success', 'La marca de la empresa quedó guardada.');
        redirect('configuracion');
    }
}
