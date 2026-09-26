<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Color;

/**
 * Which colors may be suggested at all.
 *
 *   free   : whole sRGB space in a readable lightness band
 *   themed : derived from Kimai's color list (e.g. Knust/Gruvbox):
 *            hues of the list (± a few degrees), lightness within the list's range and a chroma that
 *            follows the list per hue. Gruvbox blues are muted, its oranges strong – suggestions do the same.
 *
 *   chroma
 *     ▲        ╭──╮ orange/red
 *     │   ╭────╯  ╰──╮                 interpolated between the list colors (circular over the hue)
 *     │───╯          ╰────── blue/aqua
 *     └──────────────────────────► hue
 */
final class ColorStyle
{
    private const FREE_LIGHTNESS = [0.45, 0.55, 0.65, 0.75, 0.85];
    private const FREE_CHROMA = [0.08, 0.14, 0.20];
    private const FREE_STEP = 8.0;

    /** customers carry the hue, so they get strong, evenly bright anchor colors */
    private const CUSTOMER_LIGHTNESS = [0.58, 0.68];
    private const CUSTOMER_CHROMA = [0.16];
    private const CUSTOMER_STEP = 3.0;

    /** projects/activities inside a customer sector */
    private const SECTOR_STEP = 3.0;

    /** themed: variations around each hue of the list */
    private const ANCHOR_JITTER = [-6.0, -3.0, 0.0, 3.0, 6.0];
    /** themed: chroma levels relative to the list's chroma at that hue */
    private const CHROMA_FACTORS = [0.6, 0.8, 1.0];
    /** themed customers: only the stronger chroma levels, they carry the hue */
    private const CUSTOMER_FACTORS = [0.8, 1.0];
    /** below this chroma a color is a gray and has no usable hue */
    private const MIN_CHROMA = 0.04;
    private const MIN_ANCHORS = 3;
    /** lightness band in which Kimai's contrast font (black or white) stays readable */
    private const MIN_LIGHTNESS = 0.40;
    private const MAX_LIGHTNESS = 0.88;
    private const LIGHTNESS_STEPS = 5;
    /** a limited palette offers at least this many colors to a project/activity of a customer */
    private const MIN_SECTOR_COLORS = 3;

    /**
     * @param list<float> $lightness
     * @param list<Candidate> $anchors colorful entries of the color list, sorted by hue; empty = free style
     * @param list<array{0: float, 1: float}> $chromaByHue [hue, chroma] of the anchors, sorted by hue
     * @param list<Candidate>|null $fixed limited palette (see withSize()), null = every candidate of the style
     */
    private function __construct(
        private readonly array $lightness,
        private readonly array $anchors,
        private readonly array $chromaByHue,
        private readonly ?array $fixed = null,
    ) {
    }

    public static function free(): self
    {
        return new self(self::FREE_LIGHTNESS, [], []);
    }

    /**
     * Falls back to the free style if the list has too few colorful entries.
     *
     * @param list<string> $hexColors
     */
    public static function fromPalette(array $hexColors): self
    {
        $anchors = [];
        foreach ($hexColors as $hex) {
            $hex = Oklab::normalizeHex($hex);
            $lab = Oklab::fromHex($hex);
            if ($hex === null || $lab === null || Oklab::toLch($lab)[1] < self::MIN_CHROMA) {
                continue;
            }
            $anchors[$hex] = new Candidate($hex, $lab);
        }
        if (\count($anchors) < self::MIN_ANCHORS) {
            return self::free();
        }

        $anchors = array_values($anchors);
        usort($anchors, fn (Candidate $a, Candidate $b) => Oklab::toLch($a->lab)[2] <=> Oklab::toLch($b->lab)[2]);
        $lch = array_map(fn (Candidate $c) => Oklab::toLch($c->lab), $anchors);
        $chromaByHue = array_map(fn (array $l) => [$l[2], $l[1]], $lch);

        return new self(self::lightnessRange(array_column($lch, 0)), $anchors, $chromaByHue);
    }

    public function isThemed(): bool
    {
        return \count($this->anchors) > 0;
    }

