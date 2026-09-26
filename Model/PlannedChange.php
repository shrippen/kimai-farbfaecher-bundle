<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Model;

final class PlannedChange
{
    public const REASON_MISSING = 'missing';
    public const REASON_CLASH = 'clash';
    public const REASON_RECOLOR = 'recolor';
    /** parent got a new color, the child follows to keep the color family */
    public const REASON_FOLLOW = 'follow';

    public function __construct(
        public readonly ColorNode $node,
        public readonly string $path,
        /** color that is displayed today */
        public readonly string $oldColor,
        /** 'own', 'inherited' or 'generated' */
        public readonly string $oldSource,
        public readonly string $newColor,
        public readonly string $reason,
    ) {
    }
}
