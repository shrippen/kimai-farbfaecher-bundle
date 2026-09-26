<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\EventSubscriber;

use App\Event\ActivityCreatePreEvent;
use App\Event\CustomerCreatePreEvent;
use App\Event\ProjectCreatePreEvent;
use KimaiPlugin\FarbfaecherBundle\Configuration\FarbfaecherConfiguration;
use KimaiPlugin\FarbfaecherBundle\Model\ColorNode;
use KimaiPlugin\FarbfaecherBundle\Service\ColorEngine;
use KimaiPlugin\FarbfaecherBundle\Service\GraphBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives new customers, projects and activities without color the best fitting color.
 */
final class AutoAssignSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly FarbfaecherConfiguration $configuration,
        private readonly GraphBuilder $graphBuilder,
        private readonly ColorEngine $engine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerCreatePreEvent::class => ['onCustomer', 50],
            ProjectCreatePreEvent::class => ['onProject', 50],
            ActivityCreatePreEvent::class => ['onActivity', 50],
        ];
    }

    public function onCustomer(CustomerCreatePreEvent $event): void
    {
        $customer = $event->getCustomer();
        if ($customer->hasColor() || !$this->configuration->isAutoAssign()) {
            return;
        }
        $customer->setColor($this->pick(ColorNode::CUSTOMER, (string) $customer->getName(), null));
    }

    public function onProject(ProjectCreatePreEvent $event): void
    {
        $project = $event->getProject();
        if ($project->hasColor() || !$this->configuration->isAutoAssign()) {
            return;
        }
        $customer = $project->getCustomer()?->getId();
        $parent = $customer === null ? null : ColorNode::makeKey(ColorNode::CUSTOMER, $customer);
        $project->setColor($this->pick(ColorNode::PROJECT, (string) $project->getName(), $parent));
    }

    public function onActivity(ActivityCreatePreEvent $event): void
    {
        $activity = $event->getActivity();
        if ($activity->hasColor() || !$this->configuration->isAutoAssign()) {
            return;
        }
        $project = $activity->getProject()?->getId();
        $parent = $project === null ? null : ColorNode::makeKey(ColorNode::PROJECT, $project);
        $activity->setColor($this->pick(ColorNode::ACTIVITY, (string) $activity->getName(), $parent));
    }

    private function pick(string $type, string $name, ?string $parentKey): ?string
    {
        try {
            $graph = $this->graphBuilder->build($this->configuration->getWindowDays());
            $node = new ColorNode($type, null, $name, null, GraphBuilder::NO_COLOR, $parentKey, true, false);

            return $this->engine->suggest($graph, $node, null, 1)['suggestions'][0] ?? null;
        } catch (\Throwable $ex) {
            // never block creating an entity because of a color
            $this->logger->error('Farbfaecher: auto assign failed: ' . $ex->getMessage());

            return null;
        }
    }
}
