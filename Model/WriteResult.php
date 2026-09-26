<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Model;

/**
 * Outcome of one batch of color changes.
 */
final class WriteResult
{
    public function __construct(
        public readonly int $count,
        /** null if nothing was changed */
        public readonly ?string $backupId,
        /** entities not restored, because their color was changed again in the meantime */
        public readonly int $skipped = 0,
    ) {
    }
}
