<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Color;

/**
 * Color math in the perceptual OKLab space (Björn Ottosson, 2020).
 *
 * A Lab value is a list [L, a, b] with L in 0..1. Distances are returned as ΔE_OK * 100,
 * so that "2" is roughly one just-noticeable difference for large patches.
 */
final class Oklab
{
    /**
     * Viénot et al. (1999) dichromacy simulation in linear sRGB.
     */
    private const CVD_MATRICES = [
        'deutan' => [[0.29275, 0.70725, 0.0], [0.29275, 0.70725, 0.0], [-0.02234, 0.02234, 1.0]],
        'protan' => [[0.11238, 0.88762, 0.0], [0.11238, 0.88762, 0.0], [0.00401, -0.00401, 1.0]],
    ];

    public static function normalizeHex(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }
        $hex = strtolower(trim($hex));
        if (preg_match('/^#[0-9a-f]{3}$/', $hex) === 1) {
            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $hex) === 1 ? $hex : null;
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function fromHex(?string $hex): ?array
    {
        $hex = self::normalizeHex($hex);
        if ($hex === null) {
            return null;
        }

        return self::fromLinearRgb(self::hexToLinearRgb($hex));
    }

    /**
     * @param array{0: float, 1: float, 2: float} $lab
     */
    public static function toHex(array $lab): string
    {
        $rgb = self::toLinearRgb($lab);
        $out = '#';
        foreach ($rgb as $channel) {
            $channel = max(0.0, min(1.0, $channel));
            $srgb = $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * ($channel ** (1 / 2.4)) - 0.055;
            $out .= \sprintf('%02x', (int) round(max(0.0, min(1.0, $srgb)) * 255));
        }

        return $out;
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public static function fromLch(float $l, float $c, float $h): array
    {
        $rad = deg2rad($h);

        return [$l, $c * cos($rad), $c * sin($rad)];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $lab
     * @return array{0: float, 1: float, 2: float} [L, C, H in degrees]
     */
    public static function toLch(array $lab): array
    {
        $h = rad2deg(atan2($lab[2], $lab[1]));
        if ($h < 0) {
            $h += 360;
        }

        return [$lab[0], sqrt($lab[1] ** 2 + $lab[2] ** 2), $h];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $lab
     */
    public static function inGamut(array $lab): bool
    {
        foreach (self::toLinearRgb($lab) as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reduces chroma until the color fits into sRGB.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function fromLchInGamut(float $l, float $c, float $h): array
    {
        $lab = self::fromLch($l, $c, $h);
        while (!self::inGamut($lab) && $c > 0.005) {
            $c -= 0.005;
            $lab = self::fromLch($l, $c, $h);
        }

        return $lab;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     */
    public static function distance(array $a, array $b): float
    {
        return 100 * sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /**
     * Smallest hue difference in degrees (0..180).
     */
    public static function hueDistance(float $a, float $b): float
    {
        $d = fmod(abs($a - $b), 360.0);

        return $d > 180 ? 360 - $d : $d;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $lab
     * @return array{0: float, 1: float, 2: float}
     */
    public static function simulate(array $lab, string $deficiency): array
    {
        $m = self::CVD_MATRICES[$deficiency];
        $rgb = self::toLinearRgb($lab);
        $rgb = array_map(fn ($c) => max(0.0, min(1.0, $c)), $rgb);
        $sim = [];
        for ($i = 0; $i < 3; $i++) {
            $sim[$i] = $m[$i][0] * $rgb[0] + $m[$i][1] * $rgb[1] + $m[$i][2] * $rgb[2];
        }

        return self::fromLinearRgb($sim);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function hexToLinearRgb(string $hex): array
    {
        $rgb = [];
        for ($i = 0; $i < 3; $i++) {
            $c = hexdec(substr($hex, 1 + $i * 2, 2)) / 255;
            $rgb[$i] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return $rgb;
    }

    /**
     * @param array<int, float> $rgb
     * @return array{0: float, 1: float, 2: float}
     */
    private static function fromLinearRgb(array $rgb): array
    {
        [$r, $g, $b] = $rgb;
        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $lab
     * @return array{0: float, 1: float, 2: float}
     */
    private static function toLinearRgb(array $lab): array
    {
        [$L, $a, $b] = $lab;
        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    private static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }
}