    /**
     * Limits the style to $size colors that are as far apart as possible (farthest point sampling):
     *
     *   1. the list colors first (themed), spread out if there are more of them than $size
     *   2. then repeatedly the candidate whose nearest chosen color is farthest away
     *
     * Fewer colors = easier to tell apart, but repeated more often. 0 = no limit.
     */
    public function withSize(int $size): self
    {
        if ($size <= 0) {
            return $this;
        }

        // seeds first, so phase 1 can be restricted to them by index
        $pool = [];
        foreach (array_merge($this->anchors, $this->all(), $this->customers()) as $candidate) {
            $pool[$candidate->hex] = $candidate;
        }
        $pool = array_values($pool);
        $seedCount = max(1, \count($this->anchors));

        $nearest = array_fill(0, \count($pool), INF);
        $chosen = [];
        while (\count($chosen) < min($size, \count($pool))) {
            $limit = \count($chosen) < $seedCount ? $seedCount : \count($pool);
            $next = $this->farthest($nearest, $limit, $chosen);
            $chosen[$next] = $pool[$next];

            // keep each candidate's distance to its nearest chosen color up to date
            foreach ($pool as $i => $candidate) {
                $nearest[$i] = min($nearest[$i], Oklab::distance($candidate->lab, $pool[$next]->lab));
            }
        }

        return new self($this->lightness, $this->anchors, $this->chromaByHue, array_values($chosen));
    }

    public function isLimited(): bool
    {
        return $this->fixed !== null;
    }

    /**
     * The limited palette, empty if not limited.
     *
     * @return list<Candidate>
     */
    public function colors(): array
    {
        return $this->fixed ?? [];
    }

    /**
     * Candidates without any hue restriction.
     *
     * @return list<Candidate>
     */
    public function all(): array
    {
        if ($this->fixed !== null) {
            return $this->fixed;
        }
        if (!$this->isThemed()) {
            return CandidatePalette::generate(0, 360, self::FREE_STEP, $this->lightness, self::FREE_CHROMA);
        }

        return $this->withAnchors($this->atHues($this->jitteredHues(), $this->lightness));
    }

    /**
     * Candidates for customers: strong colors, the hue is inherited by their projects.
     *
     * @return list<Candidate>
     */
    public function customers(): array
    {
        if ($this->fixed !== null) {
            return $this->fixed;
        }
        if (!$this->isThemed()) {
            return CandidatePalette::generate(0, 360, self::CUSTOMER_STEP, self::CUSTOMER_LIGHTNESS, self::CUSTOMER_CHROMA);
        }

        // the list colors themselves first, strong variations of their hues for more customers than colors
        return $this->withAnchors($this->atHues($this->jitteredHues(), $this->lightness, self::CUSTOMER_FACTORS));
    }

    /**
     * Candidates within a hue range, e.g. the sector of a customer.
     *
     * @return list<Candidate>
     */
    public function sector(float $hueFrom, float $hueTo): array
    {
        if ($this->fixed !== null) {
            return $this->fixedSector($hueFrom, $hueTo);
        }
        if (!$this->isThemed()) {
            return CandidatePalette::generate($hueFrom, $hueTo, self::SECTOR_STEP, $this->lightness, self::FREE_CHROMA);
        }

        $hues = [];
        for ($i = (int) ceil($hueFrom / self::SECTOR_STEP); $i <= (int) floor($hueTo / self::SECTOR_STEP); $i++) {
            $hues[] = $i * self::SECTOR_STEP;
        }

        return $this->atHues($hues, $this->lightness);
    }

    /**
     * Palette colors inside the hue range; if there are too few, the ones with the nearest hue.
     *
     * @return list<Candidate>
     */
    private function fixedSector(float $hueFrom, float $hueTo): array
    {
        $center = ($hueFrom + $hueTo) / 2;
        $halfWidth = ($hueTo - $hueFrom) / 2;
        $colorful = array_values(array_filter($this->fixed ?? [], fn (Candidate $c) => Oklab::toLch($c->lab)[1] >= self::MIN_CHROMA));
        $distance = fn (Candidate $c) => Oklab::hueDistance(Oklab::toLch($c->lab)[2], $center);

        $inside = array_values(array_filter($colorful, fn (Candidate $c) => $distance($c) <= $halfWidth));
        if (\count($inside) >= self::MIN_SECTOR_COLORS) {
            return $inside;
        }

        usort($colorful, fn (Candidate $a, Candidate $b) => $distance($a) <=> $distance($b));

        return \array_slice($colorful, 0, self::MIN_SECTOR_COLORS);
    }

