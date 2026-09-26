<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Service;

use KimaiPlugin\FarbfaecherBundle\Color\Candidate;
use KimaiPlugin\FarbfaecherBundle\Color\ColorStyle;
use KimaiPlugin\FarbfaecherBundle\Color\Oklab;
use KimaiPlugin\FarbfaecherBundle\Color\RankingContext;
use KimaiPlugin\FarbfaecherBundle\Configuration\FarbfaecherConfiguration;
use KimaiPlugin\FarbfaecherBundle\Model\Clash;
use KimaiPlugin\FarbfaecherBundle\Model\ColorGraph;
use KimaiPlugin\FarbfaecherBundle\Model\ColorNode;

/**
 * Clash detection, candidate generation and ranking.
 *
 * Ranking minimizes  Σ weight(n) · max(0, 2T − ΔE(candidate, n))²  over all neighbors n of the same type,
 * so colors are pushed well beyond the clash threshold T, most strongly away from entities
 * that are used together or sit next to each other (siblings).
 */
final class ColorEngine
{
    /** below this chroma a color is a gray and has no usable hue */
    private const GRAY_CHROMA = 0.04;
    /** activities stay within ± this many degrees of their project's hue */
    private const ACTIVITY_HALF_WIDTH = 14.0;
    /** clash hints shown below the color field */
    private const MAX_FIELD_CLASHES = 8;

    /** @var array<string, list<array{0: float, 1: float, 2: float}>> */
    private array $variantCache = [];
    private ?ColorStyle $style = null;

    public function __construct(private readonly FarbfaecherConfiguration $configuration)
    {
    }

    public function getConfiguration(): FarbfaecherConfiguration
    {
        return $this->configuration;
    }

