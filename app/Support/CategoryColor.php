<?php

namespace App\Support;

final class CategoryColor
{
    public static function fromName(string $name): string
    {
        $hue = hexdec(substr(hash('crc32b', mb_strtolower(trim($name))), 0, 8)) % 360;

        return self::fromHsl($hue, 0.58, 0.45);
    }

    public static function fromHsl(int|float $hue, float $saturation, float $lightness): string
    {
        $h = fmod((($hue % 360) + 360) % 360, 360) / 360;
        $s = max(0.0, min(1.0, $saturation));
        $l = max(0.0, min(1.0, $lightness));

        $chroma = (1 - abs(2 * $l - 1)) * $s;
        $x = $chroma * (1 - abs(fmod($h * 6, 2) - 1));
        $m = $l - $chroma / 2;

        $r = 0.0;
        $g = 0.0;
        $b = 0.0;
        $h6 = $h * 6;

        if ($h6 < 1) {
            [$r, $g, $b] = [$chroma, $x, 0.0];
        } elseif ($h6 < 2) {
            [$r, $g, $b] = [$x, $chroma, 0.0];
        } elseif ($h6 < 3) {
            [$r, $g, $b] = [0.0, $chroma, $x];
        } elseif ($h6 < 4) {
            [$r, $g, $b] = [0.0, $x, $chroma];
        } elseif ($h6 < 5) {
            [$r, $g, $b] = [$x, 0.0, $chroma];
        } else {
            [$r, $g, $b] = [$chroma, 0.0, $x];
        }

        return sprintf(
            '#%02X%02X%02X',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        );
    }
}
