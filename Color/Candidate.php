<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Color;

final class Candidate
{
    /**
     * @param array{0: float, 1: float, 2: float} $lab
     */
    public function __construct(public readonly string $hex, public readonly array $lab)
    {
    }
}