    /**
     * Index of the not yet chosen candidate (among the first $limit) that is farthest from all chosen colors.
     *
     * @param list<float> $nearest distance of each candidate to its nearest chosen color
     * @param array<int, Candidate> $chosen by index
     */
    private function farthest(array $nearest, int $limit, array $chosen): int
    {
        $best = 0;
        $bestDistance = -1.0;
        for ($i = 0; $i < $limit; $i++) {
            if (isset($chosen[$i]) || $nearest[$i] <= $bestDistance) {
                continue;
            }
            $best = $i;
            $bestDistance = $nearest[$i];
        }

        return $best;
    }

    /**
     * Chroma of the list at a hue, linearly interpolated between the two neighboring list colors.
     */
    private function chromaAt(float $hue): float
    {
        $hue = fmod($hue + 720.0, 360.0);
        $count = \count($this->chromaByHue);

        for ($i = 0; $i < $count; $i++) {
            [$h1, $c1] = $this->chromaByHue[$i];
            [$h2, $c2] = $this->chromaByHue[($i + 1) % $count];
            // last segment wraps around 360°
            $end = $h2 > $h1 ? $h2 : $h2 + 360;
            $at = $hue >= $h1 ? $hue : $hue + 360;
            if ($at >= $h1 && $at <= $end) {
                $t = $end - $h1 < 0.001 ? 0.0 : ($at - $h1) / ($end - $h1);

                return max(self::MIN_CHROMA, $c1 + ($c2 - $c1) * $t);
            }
        }

        return max(self::MIN_CHROMA, $this->chromaByHue[0][1]);
    }

    /**
     * @param list<float> $hues
     * @param list<float> $lightness
     * @param list<float> $factors chroma relative to the list at each hue
     * @return list<Candidate>
     */
    private function atHues(array $hues, array $lightness, array $factors = self::CHROMA_FACTORS): array
    {
        $result = [];
        foreach ($hues as $hue) {
            $chroma = array_map(fn (float $f) => round($f * $this->chromaAt($hue), 3), $factors);
            foreach (CandidatePalette::atHues([$hue], $lightness, $chroma) as $candidate) {
                $result[$candidate->hex] = $candidate;
            }
        }

        return array_values($result);
    }

    /**
     * @return list<float>
     */
    private function jitteredHues(): array
    {
        $hues = [];
        foreach ($this->chromaByHue as [$hue]) {
            foreach (self::ANCHOR_JITTER as $offset) {
                $hues[] = $hue + $offset;
            }
        }

        return $hues;
    }

    /**
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    private function withAnchors(array $candidates): array
    {
        $known = array_flip(array_map(fn (Candidate $c) => $c->hex, $this->anchors));

        return array_merge($this->anchors, array_values(array_filter($candidates, fn (Candidate $c) => !isset($known[$c->hex]))));
    }

    /**
     * Evenly spaced lightness levels covering the list, clamped to the readable band.
     * Example: Gruvbox (0.55 … 0.83) → 0.55, 0.62, 0.69, 0.76, 0.83
     *
     * @param list<float> $values
     * @return list<float>
     */
    private static function lightnessRange(array $values): array
    {
        $min = max(self::MIN_LIGHTNESS, min($values));
        $max = min(self::MAX_LIGHTNESS, max($values));

        // a narrow list would leave too few distinguishable levels
        if ($max - $min < 0.2) {
            $center = ($min + $max) / 2;
            $min = max(self::MIN_LIGHTNESS, $center - 0.1);
            $max = min(self::MAX_LIGHTNESS, $center + 0.1);
        }

        $levels = [];
        for ($i = 0; $i < self::LIGHTNESS_STEPS; $i++) {
            $levels[] = round($min + ($max - $min) * $i / (self::LIGHTNESS_STEPS - 1), 3);
        }

        return $levels;
    }
}
