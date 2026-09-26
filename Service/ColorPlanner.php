<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Service;

use KimaiPlugin\FarbfaecherBundle\Color\Candidate;
use KimaiPlugin\FarbfaecherBundle\Color\Oklab;
use KimaiPlugin\FarbfaecherBundle\Configuration\FarbfaecherConfiguration;
use KimaiPlugin\FarbfaecherBundle\Model\Clash;
use KimaiPlugin\FarbfaecherBundle\Model\ColorGraph;
use KimaiPlugin\FarbfaecherBundle\Model\ColorNode;
use KimaiPlugin\FarbfaecherBundle\Model\PlannedChange;

/**
 * Computes new colors for many entities at once (greedy assignment plus one refinement pass).
 */
final class ColorPlanner
{
    /** only entities without an own color */
    public const SCOPE_MISSING = 'missing';
    /** entities without own color plus one side of every clash */
    public const SCOPE_CLASHES = 'clashes';
    /** every entity that is not locked */
    public const SCOPE_ALL = 'all';

    public const SCOPES = [self::SCOPE_MISSING, self::SCOPE_CLASHES, self::SCOPE_ALL];

    public function __construct(private readonly ColorEngine $engine)
    {
    }

    /**
     * @return array{changes: list<PlannedChange>, before: list<Clash>, after: list<Clash>}
     */
    public function plan(ColorGraph $graph, string $scope, ?string $strategy = null): array
    {
        $strategy ??= $this->engine->getConfiguration()->getStrategy();
        $before = $this->engine->analyze($graph);
        $free = $this->determineFree($graph, $scope, $before);
        $includeHidden = $this->engine->getConfiguration()->isIncludeHidden();
        if ($strategy === FarbfaecherConfiguration::STRATEGY_HIERARCHICAL) {
            $this->addFollowers($graph, $free, $includeHidden);
        }
        $overrides = [];
        $reasons = [];

        // parents first: in the hierarchical strategy children are placed relative to their parent
        foreach (ColorNode::TYPES as $type) {
            $nodes = $graph->byType($type, $includeHidden);
            $freeNodes = array_values(array_filter($nodes, fn (ColorNode $n) => isset($free[$n->getKey()])));
            if (\count($freeNodes) === 0) {
                continue;
            }
            // the most used entities pick first and get the best spots
            usort($freeNodes, fn (ColorNode $a, ColorNode $b) => [$b->hours, $a->name] <=> [$a->hours, $b->name]);

            $context = $this->engine->createContext($graph);
            foreach ($nodes as $key => $node) {
                if (!isset($free[$key])) {
                    $context->place($node, $graph->effectiveColor($key, $overrides));
                }
            }

            // pass 1: greedy
            foreach ($freeNodes as $node) {
                $keep = $this->keepColor($node, $scope, $free);
                $ranked = $context->rank($node, $this->candidates($graph, $node, $overrides, $strategy, $keep), $keep);
                $best = $ranked[0]['candidate']->hex;
                $overrides[$node->getKey()] = $best;
                $context->place($node, $best);
            }

            // pass 2: revisit every decision now that all colors are known
            foreach ($freeNodes as $node) {
                $key = $node->getKey();
                $current = $overrides[$key];
                $keep = $this->keepColor($node, $scope, $free);
                $context->remove($node);

                $currentPenalty = $context->rank($node, [new Candidate($current, Oklab::fromHex($current) ?? [0.8, 0.0, 0.0])], $keep)[0]['penalty'];
                if ($currentPenalty > 0.0) {
                    $ranked = $context->rank($node, $this->candidates($graph, $node, $overrides, $strategy, $keep), $keep);
                    $best = $ranked[0];
                    if ($best['candidate']->hex !== $current && $best['penalty'] < $currentPenalty * 0.95) {
                        $current = $best['candidate']->hex;
                        $overrides[$key] = $current;
                    }
                }
                $context->place($node, $current);
            }

            foreach ($freeNodes as $node) {
                $reasons[$node->getKey()] = $free[$node->getKey()];
            }
        }

        $changes = [];
        foreach ($overrides as $key => $color) {
            $node = $graph->get($key);
            if ($color === $node->ownColor) {
                unset($overrides[$key]);
                continue;
            }
            $changes[] = new PlannedChange(
                $node,
                $graph->path($key),
                $graph->effectiveColor($key),
                $graph->colorSource($key),
                $color,
                $reasons[$key],
            );
        }

        return [
            'changes' => $changes,
            'before' => $before,
            'after' => $this->engine->analyze($graph, $overrides),
        ];
    }

