<?php
declare(strict_types=1);

/**
 * Matemáticas de nómina: faltas (F), libres excedidos, flete de días y resto en caja.
 * Los montos se trabajan en centavos para no perder precisión.
 */
final class NominaService
{
    public const DIAS_CICLO = 7;

    /**
     * Recorre el rango lunes-domingo y clasifica cada día.
     * F / no_trabajado siempre descuenta. L solo si ya se agotó el cupo mensual.
     *
     * @param array<string, mixed> $emp
     * @param array<string, string> $marcas Y-m-d => libre|no_trabajado
     * @param array<string, true> $excedidos Y-m-d de libres fuera de cupo
     * @return array{ausencia:int, excedido:int, fuera:int, dias:int}
     */
    public static function contarDiasNoTrabajados(array $emp, string $inicio, string $fin, array $marcas, array $excedidos): array
    {
        $ausencia = 0;
        $excedido = 0;
        $fuera = 0;
        $fechaFin = new DateTimeImmutable($fin);
        for ($d = new DateTimeImmutable($inicio); $d <= $fechaFin; $d = $d->modify('+1 day')) {
            $fecha = $d->format('Y-m-d');
            if (!DiaLibre::enContrato($emp, $fecha)) {
                $fuera++;
            } elseif (($marcas[$fecha] ?? null) === 'no_trabajado') {
                $ausencia++;
            } elseif (($marcas[$fecha] ?? null) === 'libre' && isset($excedidos[$fecha])) {
                $excedido++;
            }
        }
        return [
            'ausencia' => $ausencia,
            'excedido' => $excedido,
            'fuera'    => $fuera,
            'dias'     => $ausencia + $excedido + $fuera,
        ];
    }

    /** Salario diario en centavos = semanal / 7, redondeo bancario a 2 decimales vía centavos del desc. */
    public static function salarioDiarioUsd(int $salarioSemanalCentavos): string
    {
        return number_format($salarioSemanalCentavos / (100 * self::DIAS_CICLO), 4, '.', '');
    }

    /** Descuento por días = días × (salario semanal / 7), redondeado al céntimo más cercano. */
    public static function descDiasCentavos(int $salarioSemanalCentavos, int $diasNoTrabajados): int
    {
        return intdiv($salarioSemanalCentavos * $diasNoTrabajados * 2 + self::DIAS_CICLO, self::DIAS_CICLO * 2);
    }

    /**
     * Neto oficial y resto de caja.
     * neto  = semanal − desc. días + extras − vales − cuota préstamo
     * resto = neto − cobranzas a terceros (afecta Resto a pagar Bs)
     *
     * @return array<string, mixed>
     */
    public static function liquidar(
        int $salarioC,
        int $dias,
        int $extrasC,
        int $valesC,
        int $deudaC,
        int $cuotaC,
        int $cobroC,
        ?string $tasa
    ): array {
        $descC = self::descDiasCentavos($salarioC, $dias);
        $netoC = $salarioC - $descC + $extrasC - $valesC - $cuotaC;
        $restoC = $netoC - $cobroC;
        return [
            'desc_c'  => $descC,
            'neto_c'  => $netoC,
            'resto_c' => $restoC,
            'diario'  => self::salarioDiarioUsd($salarioC),
            'monto_bs' => $tasa === null ? null : self::aBs($netoC, $tasa),
            'resto_bs' => $tasa === null ? null : self::restoBsDesdeDeudaUsd($netoC, $cobroC, $tasa),
        ];
    }

    /**
     * La cobranza se guarda en USD. Al cerrar/pagar la nómina se convierte con la tasa del momento
     * y se resta del resto a pagar en bolívares.
     */
    public static function restoBsDesdeDeudaUsd(int $netoCentavos, int $cobranzaUsdCentavos, string $tasaPago): string
    {
        return self::aBs($netoCentavos - $cobranzaUsdCentavos, $tasaPago);
    }

    public static function aBs(int $centavosUsd, string $tasa): string
    {
        return number_format(round($centavosUsd * (float) $tasa / 100, 2), 2, '.', '');
    }
}
