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
 * Page header of the plan preview: back to the overview.
 */
final class PlanActionsSubscriber extends AbstractActionsSubscriber
{
    public static function getActionName(): string
    {
        return 'farbfaecher_plan';
    }

    public function onActions(PageActionsEvent $event): void
    {
        $event->addAction('back', [
            'url' => $this->path('farbfaecher'),
            'title' => 'farbfaecher.action.overview',
            'translation_domain' => 'messages',
        ]);
    }
}