    /**
     * @param array<string, string> $overrides planned colors by node key
     * @return list<Clash> sorted by severity, worst first
     */
    public function analyze(ColorGraph $graph, array $overrides = []): array
    {
        $threshold = $this->configuration->getThreshold();
        $includeHidden = $this->configuration->isIncludeHidden();
        $clashes = [];

        foreach (ColorNode::TYPES as $type) {
            $nodes = array_values($graph->byType($type, $includeHidden));
            $colors = [];
            $variants = [];
            foreach ($nodes as $i => $node) {
                $colors[$i] = $graph->effectiveColor($node->getKey(), $overrides);
                $variants[$i] = $this->variants($colors[$i]);
            }

            $count = \count($nodes);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $distance = $this->distance($variants[$i], $variants[$j]);
                    if ($distance >= $threshold) {
                        continue;
                    }
                    $weight = $graph->weight($nodes[$i], $nodes[$j]);
                    if ($weight <= 0) {
                        continue;
                    }
                    $normal = Oklab::distance($variants[$i][0], $variants[$j][0]);
                    $clashes[] = new Clash(
                        $nodes[$i],
                        $nodes[$j],
                        $colors[$i],
                        $colors[$j],
                        $distance,
                        $weight,
                        $graph->getCooccurrence($nodes[$i]->getKey(), $nodes[$j]->getKey()),
                        $graph->isSibling($nodes[$i], $nodes[$j]),
                        $normal >= $threshold,
                        $threshold,
                    );
                }
            }
        }

        usort($clashes, fn (Clash $a, Clash $b) => $b->getSeverity() <=> $a->getSeverity());

        return $clashes;
    }

    /**
     * Colors that fit the node under the given strategy.
     *
     * @param array<string, string> $overrides
     * @return list<Candidate>
     */
    public function candidatesFor(ColorGraph $graph, ColorNode $node, array $overrides, ?string $strategy = null): array
    {
        $strategy ??= $this->configuration->getStrategy();
        $style = $this->getStyle();

        if ($strategy !== FarbfaecherConfiguration::STRATEGY_HIERARCHICAL) {
            return $style->all();
        }

        if ($node->type === ColorNode::CUSTOMER) {
            return $style->customers();
        }

        // global activities (and projects without customer) describe the kind of work, independent of customers
        $parent = $node->parentKey;
        if ($parent === null || !$graph->has($parent)) {
            return $style->all();
        }

        // grays have no hue a child could follow
        [, $chroma, $hue] = Oklab::toLch($graph->effectiveLab($parent, $overrides));
        if ($chroma < self::GRAY_CHROMA) {
            return $style->all();
        }

        // project: the customer's sector; activity: a narrower band around its project
        $halfWidth = $this->sectorHalfWidth($graph, $parent, $overrides);
        if ($node->type === ColorNode::ACTIVITY) {
            $customer = $graph->get($parent)->parentKey;
            $halfWidth = self::ACTIVITY_HALF_WIDTH;
            if ($customer !== null && $graph->has($customer)) {
                $halfWidth = min($halfWidth, 0.6 * $this->sectorHalfWidth($graph, $customer, $overrides));
            }
        }

        return $style->sector($hue - $halfWidth, $hue + $halfWidth);
    }

    /**
     * Built once per request from the palette settings (color list or free, optionally limited in size).
     */
    public function getStyle(): ColorStyle
    {
        if ($this->style !== null) {
            return $this->style;
        }

        $style = $this->configuration->getPalette() === FarbfaecherConfiguration::PALETTE_FREE
            ? ColorStyle::free()
            : ColorStyle::fromPalette($this->configuration->getThemeColors());

        return $this->style = $style->withSize($this->configuration->getPaletteSize());
    }

    /**
     * Half of the hue range "owned" by a customer: half the way to the next customer hue, clamped to 8°..40°.
     *
     * @param array<string, string> $overrides
     */
    public function sectorHalfWidth(ColorGraph $graph, string $customerKey, array $overrides = []): float
    {
        [, , $hue] = Oklab::toLch($graph->effectiveLab($customerKey, $overrides));
        $nearest = 360.0;
        foreach ($graph->byType(ColorNode::CUSTOMER, $this->configuration->isIncludeHidden()) as $key => $other) {
            if ($key === $customerKey) {
                continue;
            }
            [, $chroma, $otherHue] = Oklab::toLch($graph->effectiveLab($key, $overrides));
            if ($chroma < self::GRAY_CHROMA) {
                continue;
            }
            $nearest = min($nearest, Oklab::hueDistance($hue, $otherHue));
        }

        return max(8.0, min(40.0, $nearest / 2));
    }

    /**
     * Colors are pushed to twice the clash threshold, so they spread out instead of just barely passing.
     */
    public function createContext(ColorGraph $graph): RankingContext
    {
        return new RankingContext($graph, 2 * $this->configuration->getThreshold(), fn (string $hex) => $this->variants($hex));
    }

    /**
     * Suggestions and clash hints for a single entity, e.g. while editing it.
     *
     * @return array{suggestions: list<string>, clashes: list<array{path: string, color: string, distance: float, weeks: int, sibling: bool, level: string, severity: float}>}
     */
    public function suggest(ColorGraph $graph, ColorNode $node, ?string $currentColor, int $count = 6): array
    {
        $threshold = $this->configuration->getThreshold();
        $context = $this->createContext($graph);
        $neighborVariants = [];
        foreach ($graph->byType($node->type, $this->configuration->isIncludeHidden()) as $key => $other) {
            if ($key !== $node->getKey()) {
                $color = $graph->effectiveColor($key);
                $neighborVariants[$key] = $this->variants($color);
                $context->place($other, $color);
            }
        }

        $ranked = $context->rank($node, $this->candidatesFor($graph, $node, []));
        $suggestions = [];
        $picked = [];
        $current = Oklab::fromHex($currentColor);
        foreach ($ranked as $entry) {
            $lab = $entry['candidate']->lab;
            // suggesting (almost) the color that is already entered is useless
            if ($current !== null && Oklab::distance($lab, $current) < 2) {
                continue;
            }
            foreach ($picked as $other) {
                if (Oklab::distance($lab, $other) < $threshold / 2) {
                    continue 2;
                }
            }
            $picked[] = $lab;
            $suggestions[] = $entry['candidate']->hex;
            if (\count($suggestions) >= $count) {
                break;
            }
        }

        $clashes = [];
        $currentColor = Oklab::normalizeHex($currentColor);
        if ($currentColor !== null) {
            $own = $this->variants($currentColor);
            foreach ($neighborVariants as $key => $variants) {
                $d = $this->distance($own, $variants);
                if ($d >= $threshold) {
                    continue;
                }
                $other = $graph->get($key);
                $weeks = $graph->getCooccurrence($node->getKey(), $key);
                $clash = new Clash($node, $other, $currentColor, $graph->effectiveColor($key), $d, $graph->weight($node, $other), $weeks, $graph->isSibling($node, $other), false, $threshold);
                $clashes[] = [
                    'path' => $graph->path($key),
                    'color' => $clash->colorB,
                    'distance' => round($d, 1),
                    'weeks' => $weeks,
                    'sibling' => $clash->siblings,
                    'level' => $clash->getLevel(),
                    'severity' => $clash->getSeverity(),
                ];
            }
            usort($clashes, fn (array $a, array $b) => $b['severity'] <=> $a['severity']);
            $clashes = \array_slice($clashes, 0, self::MAX_FIELD_CLASHES);
        }

        return ['suggestions' => $suggestions, 'clashes' => $clashes];
    }

    /**
     * The color itself plus, if enabled, how it looks with red/green color blindness.
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    public function variants(string $hex): array
    {
        if (\array_key_exists($hex, $this->variantCache)) {
            return $this->variantCache[$hex];
        }

        $lab = Oklab::fromHex($hex) ?? [0.8, 0.0, 0.0];
        $variants = [$lab];
        if ($this->configuration->isColorBlind()) {
            $variants[] = Oklab::simulate($lab, 'deutan');
            $variants[] = Oklab::simulate($lab, 'protan');
        }

        return $this->variantCache[$hex] = $variants;
    }

    /**
     * Distance as perceived by the "weakest" simulated viewer.
     *
     * @param list<array{0: float, 1: float, 2: float}> $a
     * @param list<array{0: float, 1: float, 2: float}> $b
     */
    public function distance(array $a, array $b): float
    {
        $min = INF;
        foreach ($a as $i => $lab) {
            $other = $b[$i];
            $d = 100 * sqrt(($lab[0] - $other[0]) ** 2 + ($lab[1] - $other[1]) ** 2 + ($lab[2] - $other[2]) ** 2);
            if ($d < $min) {
                $min = $d;
            }
        }

        return $min;
    }
}
