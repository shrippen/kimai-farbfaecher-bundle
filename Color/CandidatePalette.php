<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Color;

/**
 * Generates candidate colors on an OKLCH grid (hue × lightness × chroma).
 * Chroma is reduced where a color would leave sRGB.
 */
final class CandidatePalette
{
    /** @var array<string, list<Candidate>> */
    private static array $cache = [];

    /**
     * Hues sit on a global grid (multiples of the step), so overlapping ranges share the same candidates.
     *
     * @param float $hueFrom start hue in degrees, may be negative (wraps)
     * @param float $hueTo end hue in degrees, must be >= $hueFrom
     * @param list<float> $lightness
     * @param list<float> $chroma
     * @return list<Candidate>
     */
    public static function generate(float $hueFrom, float $hueTo, float $hueStep, array $lightness, array $chroma): array
    {
        if ($hueTo - $hueFrom >= 359.9) {
            $first = 0;
            $last = (int) ceil(360 / $hueStep) - 1;
        } else {
            $first = (int) ceil($hueFrom / $hueStep);
            $last = max($first, (int) floor($hueTo / $hueStep));
        }

        $hues = [];
        for ($i = $first; $i <= $last; $i++) {
            $hues[] = $i * $hueStep;
        }

        return self::atHues($hues, $lightness, $chroma);
    }

    /**
     * @param list<float> $hues in degrees, wrapped into 0..360
     * @param list<float> $lightness
     * @param list<float> $chroma
     * @return list<Candidate>
     */
    public static function atHues(array $hues, array $lightness, array $chroma): array
    {
        $key = implode(',', array_map(fn (float $h) => round($h, 2), $hues)) . '|' . implode(',', $lightness) . '|' . implode(',', $chroma);
        if (\array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $seen = [];
        $result = [];
        foreach ($hues as $hue) {
            $hue = fmod($hue + 720.0, 360.0);
            foreach ($lightness as $l) {
                foreach ($chroma as $c) {
                    $lab = Oklab::fromLchInGamut($l, $c, $hue);
                    $hex = Oklab::toHex($lab);
                    if (isset($seen[$hex])) {
                        continue;
                    }
                    $seen[$hex] = true;

                    // round-trip through hex, so distances are computed on what will be stored
                    $result[] = new Candidate($hex, Oklab::fromHex($hex) ?? $lab);
                }
            }
        }

        return self::$cache[$key] = $result;
    }
}
