<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Model;

use KimaiPlugin\FarbfaecherBundle\Color\Oklab;

/**
 * All color relevant entities plus the information how "close" two of them are in daily use.
 *
 * Only entities of the same type are compared: in lists, pickers and calendars customers are shown
 * next to customers, projects next to projects and so on.
 */
final class ColorGraph
{
    /** weight for any two visible entities of the same type */
    public const BASE_WEIGHT = 0.3;
    /** extra weight for siblings (same customer / same project / all customers / all global activities) */
    public const SIBLING_WEIGHT = 0.7;
    /** extra weight per log2(1 + weeks booked together) */
    public const COOCCURRENCE_WEIGHT = 1.5;

    /** @var array<string, ColorNode> */
    private array $nodes = [];
    /** @var array<string, int> number of weeks in which both entities were booked by the same user */
    private array $cooccurrence = [];
    /** @var array<string, array<string, true>> */
    private array $partners = [];
    /** @var array<string, list<string>>|null */
    private ?array $childIndex = null;

    public function __construct(public readonly int $windowDays)
    {
    }

    public function add(ColorNode $node): void
    {
        $this->nodes[$node->getKey()] = $node;
        $this->childIndex = null;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->nodes);
    }

    public function get(string $key): ColorNode
    {
        return $this->nodes[$key];
    }

    /**
     * @return array<string, ColorNode>
     */
    public function all(): array
    {
        return $this->nodes;
    }

    /**
     * @return array<string, ColorNode>
     */
    public function byType(string $type, bool $includeHidden = false): array
    {
        return array_filter($this->nodes, fn (ColorNode $n) => $n->type === $type && ($includeHidden || $n->visible));
    }

    /**
     * @return list<ColorNode>
     */
    public function children(string $parentKey): array
    {
        if ($this->childIndex === null) {
            $this->childIndex = [];
            foreach ($this->nodes as $key => $node) {
                if ($node->parentKey !== null) {
                    $this->childIndex[$node->parentKey][] = $key;
                }
            }
        }

        return array_map(fn (string $key) => $this->nodes[$key], $this->childIndex[$parentKey] ?? []);
    }

    /**
     * Entities of the same type that sit in the same group (same parent; all customers; all global activities).
     *
     * @return list<ColorNode>
     */
    public function siblings(ColorNode $node): array
    {
        if ($node->parentKey !== null) {
            $candidates = $this->children($node->parentKey);
        } else {
            $candidates = array_filter($this->nodes, fn (ColorNode $n) => $n->type === $node->type && $n->parentKey === null);
        }

        return array_values(array_filter($candidates, fn (ColorNode $n) => $n->getKey() !== $node->getKey()));
    }

    /**
     * @return list<string> keys of entities that were booked together with the given one
     */
    public function partners(string $key): array
    {
        return array_keys($this->partners[$key] ?? []);
    }

    public function addCooccurrence(string $a, string $b, int $weeks = 1): void
    {
        $key = self::pairKey($a, $b);
        $this->cooccurrence[$key] = ($this->cooccurrence[$key] ?? 0) + $weeks;
        $this->partners[$a][$b] = true;
        $this->partners[$b][$a] = true;
    }

    /**
     * The part of weight() that applies to every pair of the same type.
     */
    public function baseWeight(ColorNode $a, ColorNode $b): float
    {
        if ($a->type !== $b->type || $a->getKey() === $b->getKey()) {
            return 0.0;
        }

        return self::BASE_WEIGHT * ($a->visible && $b->visible ? 1.0 : 0.1);
    }

    public function getCooccurrence(string $a, string $b): int
    {
        return $this->cooccurrence[self::pairKey($a, $b)] ?? 0;
    }

    public function isSibling(ColorNode $a, ColorNode $b): bool
    {
        return $a->type === $b->type && $a->parentKey === $b->parentKey;
    }

    /**
     * How important it is that the two entities are distinguishable.
     */
    public function weight(ColorNode $a, ColorNode $b): float
    {
        if ($a->type !== $b->type || $a->getKey() === $b->getKey()) {
            return 0.0;
        }

        $weight = self::BASE_WEIGHT;
        if ($this->isSibling($a, $b)) {
            $weight += self::SIBLING_WEIGHT;
        }
        $weeks = $this->getCooccurrence($a->getKey(), $b->getKey());
        if ($weeks > 0) {
            $weight += self::COOCCURRENCE_WEIGHT * log(1 + $weeks, 2);
        }
        if (!$a->visible || !$b->visible) {
            $weight *= 0.1;
        }

        return $weight;
    }

    /**
     * Resolves the color that is actually displayed, following Kimai's fallback chain:
     * own color → parent color(s) → generated "color-safe" color.
     *
     * @param array<string, string> $overrides planned colors by key
     */
    public function effectiveColor(string $key, array $overrides = []): string
    {
        return $this->resolve($key, $overrides)[0];
    }

    /**
     * @param array<string, string> $overrides
     * @return 'own'|'inherited'|'generated'
     */
    public function colorSource(string $key, array $overrides = []): string
    {
        return $this->resolve($key, $overrides)[1];
    }

    /**
     * @param array<string, string> $overrides
     * @return array{0: float, 1: float, 2: float}
     */
    public function effectiveLab(string $key, array $overrides = []): array
    {
        return Oklab::fromHex($this->effectiveColor($key, $overrides)) ?? [0.8, 0.0, 0.0];
    }

    /**
     * Human readable path, e.g. "Customer › Project › Activity".
     */
    public function path(string $key): string
    {
        $parts = [];
        $current = $key;
        while ($current !== null && $this->has($current)) {
            $node = $this->get($current);
            array_unshift($parts, $node->name);
            $current = $node->parentKey;
        }

        return implode(' › ', $parts);
    }

    /**
     * @param array<string, string> $overrides
     * @return array{0: string, 1: 'own'|'inherited'|'generated'}
     */
    private function resolve(string $key, array $overrides): array
    {
        $node = $this->nodes[$key];
        $own = $overrides[$key] ?? $node->ownColor;
        if ($own !== null) {
            return [$own, 'own'];
        }

        $parent = $node->parentKey;
        while ($parent !== null && $this->has($parent)) {
            $color = $overrides[$parent] ?? $this->nodes[$parent]->ownColor;
            if ($color !== null) {
                return [$color, 'inherited'];
            }
            $parent = $this->nodes[$parent]->parentKey;
        }

        return [$node->generatedColor, 'generated'];
    }

    private static function pairKey(string $a, string $b): string
    {
        return strcmp($a, $b) < 0 ? $a . '|' . $b : $b . '|' . $a;
    }
}
