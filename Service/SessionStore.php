<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Short-lived state in the user's session:
 *
 *   plans : the preview the user saw, so "apply" writes exactly those colors (not a recomputed plan)
 *   undo  : the undo window of kimai-plugin-ui (GUIDELINES 3.5) – same user, same session, 15 minutes
 */
final class SessionStore
{
    private const PLANS = 'farbfaecher.plans';
    private const UNDO = 'farbfaecher.undo';
    private const MAX_PLANS = 5;
    private const UNDO_SECONDS = 900;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * @param array<string, string> $colors node key => new color
     * @return string plan id for the apply URL
     */
    public function savePlan(array $colors, string $label): string
    {
        $session = $this->requestStack->getSession();
        $plans = $session->get(self::PLANS, []);
        $id = bin2hex(random_bytes(6));
        $plans[$id] = ['colors' => $colors, 'label' => $label];

        // keep only the latest previews, older tabs simply expire
        $session->set(self::PLANS, \array_slice($plans, -self::MAX_PLANS, null, true));

        return $id;
    }

    /**
     * @return array{colors: array<string, string>, label: string}|null
     */
    public function getPlan(string $id): ?array
    {
        return $this->requestStack->getSession()->get(self::PLANS, [])[$id] ?? null;
    }

    public function forgetPlan(string $id): void
    {
        $session = $this->requestStack->getSession();
        $plans = $session->get(self::PLANS, []);
        unset($plans[$id]);
        $session->set(self::PLANS, $plans);
    }

    public function allowUndo(string $backupId, int $userId): void
    {
        $session = $this->requestStack->getSession();
        $entries = array_filter($session->get(self::UNDO, []), fn (array $e) => $e['at'] > time() - self::UNDO_SECONDS);
        $entries[$backupId] = ['user' => $userId, 'at' => time()];
        $session->set(self::UNDO, $entries);
    }

    public function canUndo(string $backupId, int $userId): bool
    {
        $entry = $this->requestStack->getSession()->get(self::UNDO, [])[$backupId] ?? null;
        if ($entry === null) {
            return false;
        }

        return $entry['user'] === $userId && $entry['at'] > time() - self::UNDO_SECONDS;
    }

    public function forgetUndo(string $backupId): void
    {
        $session = $this->requestStack->getSession();
        $entries = $session->get(self::UNDO, []);
        unset($entries[$backupId]);
        $session->set(self::UNDO, $entries);
    }
}
