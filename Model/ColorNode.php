<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Model;

/**
 * Snapshot of a customer, project or activity with everything relevant for color decisions.
 */
final class ColorNode
{
    public const CUSTOMER = 'customer';
    public const PROJECT = 'project';
    public const ACTIVITY = 'activity';

    public const TYPES = [self::CUSTOMER, self::PROJECT, self::ACTIVITY];

    public function __construct(
        public readonly string $type,
        public readonly ?int $id,
        public readonly string $name,
        /** color stored on the entity, null if none */
        public readonly ?string $ownColor,
        /** what Kimai generates as "color-safe" when neither the entity nor a parent has a color */
        public readonly string $generatedColor,
        /** customer key for projects, project key for activities, null for customers and global activities */
        public readonly ?string $parentKey,
        public readonly bool $visible,
        public readonly bool $locked,
        public readonly float $hours = 0.0,
    ) {
    }

    public static function makeKey(string $type, int|string|null $id): string
    {
        return $type . ':' . ($id ?? 'new');
    }

    public function getKey(): string
    {
        return self::makeKey($this->type, $this->id);
    }

    public function withParent(?string $parentKey): self
    {
        return new self($this->type, $this->id, $this->name, $this->ownColor, $this->generatedColor, $parentKey, $this->visible, $this->locked, $this->hours);
    }

    public function isGlobalActivity(): bool
    {
        return $this->type === self::ACTIVITY && $this->parentKey === null;
    }
}
