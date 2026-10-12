<?php
declare(strict_types=1);

final class ValidationException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Datos inválidos');
    }
}

/** Guarda la entrada y los errores para repintar el formulario tras el redirect (patrón PRG). */
function form_fail(array $old, array $errors, string $page, array $query = []): never
{
    unset($old['_csrf'], $old['password']);
    $_SESSION['_old'] = array_filter($old, 'is_string');
    $_SESSION['_errors'] = $errors;
    flash('error', 'Revisa los campos marcados.');
    redirect($page, $query);
}

/** @return array{0: array<string, string>, 1: array<string, string>} */
function form_state(): array
{
    $state = [$_SESSION['_old'] ?? [], $_SESSION['_errors'] ?? []];
    unset($_SESSION['_old'], $_SESSION['_errors']);
    return $state;
}

function field_class(array $errors, string $field): string
{
    return isset($errors[$field]) ? 'is-invalid' : '';
}

function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<div class="invalid-feedback">' . e($errors[$field]) . '</div>' : '';
}

/**
 * Valida un decimal positivo recibido como texto (acepta coma o punto) y lo devuelve como string
 * para enviarlo a MySQL sin pasar por float.
 */
function parse_decimal(mixed $raw, int $decimals, float $max, bool $allowZero = false): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $value = str_replace(',', '.', trim($raw));
    if (!preg_match('/^\d{1,9}(\.\d{1,' . $decimals . '})?$/', $value)) {
        return null;
    }
    $float = (float) $value;
    if ($float > $max || (!$allowZero && $float <= 0)) {
        return null;
    }
    return $value;
}

/**
 * Monto escrito a mano en formato venezolano o internacional: "3.431,52", "3431,52", "3,431.52" o "3431.52".
 * Si hay coma y punto, el último separador es el decimal; con un solo tipo repetido, son miles.
 */
function parse_monto(mixed $raw, int $decimals, float $max, bool $allowZero = false): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $v = str_replace([' ', "\u{00A0}", '$', 'Bs', 'bs'], '', trim($raw));
    $coma = strrpos($v, ',');
    $punto = strrpos($v, '.');
    if ($coma !== false && $punto !== false) {
        $decimal = $coma > $punto ? ',' : '.';
        $miles = $decimal === ',' ? '.' : ',';
        $v = str_replace([$miles, $decimal], ['', '.'], $v);
    } elseif ($coma !== false) {
        $v = substr_count($v, ',') > 1 ? str_replace(',', '', $v) : str_replace(',', '.', $v);
    } elseif ($punto !== false && substr_count($v, '.') > 1) {
        $v = str_replace('.', '', $v);
    }
    return parse_decimal($v, $decimals, $max, $allowZero);
}

function parse_int_range(mixed $raw, int $min, int $max): ?int
{
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    return $value === false ? null : $value;
}

function parse_date(mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return $date && $date->format('Y-m-d') === $raw ? $raw : null;
}

/** Normaliza cédula/RIF a "V-12345678" o "J-12345678-9". Devuelve null si el formato no es válido. */
function normalize_documento(string $raw): ?string
{
    $value = strtoupper(preg_replace('/[\s.]/', '', $raw));
    if (!preg_match('/^([VEJGP])-?(\d{6,9})(?:-?(\d))?$/', $value, $m)) {
        return null;
    }
    return $m[1] . '-' . $m[2] . (isset($m[3]) && $m[3] !== '' ? '-' . $m[3] : '');
}

/** Prefijos venezolanos de cédula / RIF. */
function documento_prefijos(): array
{
    return ['V', 'J', 'E', 'G', 'P'];
}

/** Códigos de país para teléfonos. El primero (+58) es el predeterminado. */
function paises_telefono(): array
{
    return [
        '+58'  => '+58 VE',
        '+1'   => '+1 US',
        '+57'  => '+57 CO',
        '+34'  => '+34 ES',
        '+51'  => '+51 PE',
        '+593' => '+593 EC',
        '+56'  => '+56 CL',
        '+54'  => '+54 AR',
        '+55'  => '+55 BR',
        '+52'  => '+52 MX',
        '+507' => '+507 PA',
    ];
}

