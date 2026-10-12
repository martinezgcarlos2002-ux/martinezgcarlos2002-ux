<?php
declare(strict_types=1);

/**
 * Liquidaciones masivas: un solo PDF consolidado (DomPDF una vez) o ZIP de imágenes.
 */
final class LiquidacionArchivo
{
    /**
     * Un único PDF con todas las liquidaciones. DomPDF se instancia una sola vez.
     *
     * @param list<array<string, mixed>> $documentos
     */
    public static function consolidado(array $documentos, string $desde, string $hasta, ?string $tasa): never
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $html = self::htmlConsolidado($documentos, $desde, $hasta, $tasa, date('Y-m-d H:i'));
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new RuntimeException('DomPDF no está instalado. Usa «Imprimir todas» para generar el PDF desde el navegador.');
        }

        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled'     => false,
            'isHtml5ParserEnabled' => true,
            'chroot'              => ADARA_ROOT,
        ]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        $out = $dompdf->output();
        if (!is_string($out) || !str_starts_with($out, '%PDF')) {
            throw new RuntimeException('DomPDF no devolvió un PDF válido.');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Liquidaciones_Masivas.pdf"');
        header('Content-Length: ' . strlen($out));
        header('Cache-Control: no-store');
        echo $out;
        exit;
    }

    /**
     * ZIP de imágenes (una PNG por productor). El PDF masivo ya no entra aquí.
     *
     * @param list<array<string, mixed>> $documentos
     */
    public static function zip(array $documentos, string $desde, string $hasta, string $formato, ?string $tasa): never
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        if ($formato !== 'img') {
            self::consolidado($documentos, $desde, $hasta, $tasa);
        }

        $emitida = date('Y-m-d H:i');
        $archivos = [];
        foreach ($documentos as $doc) {
            $slug = self::slug((string) $doc['productor']['nombre']) . '-' . (string) $doc['numero'];
            $archivos[$slug . '.png'] = self::pngDocumento($doc, $desde, $hasta, $tasa, $emitida);
        }

        $bytes = self::empaquetar($archivos);
        $nombre = sprintf('liquidaciones-imagenes-%s-%s.zip', $desde, $hasta);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
        echo $bytes;
        exit;
    }

    /** @param array<string, string> $archivos nombre => contenido */
    public static function empaquetar(array $archivos): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'adz');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del ZIP.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('ZipArchive no pudo crear el paquete.');
        }
        foreach ($archivos as $nombre => $contenido) {
            $zip->addFromString($nombre, $contenido);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        if ($bytes === '') {
            throw new RuntimeException('ZipArchive generó un archivo vacío.');
        }
        return $bytes;
    }

    /** @param array<string, mixed> $doc */
    private static function pdfDocumento(array $doc, string $desde, string $hasta, ?string $tasa, string $emitida): string
    {
        $html = self::htmlHoja($doc, $desde, $hasta, $tasa, $emitida);
        if (class_exists(\Dompdf\Dompdf::class)) {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->render();
            $out = $dompdf->output();
            if (!is_string($out) || !str_starts_with($out, '%PDF')) {
                throw new RuntimeException('DomPDF no devolvió un PDF válido.');
            }
            return $out;
        }
        return self::pdfNativo($doc, $desde, $hasta, $tasa, $emitida);
    }

    /** Imagen corporativa (cabecera azul, tabla y badge de saldo). Nunca el ticket de texto. */
    private static function pngDocumento(array $doc, string $desde, string $hasta, ?string $tasa, string $emitida): string
    {
        return self::pngSinGd($doc, $desde, $hasta, $tasa, $emitida);
    }

    /** @param array<string, mixed> $doc */
    private static function pngSinGd(array $doc, string $desde, string $hasta, ?string $tasa, string $emitida): string
    {
        $p = $doc['productor'];
        $fmt = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
        $w = 1000;
        $h = max(780, 360 + count($doc['entregas']) * 28 + 180);
        $img = new PngTexto($w, $h, 255, 255, 255);
        $img->rect(0, 0, $w, 132, 30, 58, 138);
        $img->rect(24, 22, 84, 84, 255, 255, 255);
        $img->texto(122, 28, 'EL CASTILLO DEL QUESO', 255, 255, 255, 2);
        $img->texto(122, 56, 'PRODUCTORA ARTESANAL DE QUESOS', 220, 230, 255, 2);
        $img->texto(122, 80, 'RIF V-286013356', 220, 230, 255, 2);
        $img->texto(122, 102, 'PERIODO ' . $fmt($desde) . ' - ' . $fmt($hasta), 255, 255, 255, 2);
        $img->texto(700, 28, 'LIQUIDACION', 255, 255, 255, 3);
        $img->texto(700, 70, 'PRODUCTOR #' . $doc['numero'], 230, 236, 255, 2);
        $img->texto(700, 94, 'EMITIDA ' . $fmt(substr($emitida, 0, 10)), 230, 236, 255, 2);

        $img->rect(24, 154, 460, 92, 244, 247, 252);
        $img->rect(508, 154, 468, 92, 244, 247, 252);
        $img->texto(40, 166, 'PRODUCTOR', 100, 116, 139, 2);
        $img->texto(40, 190, (string) $p['nombre'], 30, 58, 138, 2);
        $img->texto(40, 214, (($p['documento'] ?? '') !== '' ? 'C.I. / RIF: ' . $p['documento'] : '—'), 100, 116, 139, 2);
        $img->texto(524, 166, 'RESUMEN DEL PERIODO', 100, 116, 139, 2);
        $img->texto(524, 190, count($doc['entregas']) . ' ENTREGAS  ·  ' . num($doc['litros'], 1) . ' L', 30, 58, 138, 2);
        $img->texto(524, 214, $tasa !== null && (float) $tasa > 0 ? 'TASA 1 USD = BS. ' . num($tasa) : 'TASA —', 30, 58, 138, 2);

        $y = 270;
        $img->texto(24, $y, 'DETALLE DE ENTREGAS DE LECHE', 30, 58, 138, 2);
        $y += 28;
        $img->rect(24, $y, 952, 32, 30, 58, 138);
        $img->texto(36, $y + 8, '#   DIA        FECHA         LITROS      PRECIO/L      SUBTOTAL USD', 255, 255, 255, 2);
        $y += 36;
        $dias = [1 => 'LUN', 2 => 'MAR', 3 => 'MIE', 4 => 'JUE', 5 => 'VIE', 6 => 'SAB', 7 => 'DOM'];
        foreach ($doc['entregas'] as $i => $e) {
            $d = new DateTimeImmutable((string) $e['fecha']);
            if ($i % 2 === 1) {
                $img->rect(24, $y - 4, 952, 26, 248, 250, 252);
            }
            $img->rect(24, $y + 22, 952, 1, 232, 237, 245);
            $linea = sprintf(
                '%-3s %-10s %-12s %8s   %10s    %12s',
                (string) ($i + 1),
                $dias[(int) $d->format('N')],
                $d->format('d/m/Y'),
                num($e['litros'], 1),
                '$' . num($e['precio_litro_usd'], 4),
                '$' . num($e['subtotal_usd'])
            );
            $img->texto(36, $y, $linea, 30, 41, 59, 2);
            $y += 28;
        }
        $img->rect(24, $y, 952, 32, 238, 242, 255);
        $img->texto(36, $y + 8, 'TOTALES   ' . num($doc['litros'], 1) . ' L                    $' . num($doc['facturado_usd']), 30, 58, 138, 2);
        $y += 52;
        $img->rect(520, $y, 456, 110, 244, 247, 252);
        $img->texto(540, $y + 12, 'TOTAL FACTURADO', 71, 85, 105, 2);
        $img->texto(800, $y + 12, '$' . num($doc['facturado_usd']), 30, 41, 59, 2);
        $img->texto(540, $y + 40, 'TOTAL PAGADO', 71, 85, 105, 2);
        $img->texto(800, $y + 40, '$' . num($doc['pagado_usd']), 22, 163, 74, 2);
        $saldo = (float) $doc['saldo_usd'];
        $img->rect(536, $y + 68, 424, 32, $saldo > 0 ? 220 : 22, $saldo > 0 ? 38 : 163, $saldo > 0 ? 38 : 74);
        $img->texto(552, $y + 76, 'SALDO (USD)  $' . num($doc['saldo_usd']), 255, 255, 255, 2);

        $png = $img->png();
        if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            error_log('[ADARA] ZIP imágenes: el PNG sin GD no es válido.');
            throw new RuntimeException('No se pudo generar un PNG válido de la liquidación.');
        }
        return $png;
    }

    /** @return \GdImage|resource|null */
    private static function logoGd()
    {
        $path = ADARA_ROOT . '/assets/' . LOGO_EMPRESA;
        if (!is_file($path) || !function_exists('imagecreatefromjpeg')) {
            return null;
        }
        $img = @imagecreatefromjpeg($path);
        return $img === false ? null : $img;
    }

    /**
     * CSS ligero para DomPDF (sin flex, grid, sombras ni fuentes web).
     * Misma hoja que assets/css/reportes_pdf.css.
     */
    public static function cssPdfMotor(): string
    {
        $path = ADARA_ROOT . '/assets/css/reportes_pdf.css';
        if (!is_file($path)) {
            throw new RuntimeException('Falta assets/css/reportes_pdf.css.');
        }
        $css = (string) file_get_contents($path);
        if (trim($css) === '') {
            throw new RuntimeException('assets/css/reportes_pdf.css está vacío.');
        }
        return $css;
    }

    /**
     * HTML de todas las liquidaciones en un solo documento. El logo se resuelve una vez.
     *
     * @param list<array<string, mixed>> $documentos
     */
    public static function htmlConsolidado(array $documentos, string $desde, string $hasta, ?string $tasa, string $emitida): string
    {
        $css = self::cssPdfMotor();
        $empresaRif = defined('APP_RIF') ? APP_RIF : 'V-286013356';
        $logoSrc = self::logoLocalUri();
        $fecha = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
        $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $tiposPago = PagoProductor::ETIQUETAS;
        $conTasa = $tasa !== null && (float) $tasa > 0;
        $usd = static fn (mixed $v, int $d = 2): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v), $d);
        $bs = static fn (mixed $usdV): string => 'Bs. ' . num((float) $usdV * (float) $tasa);

        $htmlCompleto = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>'
            . $css . '</style></head><body class="pdf-motor">';

        $ultimo = count($documentos) - 1;
        foreach ($documentos as $i => $doc) {
            ob_start();
            require ADARA_ROOT . '/pages/partials/liquidacion_doc.php';
            $cuerpo = (string) ob_get_clean();
            $style = $i < $ultimo ? 'page-break-after:always' : 'page-break-after:auto';
            $htmlCompleto .= '<div class="salto-pagina" style="' . $style . '">' . $cuerpo . '</div>';
        }

        return $htmlCompleto . '</body></html>';
    }

    /** Misma estructura que pages/productores_liquidacion.php (un solo productor). */
    private static function htmlHoja(array $doc, string $desde, string $hasta, ?string $tasa, string $emitida): string
    {
        return self::htmlConsolidado([$doc], $desde, $hasta, $tasa, $emitida);
    }

    /** Empaqueta un PNG corporativo (GD) en un PDF carta. */
    private static function pdfDesdePng(string $png): string
    {
        $im = @imagecreatefromstring($png);
        if ($im === false) {
            throw new RuntimeException('GD no pudo leer el PNG de la liquidación.');
        }
        $w = imagesx($im);
        $h = imagesy($im);
        ob_start();
        imagejpeg($im, null, 92);
        imagedestroy($im);
        $jpg = (string) ob_get_clean();
        $ops = "q 562 0 0 750 16 16 cm /Im0 Do Q\n";
        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>",
            4 => "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpg) . " >>\nstream\n{$jpg}endstream",
            5 => "<< /Length " . strlen($ops) . " >>\nstream\n{$ops}endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offs = [0];
        foreach ($objects as $i => $body) {
            $offs[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offs[$i]);
        }
        $pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    private static function logoDataUri(): string
    {
        $path = ADARA_ROOT . '/assets/' . LOGO_EMPRESA;
        if (!is_file($path)) {
            return '';
        }
        return 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($path));
    }

    /** Ruta local para DomPDF (una sola vez; evita incrustar el JPEG en base64 por cada productor). */
    private static function logoLocalUri(): string
    {
        $path = ADARA_ROOT . '/assets/' . LOGO_EMPRESA;
        if (!is_file($path)) {
            return '';
        }
        return 'file:///' . str_replace('\\', '/', $path);
    }

    /** @param array<string, mixed> $doc */
    private static function pdfNativo(array $doc, string $desde, string $hasta, ?string $tasa, string $emitida): string
    {
        $p = $doc['productor'];
        $fmt = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
        $dias = [1 => 'Lun', 2 => 'Mar', 3 => 'Mie', 4 => 'Jue', 5 => 'Vie', 6 => 'Sab', 7 => 'Dom'];
        $conTasa = $tasa !== null && (float) $tasa > 0;
        $usd = static fn (mixed $v, int $d = 2): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v), $d);

        $ops = '';
        $ops .= "0.118 0.227 0.541 rg 0 742 595 100 re f\n";
        $ops .= "1 1 1 rg BT /F2 18 Tf 120 800 Td (" . self::pdfEsc('El Castillo del Queso') . ") Tj ET\n";
        $ops .= "BT /F1 9 Tf 120 784 Td (" . self::pdfEsc('Productora Artesanal de Quesos Venezolanos  RIF: V-286013356') . ") Tj ET\n";
        $ops .= "BT /F2 20 Tf 400 800 Td (" . self::pdfEsc('LIQUIDACION') . ") Tj ET\n";
        $ops .= "BT /F1 9 Tf 400 782 Td (" . self::pdfEsc('Productor #' . $doc['numero']) . ") Tj ET\n";
        $ops .= "BT /F1 9 Tf 400 768 Td (" . self::pdfEsc('Emitida: ' . $fmt(substr($emitida, 0, 10))) . ") Tj ET\n";
        if ($conTasa) {
            $ops .= "BT /F1 8 Tf 400 754 Td (" . self::pdfEsc('Tasa: 1 USD = Bs. ' . num($tasa)) . ") Tj ET\n";
        }

        $y = 720;
        $ops .= "0.957 0.969 0.988 rg 36 " . ($y - 52) . " 250 58 re f\n";
        $ops .= "0.957 0.969 0.988 rg 309 " . ($y - 52) . " 250 58 re f\n";
        $ops .= "0.392 0.455 0.545 rg BT /F1 8 Tf 48 " . ($y - 4) . " Td (" . self::pdfEsc('PRODUCTOR') . ") Tj ET\n";
        $ops .= "0.102 0.180 0.353 rg BT /F2 11 Tf 48 " . ($y - 20) . " Td (" . self::pdfEsc((string) $p['nombre']) . ") Tj ET\n";
        $ops .= "0.392 0.455 0.545 rg BT /F1 9 Tf 48 " . ($y - 36) . " Td (" . self::pdfEsc((($p['documento'] ?? '') !== '' ? 'C.I. / RIF: ' . $p['documento'] : '—')) . ") Tj ET\n";
        $ops .= "BT /F1 8 Tf 321 " . ($y - 4) . " Td (" . self::pdfEsc('RESUMEN DEL PERIODO') . ") Tj ET\n";
        $ops .= "0.102 0.180 0.353 rg BT /F1 9 Tf 321 " . ($y - 20) . " Td (" . self::pdfEsc('Periodo: ' . $fmt($desde) . ' — ' . $fmt($hasta)) . ") Tj ET\n";
        $ops .= "BT /F1 9 Tf 321 " . ($y - 36) . " Td (" . self::pdfEsc(count($doc['entregas']) . ' entregas  ·  ' . num($doc['litros'], 1) . ' L') . ") Tj ET\n";

        $y = 640;
        $ops .= "0.102 0.180 0.353 rg BT /F2 10 Tf 36 {$y} Td (" . self::pdfEsc('DETALLE DE ENTREGAS DE LECHE') . ") Tj ET\n";
        $y -= 8;
        $ops .= "0.102 0.180 0.353 rg 36 " . ($y - 16) . " 523 20 re f\n";
        $cols = $conTasa
            ? [['#', 36], ['Dia', 70], ['Fecha', 130], ['Litros', 220], ['Precio/L', 290], ['USD', 380], ['Bs.', 470]]
            : [['#', 36], ['Dia', 80], ['Fecha', 150], ['Litros', 260], ['Precio/L', 350], ['USD', 460]];
        $ops .= "1 1 1 rg\n";
        foreach ($cols as [$t, $x]) {
            $ops .= "BT /F2 8 Tf {$x} " . ($y - 10) . " Td (" . self::pdfEsc($t) . ") Tj ET\n";
        }

        $y -= 20;
        foreach ($doc['entregas'] as $i => $e) {
            if ($y < 80) {
                break;
            }
            $d = new DateTimeImmutable((string) $e['fecha']);
            if ($i % 2 === 1) {
                $ops .= "0.973 0.980 0.988 rg 36 " . ($y - 14) . " 523 18 re f\n";
            }
            $vals = [
                (string) ($i + 1),
                $dias[(int) $d->format('N')],
                $d->format('d/m/Y'),
                num($e['litros'], 1),
                $usd($e['precio_litro_usd'], 4),
                $usd($e['subtotal_usd']),
            ];
            if ($conTasa) {
                $vals[] = 'Bs. ' . num((float) $e['subtotal_usd'] * (float) $tasa);
            }
            $ops .= "0.118 0.161 0.231 rg\n";
            foreach ($cols as $c => [, $x]) {
                $ops .= "BT /F1 8 Tf {$x} " . ($y - 10) . " Td (" . self::pdfEsc((string) $vals[$c]) . ") Tj ET\n";
            }
            $y -= 18;
        }

        $ops .= "0.933 0.949 1 rg 36 " . ($y - 16) . " 523 20 re f\n";
        $ops .= "0.102 0.180 0.353 rg BT /F2 8 Tf 36 " . ($y - 10) . " Td (" . self::pdfEsc('TOTALES') . ") Tj ET\n";
        $ops .= "BT /F2 8 Tf 220 " . ($y - 10) . " Td (" . self::pdfEsc(num($doc['litros'], 1) . ' L') . ") Tj ET\n";
        $ops .= "BT /F2 8 Tf 380 " . ($y - 10) . " Td (" . self::pdfEsc($usd($doc['facturado_usd'])) . ") Tj ET\n";
        $y -= 50;

        $ops .= "0.957 0.969 0.988 rg 300 " . ($y - 70) . " 259 80 re f\n";
        $ops .= "0.118 0.161 0.231 rg BT /F1 9 Tf 312 " . ($y + 4) . " Td (" . self::pdfEsc('Total facturado') . ") Tj ET\n";
        $ops .= "BT /F2 9 Tf 460 " . ($y + 4) . " Td (" . self::pdfEsc($usd($doc['facturado_usd'])) . ") Tj ET\n";
        $ops .= "BT /F1 9 Tf 312 " . ($y - 14) . " Td (" . self::pdfEsc('Total pagado') . ") Tj ET\n";
        $ops .= "0.086 0.639 0.294 rg BT /F2 9 Tf 460 " . ($y - 14) . " Td (" . self::pdfEsc($usd($doc['pagado_usd'])) . ") Tj ET\n";
        $saldo = (float) $doc['saldo_usd'];
        $ops .= ($saldo > 0 ? "0.863 0.149 0.149" : ($saldo == 0.0 ? "0.086 0.639 0.294" : "0.102 0.180 0.353")) . " rg 300 " . ($y - 66) . " 259 28 re f\n";
        $ops .= "1 1 1 rg BT /F2 11 Tf 312 " . ($y - 50) . " Td (" . self::pdfEsc('Saldo (USD)  ' . $usd($saldo)) . ") Tj ET\n";

        $ops .= "0.580 0.639 0.722 rg BT /F1 8 Tf 36 40 Td (" . self::pdfEsc('El Castillo del Queso · Generado por ' . APP_NAME . ' el ' . (new DateTimeImmutable($emitida))->format('d/m/Y H:i')) . ") Tj ET\n";

        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>",
            4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
            5 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>",
            6 => "<< /Length " . strlen($ops) . " >>\nstream\n{$ops}endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offs = [0];
        foreach ($objects as $i => $body) {
            $offs[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offs[$i]);
        }
        $pdf .= "trailer << /Size 7 /Root 1 0 R /Info << /Title (" . self::pdfEsc('Liquidacion ' . $p['nombre']) . ") >> >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    /** @param array<string, mixed> $doc @return list<string> */
    private static function lineasEstilo(array $doc, string $desde, string $hasta, ?string $tasa, string $emitida): array
    {
        $p = $doc['productor'];
        $fmt = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
        $out = [
            'EL CASTILLO DEL QUESO — LIQUIDACION',
            'Productor: ' . $p['nombre'] . '  #' . $doc['numero'],
            'Documento: ' . (($p['documento'] ?? '') !== '' ? $p['documento'] : '—'),
            'Periodo: ' . $fmt($desde) . ' — ' . $fmt($hasta),
            $tasa !== null && (float) $tasa > 0 ? 'Tasa: 1 USD = Bs. ' . num($tasa) : 'Tasa: —',
            'Emitida: ' . $fmt(substr($emitida, 0, 10)),
            '--- DETALLE ---',
            sprintf('%-12s %8s %10s %12s', 'Fecha', 'Litros', '$/L', 'Subtotal'),
        ];
        foreach ($doc['entregas'] as $e) {
            $out[] = sprintf(
                '%-12s %8s %10s %12s',
                $fmt((string) $e['fecha']),
                num($e['litros'], 1),
                num($e['precio_litro_usd'], 4),
                num($e['subtotal_usd'])
            );
        }
        $out[] = '--- TOTALES ---';
        $out[] = 'Total litros:     ' . num($doc['litros'], 1) . ' L';
        $out[] = 'Facturado USD:    $' . num($doc['facturado_usd']);
        $out[] = 'Pagado USD:       $' . num($doc['pagado_usd']);
        $out[] = 'Saldo USD:        $' . num($doc['saldo_usd']);
        return $out;
    }

    private static function pdfEsc(string $s): string
    {
        $latin = function_exists('iconv') ? (iconv('UTF-8', 'Windows-1252//TRANSLIT', $s) ?: $s) : $s;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $latin);
    }

    private static function latin(string $s): string
    {
        $out = function_exists('iconv') ? iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) : false;
        return $out !== false ? $out : $s;
    }

    /** @param array<string, mixed> $doc */
    public static function nombrePng(array $doc): string
    {
        return 'Liquidacion_' . self::slug((string) $doc['productor']['nombre']) . '-' . (string) $doc['numero'] . '.png';
    }

    private static function slug(string $nombre): string
    {
        $s = function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre) : $nombre;
        $s = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $s));
        return trim($s, '-') !== '' ? trim($s, '-') : 'productor';
    }
}
