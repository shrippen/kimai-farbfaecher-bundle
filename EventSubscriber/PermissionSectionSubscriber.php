<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\EventSubscriber;

use App\Event\PermissionSectionsEvent;
use App\Model\PermissionSection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PermissionSectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PermissionSectionsEvent::class => ['onEvent', 100],
        ];
    }

    public function onEvent(PermissionSectionsEvent $event): void
    {
        $event->addSection(new PermissionSection('farbfaecher.title', 'farbfaecher'));
    }
}