/** @return array{prefijo:string, numero:string} */
function split_documento(string $raw): array
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') {
        return ['prefijo' => 'V', 'numero' => ''];
    }
    $norm = normalize_documento($raw);
    if ($norm !== null) {
        return ['prefijo' => $norm[0], 'numero' => substr($norm, 2)];
    }
    if (preg_match('/^([VEJGP])-?(.*)$/', $raw, $m)) {
        return ['prefijo' => $m[1], 'numero' => preg_replace('/[^0-9-]/', '', $m[2])];
    }
    return ['prefijo' => 'V', 'numero' => preg_replace('/[^0-9-]/', '', $raw)];
}

/** Deja el número local como XXX-XXXXXXX (hasta 10 dígitos). */
function formatear_telefono_local(string $raw): string
{
    $d = preg_replace('/\D/', '', $raw);
    if (str_starts_with($d, '0') && strlen($d) === 11) {
        $d = substr($d, 1);
    }
    $d = substr($d, 0, 10);
    if (strlen($d) <= 3) {
        return $d;
    }
    return substr($d, 0, 3) . '-' . substr($d, 3);
}

/** @return array{codigo:string, numero:string} */
function split_telefono(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['codigo' => '+58', 'numero' => ''];
    }
    $codigos = array_keys(paises_telefono());
    usort($codigos, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    $compact = preg_replace('/\s+/', '', $raw);
    foreach ($codigos as $cod) {
        if (str_starts_with($compact, $cod)) {
            return ['codigo' => $cod, 'numero' => formatear_telefono_local(substr($compact, strlen($cod)))];
        }
    }
    $digitos = preg_replace('/\D/', '', $raw);
    if (str_starts_with($digitos, '58') && strlen($digitos) >= 12) {
        return ['codigo' => '+58', 'numero' => formatear_telefono_local(substr($digitos, 2))];
    }
    if (str_starts_with($digitos, '0')) {
        return ['codigo' => '+58', 'numero' => formatear_telefono_local($digitos)];
    }
    return ['codigo' => '+58', 'numero' => formatear_telefono_local($digitos)];
}

/**
 * Concatena prefijo + número si el campo compuesto llega vacío (fallback sin JS).
 * El valor oculto `documento` gana si ya viene concatenado (ej. V-280601335).
 */
function post_documento_compuesto(string $campo = 'documento', int $maxLen = 30): string
{
    $full = trim(post_string($campo, $maxLen));
    if ($full !== '' && !preg_match('/^[VEJGP]-?$/i', $full)) {
        return $full;
    }
    $pref = strtoupper(trim(post_string($campo . '_prefijo', 3)));
    $num = preg_replace('/[\s.]/', '', post_string($campo . '_numero', 16));
    if ($num === '') {
        return '';
    }
    if ($pref !== '' && !str_ends_with($pref, '-')) {
        $pref .= '-';
    }
    return $pref . $num;
}

/** Concatena código de país + número (ej. +58414-1234567). Vacío si no hay número. */
function post_telefono_compuesto(string $campo = 'telefono', int $maxLen = 30): string
{
    $full = trim(post_string($campo, $maxLen));
    $cod = trim(post_string($campo . '_codigo', 6));
    $num = trim(post_string($campo . '_numero', 16));
    if ($full !== '' && str_starts_with($full, '+') && $num !== '') {
        return $full;
    }
    if ($num !== '') {
        if ($cod === '') {
            $cod = '+58';
        }
        if (!str_starts_with($cod, '+')) {
            $cod = '+' . $cod;
        }
        return $cod . $num;
    }
    return $full !== '' && !preg_match('/^\+\d{1,3}$/', $full) ? $full : '';
}

/**
 * Select V-/J-/E-/G-/P- + input numérico. El hidden `name` recibe el valor concatenado.
 *
 * @param array{name:string, id?:string, value?:string, required?:bool, class?:string, placeholder?:string, error_html?:string} $opts
 */
