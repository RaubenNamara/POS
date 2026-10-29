<?php

namespace App\Support;

/**
 * Exact decimal arithmetic for money and costs (bcmath, string in / string out).
 * Never use PHP floats for financial values; everything that touches a total goes through here.
 */
final class Money
{
    public const SCALE = 2;
    public const COST_SCALE = 4;

    public static function of(string|int|float|null $v, int $scale = self::SCALE): string
    {
        if ($v === null || $v === '') {
            return self::round('0', $scale);
        }
        if (is_float($v)) {
            // Only reached for literals/config; format first so bcmath gets a plain decimal string.
            $v = number_format($v, 8, '.', '');
        }
        $v = trim((string) $v);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            throw new \InvalidArgumentException("Not a decimal amount: {$v}");
        }

        return self::round($v, $scale);
    }

    public static function add(string ...$vals): string
    {
        $sum = '0';
        foreach ($vals as $v) {
            $sum = bcadd($sum, $v, 8);
        }

        return self::round($sum);
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::round(bcsub($a, $b, 8), $scale);
    }

    public static function mul(string $a, string|int $b, int $scale = self::SCALE): string
    {
        return self::round(bcmul($a, (string) $b, 8), $scale);
    }

    public static function div(string $a, string|int $b, int $scale = self::SCALE): string
    {
        if (bccomp((string) $b, '0', 8) === 0) {
            throw new \DivisionByZeroError('Money division by zero');
        }

        return self::round(bcdiv($a, (string) $b, 10), $scale);
    }

    /** Percentage of an amount: pct("10000", "18") is "1800.00". */
    public static function pct(string $a, string $percent): string
    {
        return self::round(bcdiv(bcmul($a, $percent, 10), '100', 10));
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, 8);
    }

    public static function isZero(string $a): bool
    {
        return bccomp($a, '0', 8) === 0;
    }

    public static function isNegative(string $a): bool
    {
        return bccomp($a, '0', 8) < 0;
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? $a : $b;
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? $a : $b;
    }

    /** Round half away from zero (bcmath on its own only truncates). */
    public static function round(string $v, int $scale = self::SCALE): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';
        $v = str_starts_with($v, '-') ? bcsub($v, $offset, $scale + 1) : bcadd($v, $offset, $scale + 1);
        $r = bcadd($v, '0', $scale);
        $zero = bcadd('0', '0', $scale);

        return $r === '-'.$zero ? $zero : $r;
    }

    /**
     * Weighted-average unit cost after receiving $inQty units at $inCost onto $oldQty at $oldCost.
     * If existing stock is zero/negative the new cost simply replaces the old one.
     */
    public static function weightedAverage(int $oldQty, string $oldCost, int $inQty, string $inCost): string
    {
        if ($inQty <= 0) {
            return self::round($oldCost, self::COST_SCALE);
        }
        if ($oldQty <= 0) {
            return self::round($inCost, self::COST_SCALE);
        }
        $value = bcadd(bcmul((string) $oldQty, $oldCost, 8), bcmul((string) $inQty, $inCost, 8), 8);

        return self::div($value, (string) ($oldQty + $inQty), self::COST_SCALE);
    }
}
