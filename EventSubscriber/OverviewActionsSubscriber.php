<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\EventSubscriber;

use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;

/**
 * Page header of the overview: settings.
 */
final class OverviewActionsSubscriber extends AbstractActionsSubscriber
{
    public static function getActionName(): string
    {
        return 'farbfaecher';
    }

    public function onActions(PageActionsEvent $event): void
    {
        if (!$this->isGranted('system_configuration')) {
            return;
        }

        $event->addAction('settings', [
            'url' => $this->path('system_configuration_section', ['section' => 'farbfaecher']),
            'title' => 'farbfaecher.action.settings',
            'translation_domain' => 'messages',
        ]);
    }
}