    /**
     * @param list<Clash> $clashes
     * @return array<string, string> key => reason
     */
    private function determineFree(ColorGraph $graph, string $scope, array $clashes): array
    {
        $includeHidden = $this->engine->getConfiguration()->isIncludeHidden();
        $free = [];

        foreach ($graph->all() as $key => $node) {
            if ($node->locked || (!$node->visible && !$includeHidden)) {
                continue;
            }
            if ($scope === self::SCOPE_ALL) {
                $free[$key] = $node->ownColor === null ? PlannedChange::REASON_MISSING : PlannedChange::REASON_RECOLOR;
            } elseif ($node->ownColor === null) {
                $free[$key] = PlannedChange::REASON_MISSING;
            }
        }

        if ($scope !== self::SCOPE_CLASHES) {
            return $free;
        }

        foreach ($clashes as $clash) {
            if (isset($free[$clash->a->getKey()]) || isset($free[$clash->b->getKey()])) {
                continue;
            }
            $move = $this->pickSideToMove($clash->a, $clash->b);
            if ($move !== null) {
                $free[$move->getKey()] = PlannedChange::REASON_CLASH;
            }
        }

        return $free;
    }

    /**
     * The current color gets a small bonus, unless everything is recolored or the entity follows its parent.
     *
     * @param array<string, string> $free
     */
    private function keepColor(ColorNode $node, string $scope, array $free): ?string
    {
        if ($scope === self::SCOPE_ALL || $free[$node->getKey()] === PlannedChange::REASON_FOLLOW) {
            return null;
        }

        return $node->ownColor;
    }

    /**
     * If a customer (or project) with an own color changes, its children follow into the new color family.
     *
     * @param array<string, string> $free
     */
    private function addFollowers(ColorGraph $graph, array &$free, bool $includeHidden): void
    {
        foreach ([ColorNode::CUSTOMER, ColorNode::PROJECT] as $type) {
            foreach ($graph->byType($type, $includeHidden) as $key => $node) {
                if (!isset($free[$key]) || $free[$key] === PlannedChange::REASON_MISSING) {
                    continue;
                }
                foreach ($graph->children($key) as $child) {
                    if (!$child->locked && ($child->visible || $includeHidden) && !isset($free[$child->getKey()])) {
                        $free[$child->getKey()] = PlannedChange::REASON_FOLLOW;
                    }
                }
            }
        }
    }

    /**
     * Moves the less familiar entity: locked ones never, then the one with fewer hours, then the newer one.
     */
    private function pickSideToMove(ColorNode $a, ColorNode $b): ?ColorNode
    {
        if ($a->locked && $b->locked) {
            return null;
        }
        if ($a->locked) {
            return $b;
        }
        if ($b->locked) {
            return $a;
        }
        if (abs($a->hours - $b->hours) > 0.01) {
            return $a->hours < $b->hours ? $a : $b;
        }

        return ($a->id ?? PHP_INT_MAX) > ($b->id ?? PHP_INT_MAX) ? $a : $b;
    }

    /**
     * @param array<string, string> $overrides
     * @return list<Candidate>
     */
    private function candidates(ColorGraph $graph, ColorNode $node, array $overrides, ?string $strategy, ?string $include): array
    {
        $candidates = $this->engine->candidatesFor($graph, $node, $overrides, $strategy);
        $include = Oklab::normalizeHex($include);
        if ($include !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate->hex === $include) {
                    return $candidates;
                }
            }
            $lab = Oklab::fromHex($include);
            if ($lab !== null) {
                $candidates[] = new Candidate($include, $lab);
            }
        }

        return $candidates;
    }
}