function input_group_documento(array $opts): void
{
    $name = $opts['name'];
    $id = $opts['id'] ?? $name;
    $value = (string) ($opts['value'] ?? '');
    $required = !empty($opts['required']);
    $inputClass = trim('form-control js-doc-numero ' . ($opts['class'] ?? ''));
    $compacto = str_contains((string) ($opts['class'] ?? ''), 'form-control-sm');
    $selectClass = 'form-select js-doc-prefijo' . ($compacto ? ' form-select-sm' : '');
    $groupClass = 'input-group has-validation' . ($compacto ? ' input-group-sm' : '');
    $placeholder = $opts['placeholder'] ?? '280601335';
    $parts = split_documento($value);
    $compuesto = $value !== '' ? $value : ($parts['numero'] !== '' ? $parts['prefijo'] . '-' . $parts['numero'] : '');
    ?>
    <div class="js-doc-group">
      <div class="<?= e($groupClass) ?>">
        <select class="<?= e($selectClass) ?>" name="<?= e($name) ?>_prefijo" aria-label="Tipo de documento">
          <?php foreach (documento_prefijos() as $p): ?>
          <option value="<?= e($p) ?>-"<?= $parts['prefijo'] === $p ? ' selected' : '' ?>><?= e($p) ?>-</option>
          <?php endforeach; ?>
        </select>
        <input type="text" class="<?= e($inputClass) ?>" id="<?= e($id) ?>Num" name="<?= e($name) ?>_numero"
               inputmode="numeric" maxlength="12" placeholder="<?= e($placeholder) ?>" autocomplete="off"
               value="<?= e($parts['numero']) ?>"<?= $required ? ' required' : '' ?> aria-label="Número de documento">
        <?= $opts['error_html'] ?? '' ?>
      </div>
      <input type="hidden" name="<?= e($name) ?>" id="<?= e($id) ?>" class="js-doc-valor" value="<?= e($compuesto) ?>">
    </div>
    <?php
}

/**
 * Select de código de país (+58 por defecto) + número con máscara XXX-XXXXXXX.
 *
 * @param array{name:string, id?:string, value?:string, class?:string, placeholder?:string, error_html?:string} $opts
 */
function input_group_telefono(array $opts): void
{
    $name = $opts['name'];
    $id = $opts['id'] ?? $name;
    $value = (string) ($opts['value'] ?? '');
    $inputClass = trim('form-control js-tel-numero ' . ($opts['class'] ?? ''));
    $compacto = str_contains((string) ($opts['class'] ?? ''), 'form-control-sm');
    $selectClass = 'form-select js-tel-codigo' . ($compacto ? ' form-select-sm' : '');
    $groupClass = 'input-group has-validation' . ($compacto ? ' input-group-sm' : '');
    $placeholder = $opts['placeholder'] ?? '414-1234567';
    $parts = split_telefono($value);
    $compuesto = $value !== '' ? $value : ($parts['numero'] !== '' ? $parts['codigo'] . $parts['numero'] : '');
    ?>
    <div class="js-tel-group">
      <div class="<?= e($groupClass) ?>">
        <select class="<?= e($selectClass) ?>" name="<?= e($name) ?>_codigo" aria-label="Código de país">
          <?php foreach (paises_telefono() as $cod => $etiqueta): ?>
          <option value="<?= e($cod) ?>"<?= $parts['codigo'] === $cod ? ' selected' : '' ?>><?= e($etiqueta) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" class="<?= e($inputClass) ?>" id="<?= e($id) ?>Num" name="<?= e($name) ?>_numero"
               inputmode="numeric" maxlength="11" placeholder="<?= e($placeholder) ?>" autocomplete="off"
               value="<?= e($parts['numero']) ?>" aria-label="Número de teléfono">
        <?= $opts['error_html'] ?? '' ?>
      </div>
      <input type="hidden" name="<?= e($name) ?>" id="<?= e($id) ?>" class="js-tel-valor" value="<?= e($compuesto) ?>">
    </div>
    <?php
}

/** Nombre de la clave UNIQUE violada (error 1062), o null si la excepción es otra. */
function duplicate_key(PDOException $e): ?string
{
    if (($e->errorInfo[1] ?? null) !== 1062) {
        return null;
    }
    return preg_match("/for key '(?:[^.']+\.)?([^']+)'/", (string) ($e->errorInfo[2] ?? ''), $m) ? $m[1] : '';
}

/** Mensaje de un SIGNAL SQLSTATE '45000' lanzado por un trigger, o null. */
function trigger_error_message(PDOException $e): ?string
{
    return ($e->errorInfo[0] ?? null) === '45000' ? (string) ($e->errorInfo[2] ?? '') : null;
}
