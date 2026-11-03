<?php
declare(strict_types=1);

/**
 * Acuerdo que se muestra en el primer ingreso y en Panel Maestro → Contrato.
 * Se imprime tal cual. Su HTML se firma (SHA-256) en master_auditoria.
 */
?>
<div class="acuerdo-legal text-justify" style="font-size: 0.9em; line-height: 1.6; color: #374151; max-height: 50vh; overflow-y: auto; padding: 15px; border: 1px solid #e5e7eb; border-radius: 0.5rem; background-color: #f9fafb;">
    <h4 class="text-center mb-4 text-uppercase font-weight-bold" style="color: #111827;">Acuerdo de Confidencialidad, No Competencia y Protección de Propiedad Intelectual (Súper-Administrador SaaS)</h4>

    <p><strong>Conste por el presente documento privado</strong>, que tiene plenos efectos legales y fuerza probatoria, el acuerdo celebrado en El Sombrero, Estado Guárico, Venezuela, entre:</p>

    <p>Por una parte, <strong>CARLOS MANUEL MARTÍNEZ GUILLÉN</strong>, venezolano, mayor de edad, titular de la Cédula de Identidad Nro. V-28.601.356, de profesión <strong>Ingeniero en Informática</strong>, actuando en su carácter de <strong>Presidente</strong> de la compañía desarrolladora y Propietario Absoluto de los derechos patrimoniales y morales del sistema informático denominado <strong>"ADARA ERP"</strong> (en adelante "EL TITULAR").</p>

    <p>Y por la otra parte, <strong><?= htmlspecialchars((string) ($super_admin['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>, titular de la Cédula de Identidad Nro. <strong><?= htmlspecialchars((string) (($super_admin['cedula'] ?? '') !== '' ? $super_admin['cedula'] : '—'), ENT_QUOTES, 'UTF-8') ?></strong>, con domicilio en <strong><?= htmlspecialchars((string) (($super_admin['direccion'] ?? '') !== '' ? $super_admin['direccion'] : '—'), ENT_QUOTES, 'UTF-8') ?></strong>, quien acepta digitalmente este contrato al registrar su primer ingreso en el sistema (en adelante "EL RECEPTOR"). Ambas partes acuerdan sujetarse a las siguientes cláusulas:</p>

    <p><strong>PRIMERA: OBJETO DEL CONTRATO.</strong><br>
    EL TITULAR otorga a EL RECEPTOR credenciales de acceso con nivel de "Súper-Administrador" (Usuario Maestro) al sistema ADARA ERP y a su base de datos central. Este acceso se otorga estrictamente para fines de soporte técnico, gestión de inquilinos (empresas SaaS) y mantenimiento operativo autorizado.</p>

    <p><strong>SEGUNDA: PROPIEDAD INTELECTUAL Y MARCOS CONSTITUCIONALES.</strong><br>
    De conformidad con el <strong>Artículo 98 de la Constitución de la República Bolivariana de Venezuela</strong>, y en concordancia con los <strong>Artículos 1 y 2 de la Ley sobre el Derecho de Autor</strong> (Gaceta Oficial N° 4.638 Extraordinario del 01/10/1993), EL RECEPTOR reconoce que el 100% del código fuente, arquitectura de bases de datos, algoritmos, y la marca ADARA ERP pertenecen única y exclusivamente a CARLOS MANUEL MARTÍNEZ GUILLÉN.</p>

    <p><strong>TERCERA: CONFIDENCIALIDAD Y RESTRICCIÓN DE USO DE INTELIGENCIA ARTIFICIAL.</strong><br>
    Queda estrictamente prohibido: 1) Extraer, descargar o clonar el código fuente o las bases de datos. 2) <strong>Alimentar Modelos de Lenguaje (IA):</strong> EL RECEPTOR tiene terminantemente prohibido copiar fragmentos de código fuente, esquemas de bases de datos o lógica de negocio de ADARA ERP y pegarlos en herramientas de Inteligencia Artificial públicas con el fin de analizarlas, modificarlas o solicitar la generación de sistemas derivados.</p>

    <p><strong>CUARTA: TRAZABILIDAD Y MARCAS DE AGUA.</strong><br>
    EL RECEPTOR es consciente y acepta que EL TITULAR ha implementado mecanismos criptográficos y esteganográficos de rastreo. El código fuente, la estructura relacional y los documentos generados contienen <strong>marcas de agua digitales, firmas invisibles de código y variables de rastreo</strong> probatorias.</p>

    <p><strong>QUINTA: CLÁUSULA DE NO COMPETENCIA.</strong><br>
    EL RECEPTOR no podrá utilizar la arquitectura o ideas estructurales de ADARA ERP para desarrollar, asesorar o comercializar un sistema de planificación de recursos empresariales competidor durante la vigencia de este contrato y por un lapso de cinco (5) años posteriores a su terminación.</p>

    <p><strong>SEXTA: REVOCACIÓN Y RESPONSABILIDAD DE CREDENCIALES.</strong><br>
    Las credenciales son intransferibles. EL TITULAR se reserva el derecho absoluto de revocar el acceso de EL RECEPTOR en cualquier momento y sin previo aviso. EL RECEPTOR asume responsabilidad civil y penal plena sobre cualquier alteración realizada bajo su usuario.</p>

    <p><strong>SÉPTIMA: PENALIZACIONES Y DELITOS INFORMÁTICOS.</strong><br>
    El incumplimiento de este contrato constituirá un hecho punible penado por la <strong>Ley Especial Contra los Delitos Informáticos</strong> (Gaceta Oficial N° 37.313 del 30/10/2001), facultando a EL TITULAR para ejercer acciones penales inmediatas, específicamente por la comisión de sabotaje informático (Art. 7), acceso indebido (Art. 9) y espionaje informático (Art. 11).</p>
</div>
