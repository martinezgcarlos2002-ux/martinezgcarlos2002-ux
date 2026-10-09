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
