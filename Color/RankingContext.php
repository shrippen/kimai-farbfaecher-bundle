<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Color;

use KimaiPlugin\FarbfaecherBundle\Model\ColorGraph;
use KimaiPlugin\FarbfaecherBundle\Model\ColorNode;

/**
 * Keeps track of the colors placed so far (for one entity type) and computes the penalty of a candidate color:
 *
 *   penalty(c) = Σ_n weight(node, n) · f(ΔE(c, n))
 *
 *   f(d) = max(0, limit − d)²  +  CLASH_FACTOR · max(0, threshold − d)²      (limit = 2 · threshold)
 *          └── spreads colors ──┘   └── a real clash costs far more than crowding ──┘
 *          + SAME_PENALTY if d < SAME_DISTANCE (the same color twice only if nothing else is left)
 *
 * Without the second term many "medium close" neighbors add up to more than one identical color,
 * so in a crowded palette two entities could end up with exactly the same color.
 *
 * weight = base part (identical for all pairs) + specific part (siblings, co-usage).
 * The base part is kept as an incrementally updated "field" per candidate color, so only the few
 * specific neighbors need to be compared one by one. This keeps planning fast with hundreds of entities.
 */
final class RankingContext
{
    /** how much steeper the penalty gets below the clash threshold */
    private const CLASH_FACTOR = 10.0;
    /** below this ΔE two colors look the same: only chosen if there is no other option at all */
    private const SAME_DISTANCE = 3.0;
    private const SAME_PENALTY = 1.0e6;

    /** @var array<string, list<array{0: float, 1: float, 2: float}>> key => color variants of placed nodes */
    private array $placed = [];
    /** @var array<string, ColorNode> */
    private array $placedNodes = [];
    /** @var array<string, float> hex => Σ f(d) over placed visible nodes */
    private array $fieldVisible = [];
    /** @var array<string, float> hex => Σ f(d) over placed hidden nodes */
    private array $fieldHidden = [];
    /** @var array<string, list<array{0: float, 1: float, 2: float}>> hex => variants, for all colors in the fields */
    private array $fieldColors = [];

    /**
     * @param \Closure(string): list<array{0: float, 1: float, 2: float}> $variants
     */
    public function __construct(
        private readonly ColorGraph $graph,
        private readonly float $limit,
        private readonly \Closure $variants,
    ) {
    }

    public function place(ColorNode $node, string $hex): void
    {
        $key = $node->getKey();
        if (isset($this->placed[$key])) {
            $this->remove($node);
        }
        $variants = ($this->variants)($hex);
        $this->placed[$key] = $variants;
        $this->placedNodes[$key] = $node;
        $this->updateFields($variants, $node->visible, 1.0);
    }

    public function remove(ColorNode $node): void
    {
        $key = $node->getKey();
        if (!isset($this->placed[$key])) {
            return;
        }
        $this->updateFields($this->placed[$key], $node->visible, -1.0);
        unset($this->placed[$key], $this->placedNodes[$key]);
    }

    public function isPlaced(string $key): bool
    {
        return isset($this->placed[$key]);
    }

    /**
     * The node itself must not be placed while it is ranked.
     *
     * @param list<Candidate> $candidates
     * @return list<array{candidate: Candidate, penalty: float}> best first
     */
    public function rank(ColorNode $node, array $candidates, ?string $keepColor = null): array
    {
        $specific = $this->specificNeighbors($node);
        $ranked = [];
        foreach ($candidates as $i => $candidate) {
            $hex = $candidate->hex;
            if (!isset($this->fieldColors[$hex])) {
                $this->initField($hex);
            }
            $base = $node->visible
                ? $this->fieldVisible[$hex] + 0.1 * $this->fieldHidden[$hex]
                : 0.1 * ($this->fieldVisible[$hex] + $this->fieldHidden[$hex]);
            $penalty = ColorGraph::BASE_WEIGHT * $base;

            $own = $this->fieldColors[$hex];
            foreach ($specific as [$weight, $variants]) {
                $penalty += $weight * $this->falloff($own, $variants);
            }
            if ($keepColor !== null && $hex === $keepColor) {
                $penalty *= 0.8;
            }
            $ranked[] = ['candidate' => $candidate, 'penalty' => $penalty, 'order' => $i];
        }

        usort($ranked, fn (array $a, array $b) => [$a['penalty'], $a['order']] <=> [$b['penalty'], $b['order']]);

        return array_map(fn (array $r) => ['candidate' => $r['candidate'], 'penalty' => $r['penalty']], $ranked);
    }

    /**
     * Placed siblings and co-used entities, with the part of their weight that exceeds the base weight.
     *
     * @return list<array{0: float, 1: list<array{0: float, 1: float, 2: float}>}>
     */
    private function specificNeighbors(ColorNode $node): array
    {
        $keys = array_map(fn (ColorNode $n) => $n->getKey(), $this->graph->siblings($node));
        if ($node->id !== null) {
            $keys = array_merge($keys, $this->graph->partners($node->getKey()));
        }

        $result = [];
        foreach (array_unique($keys) as $key) {
            if (!isset($this->placed[$key])) {
                continue;
            }
            $other = $this->placedNodes[$key];
            $extra = $this->graph->weight($node, $other) - $this->graph->baseWeight($node, $other);
            if ($extra > 0) {
                $result[] = [$extra, $this->placed[$key]];
            }
        }

        return $result;
    }

    private function initField(string $hex): void
    {
        $own = ($this->variants)($hex);
        $visible = 0.0;
        $hidden = 0.0;
        foreach ($this->placed as $key => $variants) {
            $f = $this->falloff($own, $variants);
            if ($this->placedNodes[$key]->visible) {
                $visible += $f;
            } else {
                $hidden += $f;
            }
        }
        $this->fieldColors[$hex] = $own;
        $this->fieldVisible[$hex] = $visible;
        $this->fieldHidden[$hex] = $hidden;
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $variants
     */
    private function updateFields(array $variants, bool $visible, float $sign): void
    {
        foreach ($this->fieldColors as $hex => $own) {
            $f = $this->falloff($own, $variants);
            if ($f === 0.0) {
                continue;
            }
            if ($visible) {
                $this->fieldVisible[$hex] += $sign * $f;
            } else {
                $this->fieldHidden[$hex] += $sign * $f;
            }
        }
    }

    /**
     * f(ΔE), see class comment. ΔE is the smallest distance over all simulated viewers.
     *
     * @param list<array{0: float, 1: float, 2: float}> $a
     * @param list<array{0: float, 1: float, 2: float}> $b
     */
    private function falloff(array $a, array $b): float
    {
        $limit = $this->limit / 100;
        $limit2 = $limit * $limit;
        $min = INF;
        foreach ($a as $i => $lab) {
            $o = $b[$i];
            $dl = $lab[0] - $o[0];
            $da = $lab[1] - $o[1];
            $db = $lab[2] - $o[2];
            $d2 = $dl * $dl + $da * $da + $db * $db;
            if ($d2 < $min) {
                $min = $d2;
            }
        }
        if ($min >= $limit2) {
            return 0.0;
        }
        $d = 100 * sqrt($min);
        $threshold = $this->limit / 2;
        $clash = $d < $threshold ? self::CLASH_FACTOR * ($threshold - $d) ** 2 : 0.0;
        $same = $d < self::SAME_DISTANCE ? self::SAME_PENALTY : 0.0;

        return ($this->limit - $d) ** 2 + $clash + $same;
    }
}
