<?php

namespace App\Services\Taxes;

/**
 * Fase 6.34 (3): aritmética decimal exacta con bcmath (texto, nunca float)
 * para importes e impuestos.
 */
final class DecimalMath
{
    /** Escala interna: 6 decimales del Anexo 20 más margen para redondear. */
    public const SCALE = 12;

    /** Normaliza un número (int, float, texto) a texto decimal sin notación científica. */
    public static function of(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            // 15 dígitos significativos: los de un double sin ruido binario.
            return rtrim(rtrim(sprintf('%.15F', round($value, 9)), '0'), '.') ?: '0';
        }

        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException("No es un número decimal: {$value}");
        }

        return $value;
    }

    /** Redondeo a `$scale` decimales, la mitad se aleja del cero (como el SAT y round() de PHP). */
    public static function round(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp($value, '0', self::SCALE) < 0
            ? bcsub($value, $half, self::SCALE)
            : bcadd($value, $half, self::SCALE);

        return bcadd($adjusted, '0', $scale);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    public static function max(string $a, string $b): string
    {
        return bccomp($a, $b, self::SCALE) >= 0 ? $a : $b;
    }
}
