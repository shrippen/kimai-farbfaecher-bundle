<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Model;

final class Clash
{
    public function __construct(
        public readonly ColorNode $a,
        public readonly ColorNode $b,
        public readonly string $colorA,
        public readonly string $colorB,
        public readonly float $distance,
        public readonly float $weight,
        public readonly int $cooccurrenceWeeks,
        public readonly bool $siblings,
        /** true if the colors only collide when simulating red/green color blindness */
        public readonly bool $colorBlindOnly,
        public readonly float $threshold,
    ) {
    }

    public function getSeverity(): float
    {
        return $this->weight * (1 - $this->distance / $this->threshold);
    }

    /**
     * @return 'critical'|'warning'|'info'
     */
    public function getLevel(): string
    {
        if ($this->distance < $this->threshold * 0.4 && $this->weight >= 1.0) {
            return 'critical';
        }
        if ($this->getSeverity() >= 0.3) {
            return 'warning';
        }

        return 'info';
    }

    public function involves(string $key): bool
    {
        return $this->a->getKey() === $key || $this->b->getKey() === $key;
    }
}
