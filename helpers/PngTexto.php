<?php
declare(strict_types=1);

/**
 * PNG RGB verdadero sin GD. Incluye un bitmap 5×7 para texto Latin-1.
 */
final class PngTexto
{
    private int $w;
    private int $h;
    /** @var string píxeles RGB packed */
    private string $px;

    public function __construct(int $w, int $h, int $r = 255, int $g = 255, int $b = 255)
    {
        $this->w = $w;
        $this->h = $h;
        $this->px = str_repeat(chr($r) . chr($g) . chr($b), $w * $h);
    }

    public function rect(int $x, int $y, int $w, int $h, int $r, int $g, int $b): void
    {
        $x2 = min($this->w - 1, $x + $w - 1);
        $y2 = min($this->h - 1, $y + $h - 1);
        $x = max(0, $x);
        $y = max(0, $y);
        $c = chr($r) . chr($g) . chr($b);
        for ($yy = $y; $yy <= $y2; $yy++) {
            $off = ($yy * $this->w + $x) * 3;
            $len = ($x2 - $x + 1);
            $this->px = substr_replace($this->px, str_repeat($c, $len), $off, $len * 3);
        }
    }

    public function texto(int $x, int $y, string $s, int $r, int $g, int $b, int $escala = 2): void
    {
        $latin = function_exists('iconv') ? (iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s) : $s;
        $latin = strtoupper($latin);
        $len = strlen($latin);
        for ($i = 0; $i < $len; $i++) {
            $this->glifo($x + $i * (6 * $escala), $y, ord($latin[$i]), $r, $g, $b, $escala);
        }
    }

    public function png(): string
    {
        $raw = '';
        for ($y = 0; $y < $this->h; $y++) {
            $raw .= "\x00" . substr($this->px, $y * $this->w * 3, $this->w * 3);
        }
        $ihdr = pack('NNCCCCC', $this->w, $this->h, 8, 2, 0, 0, 0);
        $out = "\x89PNG\r\n\x1a\n";
        $out .= self::chunk('IHDR', $ihdr);
        $out .= self::chunk('IDAT', gzcompress($raw, 6));
        $out .= self::chunk('IEND', '');
        return $out;
    }

    private function glifo(int $x, int $y, int $code, int $r, int $g, int $b, int $esc): void
    {
        $bits = self::FUENTE[$code] ?? self::FUENTE[63];
        for ($row = 0; $row < 7; $row++) {
            $line = $bits[$row];
            for ($col = 0; $col < 5; $col++) {
                if ((($line >> (4 - $col)) & 1) !== 1) {
                    continue;
                }
                $this->rect($x + $col * $esc, $y + $row * $esc, $esc, $esc, $r, $g, $b);
            }
        }
    }

    private static function chunk(string $tipo, string $data): string
    {
        return pack('N', strlen($data)) . $tipo . $data . pack('N', crc32($tipo . $data) & 0xFFFFFFFF);
    }

    /** 5 bits por fila, 7 filas. Códigos 32–126. */
    private const FUENTE = [
        32 => [0, 0, 0, 0, 0, 0, 0],
        35 => [10, 31, 10, 31, 10, 0, 0],
        36 => [4, 15, 20, 14, 5, 30, 4],
        37 => [18, 18, 4, 8, 18, 18, 0],
        40 => [4, 8, 8, 8, 8, 8, 4],
        41 => [8, 4, 4, 4, 4, 4, 8],
        43 => [0, 4, 4, 31, 4, 4, 0],
        44 => [0, 0, 0, 0, 4, 4, 8],
        45 => [0, 0, 0, 31, 0, 0, 0],
        46 => [0, 0, 0, 0, 0, 4, 0],
        47 => [1, 2, 4, 8, 16, 0, 0],
        48 => [14, 17, 19, 21, 25, 17, 14],
        49 => [4, 12, 4, 4, 4, 4, 14],
        50 => [14, 17, 1, 2, 4, 8, 31],
        51 => [14, 17, 1, 6, 1, 17, 14],
        52 => [2, 6, 10, 18, 31, 2, 2],
        53 => [31, 16, 30, 1, 1, 17, 14],
        54 => [6, 8, 16, 30, 17, 17, 14],
        55 => [31, 1, 2, 4, 8, 8, 8],
        56 => [14, 17, 17, 14, 17, 17, 14],
        57 => [14, 17, 17, 15, 1, 2, 12],
        58 => [0, 4, 0, 0, 4, 0, 0],
        63 => [14, 17, 1, 2, 4, 0, 4],
        65 => [14, 17, 17, 31, 17, 17, 17],
        66 => [30, 17, 17, 30, 17, 17, 30],
        67 => [14, 17, 16, 16, 16, 17, 14],
        68 => [30, 17, 17, 17, 17, 17, 30],
        69 => [31, 16, 16, 30, 16, 16, 31],
        70 => [31, 16, 16, 30, 16, 16, 16],
        71 => [14, 17, 16, 19, 17, 17, 14],
        72 => [17, 17, 17, 31, 17, 17, 17],
        73 => [14, 4, 4, 4, 4, 4, 14],
        74 => [1, 1, 1, 1, 17, 17, 14],
        75 => [17, 18, 20, 24, 20, 18, 17],
        76 => [16, 16, 16, 16, 16, 16, 31],
        77 => [17, 27, 21, 21, 17, 17, 17],
        78 => [17, 25, 21, 19, 17, 17, 17],
        79 => [14, 17, 17, 17, 17, 17, 14],
        80 => [30, 17, 17, 30, 16, 16, 16],
        81 => [14, 17, 17, 17, 21, 18, 13],
        82 => [30, 17, 17, 30, 20, 18, 17],
        83 => [14, 17, 16, 14, 1, 17, 14],
        84 => [31, 4, 4, 4, 4, 4, 4],
        85 => [17, 17, 17, 17, 17, 17, 14],
        86 => [17, 17, 17, 17, 17, 10, 4],
        87 => [17, 17, 17, 21, 21, 21, 10],
        88 => [17, 17, 10, 4, 10, 17, 17],
        89 => [17, 17, 10, 4, 4, 4, 4],
        90 => [31, 1, 2, 4, 8, 16, 31],
        95 => [0, 0, 0, 0, 0, 0, 31],
    ];
}
