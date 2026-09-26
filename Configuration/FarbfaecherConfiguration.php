<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Configuration;

use App\Configuration\SystemConfiguration;

final class FarbfaecherConfiguration
{
    public const STRATEGY_HIERARCHICAL = 'hierarchical';
    public const STRATEGY_DISTINCT = 'distinct';
    public const STRATEGIES = [self::STRATEGY_HIERARCHICAL, self::STRATEGY_DISTINCT];

    /** suggestions follow the hues of Kimai's color list (theme.color_choices, e.g. set by a theme) */
    public const PALETTE_THEME = 'theme';
    /** suggestions use the whole sRGB color space */
    public const PALETTE_FREE = 'free';
    public const MAX_PALETTE_SIZE = 300;

    public const DEFAULTS = [
        'farbfaecher.threshold' => 12,
        'farbfaecher.window_days' => 90,
        'farbfaecher.strategy' => self::STRATEGY_HIERARCHICAL,
        'farbfaecher.palette' => self::PALETTE_THEME,
        'farbfaecher.palette_size' => 0,
        'farbfaecher.free_colors' => true,
        'farbfaecher.auto_assign' => true,
        'farbfaecher.include_hidden' => false,
        'farbfaecher.color_blind' => false,
    ];

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    /**
     * Minimum perceptual distance (ΔE_OK × 100) below which two colors count as a clash.
     */
    public function getThreshold(): float
    {
        return max(2.0, min(50.0, (float) $this->get('farbfaecher.threshold')));
    }

    /**
     * How many days of timesheets are used to find entities that are used together.
     */
    public function getWindowDays(): int
    {
        return max(7, min(730, (int) $this->get('farbfaecher.window_days')));
    }

    public function getStrategy(): string
    {
        $strategy = $this->get('farbfaecher.strategy');

        return $strategy === self::STRATEGY_DISTINCT ? self::STRATEGY_DISTINCT : self::STRATEGY_HIERARCHICAL;
    }

    public function getPalette(): string
    {
        return $this->get('farbfaecher.palette') === self::PALETTE_FREE ? self::PALETTE_FREE : self::PALETTE_THEME;
    }

    /**
     * Number of colors suggestions are taken from, 0 = no limit.
     */
    public function getPaletteSize(): int
    {
        return max(0, min(self::MAX_PALETTE_SIZE, (int) $this->get('farbfaecher.palette_size')));
    }

    /**
     * Kimai's color list, e.g. the Gruvbox palette of the Knust theme.
     *
     * @return list<string>
     */
    public function getThemeColors(): array
    {
        return array_values($this->configuration->getThemeColors());
    }

    public function isFreeColors(): bool
    {
        return (bool) $this->get('farbfaecher.free_colors');
    }

    public function isAutoAssign(): bool
    {
        return (bool) $this->get('farbfaecher.auto_assign');
    }

    public function isIncludeHidden(): bool
    {
        return (bool) $this->get('farbfaecher.include_hidden');
    }

    public function isColorBlind(): bool
    {
        return (bool) $this->get('farbfaecher.color_blind');
    }

    private function get(string $key): string|int|bool|float|null
    {
        return $this->configuration->find($key) ?? self::DEFAULTS[$key];
    }
}
