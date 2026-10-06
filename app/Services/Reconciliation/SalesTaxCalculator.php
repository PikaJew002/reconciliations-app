<?php

namespace App\Services\Reconciliation;

use InvalidArgumentException;

class SalesTaxCalculator
{
    public static function scaleRate(string|int|float $rate): int
    {
        $text = is_string($rate) ? trim($rate) : number_format((float) $rate, 5, '.', '');

        if (preg_match('/^(\d+)$/', $text, $whole) === 1) {
            return ((int) $whole[1]) * 100000;
        }

        if (preg_match('/^(\d+)\.(\d+)$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException('Invalid sales tax rate.');
        }

        $fraction = substr(str_pad($parts[2], 5, '0'), 0, 5);

        return ((int) $parts[1]) * 100000 + (int) $fraction;
    }

    public static function normalizeRate(string|int|float $rate): string
    {
        $scaled = self::scaleRate($rate);
        $whole = intdiv($scaled, 100000);
        $fraction = str_pad((string) ($scaled % 100000), 5, '0', STR_PAD_LEFT);

        return $whole.'.'.$fraction;
    }

    public static function cents(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Line tax in units of 0.0000001 dollars.
     */
    public static function lineNanos(int $priceCents, string|int|float $rate): int
    {
        return $priceCents * self::scaleRate($rate);
    }

    public static function roundedCents(int $priceCents, string|int|float $rate): int
    {
        $scaled = self::scaleRate($rate);

        if ($priceCents < 0) {
            return -self::roundedCents(-$priceCents, $rate);
        }

        return intdiv($priceCents * $scaled + 50000, 100000);
    }

    /**
     * Quantity in thousandths, matching order item quantities stored to 3 decimals.
     */
    public static function scaleQuantity(string|int|float $quantity): int
    {
        $text = is_string($quantity) ? trim($quantity) : number_format((float) $quantity, 3, '.', '');

        if (preg_match('/^(\d+)$/', $text, $whole) === 1) {
            return ((int) $whole[1]) * 1000;
        }

        if (preg_match('/^(\d+)\.(\d+)$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException('Invalid quantity.');
        }

        $fraction = substr(str_pad($parts[2], 3, '0'), 0, 3);

        return ((int) $parts[1]) * 1000 + (int) $fraction;
    }

    /**
     * Round tax on one unit, then apply the quantity.
     */
    public static function extendedTaxCents(int $unitCents, string|int|float $quantity, string|int|float $rate): int
    {
        $negative = $unitCents < 0;
        $unitTax = self::roundedCents(abs($unitCents), $rate);
        $cents = intdiv($unitTax * self::scaleQuantity($quantity) + 500, 1000);

        return $negative ? -$cents : $cents;
    }

    public static function formatFiveDecimals(int $nanos): string
    {
        $negative = $nanos < 0;
        $absolute = abs($nanos);
        $scaled = intdiv($absolute + 50, 100);
        $whole = intdiv($scaled, 100000);
        $fraction = str_pad((string) ($scaled % 100000), 5, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }
}
