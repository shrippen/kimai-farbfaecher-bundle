<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Service;

use App\Constants;
use App\Entity\Activity;
use App\Entity\ActivityMeta;
use App\Entity\Customer;
use App\Entity\CustomerMeta;
use App\Entity\Project;
use App\Entity\ProjectMeta;
use App\Entity\Timesheet;
use App\Utils\Color;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\FarbfaecherBundle\Color\Oklab;
use KimaiPlugin\FarbfaecherBundle\Model\ColorGraph;
use KimaiPlugin\FarbfaecherBundle\Model\ColorNode;

/**
 * Loads customers, projects, activities and their co-usage from the database.
 * Uses plain array hydration, so it stays fast with thousands of entries.
 */
final class GraphBuilder
{
    public const LOCK_META_FIELD = 'farbfaecher_locked';
    /** placeholder color of entities that are not stored yet (Kimai's default gray) */
    public const NO_COLOR = '#d2d6de';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function build(int $windowDays): ColorGraph
    {
        $graph = new ColorGraph($windowDays);
        $color = new Color();
        $hours = $this->loadHours($windowDays);

        $customers = $this->entityManager->createQuery(
            'SELECT c.id, c.name, c.color, c.visible FROM ' . Customer::class . ' c'
        )->getArrayResult();
        $lockedCustomers = $this->loadLocks(CustomerMeta::class, 'customer');
        $customerVisible = [];
        foreach ($customers as $row) {
            $customerVisible[$row['id']] = (bool) $row['visible'];
            $graph->add(new ColorNode(
                ColorNode::CUSTOMER,
                $row['id'],
                (string) $row['name'],
                $this->ownColor($row['color']),
                $this->generatedColor($color, (string) $row['name']),
                null,
                (bool) $row['visible'],
                isset($lockedCustomers[$row['id']]),
                $hours['customer'][$row['id']] ?? 0.0,
            ));
        }

        $projects = $this->entityManager->createQuery(
            'SELECT p.id, p.name, p.color, p.visible, IDENTITY(p.customer) AS customer FROM ' . Project::class . ' p'
        )->getArrayResult();
        $lockedProjects = $this->loadLocks(ProjectMeta::class, 'project');
        $projectVisible = [];
        foreach ($projects as $row) {
            $visible = (bool) $row['visible'] && ($customerVisible[$row['customer']] ?? true);
            $projectVisible[$row['id']] = $visible;
            $graph->add(new ColorNode(
                ColorNode::PROJECT,
                $row['id'],
                (string) $row['name'],
                $this->ownColor($row['color']),
                $this->generatedColor($color, (string) $row['name']),
                ColorNode::makeKey(ColorNode::CUSTOMER, $row['customer']),
                $visible,
                isset($lockedProjects[$row['id']]),
                $hours['project'][$row['id']] ?? 0.0,
            ));
        }

        $activities = $this->entityManager->createQuery(
            'SELECT a.id, a.name, a.color, a.visible, IDENTITY(a.project) AS project FROM ' . Activity::class . ' a'
        )->getArrayResult();
        $lockedActivities = $this->loadLocks(ActivityMeta::class, 'activity');
        foreach ($activities as $row) {
            $project = $row['project'];
            $visible = (bool) $row['visible'] && ($project === null || ($projectVisible[$project] ?? true));
            $graph->add(new ColorNode(
                ColorNode::ACTIVITY,
                $row['id'],
                (string) $row['name'],
                $this->ownColor($row['color']),
                $this->generatedColor($color, (string) $row['name']),
                $project === null ? null : ColorNode::makeKey(ColorNode::PROJECT, $project),
                $visible,
                isset($lockedActivities[$row['id']]),
                $hours['activity'][$row['id']] ?? 0.0,
            ));
        }

        $this->loadCooccurrence($graph, $windowDays);

        return $graph;
    }

    /**
     * Two entities co-occur if the same user booked both within the same ISO week.
     */
    private function loadCooccurrence(ColorGraph $graph, int $windowDays): void
    {
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(t.user) AS user, IDENTITY(t.project) AS project, IDENTITY(t.activity) AS activity, t.date AS day
             FROM ' . Timesheet::class . ' t
             WHERE t.begin >= :since
             GROUP BY t.user, t.project, t.activity, t.date'
        )
            ->setParameter('since', new \DateTime('-' . $windowDays . ' days'))
            ->getArrayResult();

        /** @var array<string, array<string, array<string, true>>> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            if (!$row['day'] instanceof \DateTimeInterface) {
                continue;
            }
            $bucket = $row['user'] . '@' . $row['day']->format('o-W');
            $projectKey = ColorNode::makeKey(ColorNode::PROJECT, $row['project']);
            $buckets[$bucket][ColorNode::PROJECT][$projectKey] = true;
            $buckets[$bucket][ColorNode::ACTIVITY][ColorNode::makeKey(ColorNode::ACTIVITY, $row['activity'])] = true;
            if ($graph->has($projectKey) && $graph->get($projectKey)->parentKey !== null) {
                $buckets[$bucket][ColorNode::CUSTOMER][$graph->get($projectKey)->parentKey] = true;
            }
        }

        foreach ($buckets as $types) {
            foreach ($types as $keys) {
                $keys = array_keys($keys);
                $count = \count($keys);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $graph->addCooccurrence($keys[$i], $keys[$j]);
                    }
                }
            }
        }
    }

    /**
     * @return array{customer: array<int, float>, project: array<int, float>, activity: array<int, float>}
     */
    private function loadHours(int $windowDays): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(t.project) AS project, IDENTITY(p.customer) AS customer, IDENTITY(t.activity) AS activity, SUM(t.duration) AS duration
             FROM ' . Timesheet::class . ' t
             JOIN t.project p
             WHERE t.begin >= :since
             GROUP BY t.project, p.customer, t.activity'
        )
            ->setParameter('since', new \DateTime('-' . $windowDays . ' days'))
            ->getArrayResult();

        $hours = ['customer' => [], 'project' => [], 'activity' => []];
        foreach ($rows as $row) {
            $h = ((int) $row['duration']) / 3600;
            foreach (['customer', 'project', 'activity'] as $type) {
                $hours[$type][(int) $row[$type]] = ($hours[$type][(int) $row[$type]] ?? 0.0) + $h;
            }
        }

        return $hours;
    }

    /**
     * @param class-string $metaClass
     * @return array<int, true>
     */
    private function loadLocks(string $metaClass, string $field): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(m.' . $field . ') AS id FROM ' . $metaClass . ' m WHERE m.name = :name AND m.value = :value'
        )
            ->setParameter('name', self::LOCK_META_FIELD)
            ->setParameter('value', '1')
            ->getArrayResult();

        $locks = [];
        foreach ($rows as $row) {
            $locks[(int) $row['id']] = true;
        }

        return $locks;
    }

    /**
     * The same color Kimai returns as "color-safe" when nothing is set.
     */
    private function generatedColor(Color $color, string $name): string
    {
        return Oklab::normalizeHex($color->getRandom($name)) ?? self::NO_COLOR;
    }

    private function ownColor(?string $color): ?string
    {
        $color = Oklab::normalizeHex($color);

        // Kimai treats its default gray as "no color"
        return $color === strtolower(Constants::DEFAULT_COLOR) ? null : $color;
    }
}
