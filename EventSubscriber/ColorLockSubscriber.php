<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\EventSubscriber;

use App\Entity\ActivityMeta;
use App\Entity\CustomerMeta;
use App\Entity\MetaTableTypeInterface;
use App\Entity\ProjectMeta;
use App\Event\ActivityMetaDefinitionEvent;
use App\Event\CustomerMetaDefinitionEvent;
use App\Event\ProjectMetaDefinitionEvent;
use KimaiPlugin\FarbfaecherBundle\Service\GraphBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

/**
 * Adds the "lock color" checkbox to customers, projects and activities.
 * Locked entities are never recolored by the plugin.
 */
final class ColorLockSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CustomerMetaDefinitionEvent::class => ['onCustomer', 100],
            ProjectMetaDefinitionEvent::class => ['onProject', 100],
            ActivityMetaDefinitionEvent::class => ['onActivity', 100],
        ];
    }

    public function onCustomer(CustomerMetaDefinitionEvent $event): void
    {
        $event->getEntity()->setMetaField($this->configure(new CustomerMeta()));
    }

    public function onProject(ProjectMetaDefinitionEvent $event): void
    {
        $event->getEntity()->setMetaField($this->configure(new ProjectMeta()));
    }

    public function onActivity(ActivityMetaDefinitionEvent $event): void
    {
        $event->getEntity()->setMetaField($this->configure(new ActivityMeta()));
    }

    private function configure(MetaTableTypeInterface $meta): MetaTableTypeInterface
    {
        return $meta
            ->setName(GraphBuilder::LOCK_META_FIELD)
            ->setLabel('farbfaecher.lock.label')
            ->setType(CheckboxType::class)
            ->setOptions(['help' => 'farbfaecher.lock.help'])
            ->setIsVisible(false);
    }
}
