<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\EventSubscriber;

use App\Event\ThemeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ships the plugin CSS and the script of the color field on every page: edit forms open as modals anywhere.
 * Both are inlined, so the plugin needs no assets:install.
 */
final class ThemeSubscriber implements EventSubscriberInterface
{
    private const ASSETS = __DIR__ . '/../Resources/assets/';

    public static function getSubscribedEvents(): array
    {
        return [
            ThemeEvent::STYLESHEET => ['onStylesheet', 100],
            ThemeEvent::JAVASCRIPT => ['onJavascript', 100],
        ];
    }

    public function onStylesheet(ThemeEvent $event): void
    {
        $this->inline($event, 'farbfaecher.css', 'style');
    }

    public function onJavascript(ThemeEvent $event): void
    {
        $this->inline($event, 'color-field.js', 'script');
    }

    private function inline(ThemeEvent $event, string $file, string $tag): void
    {
        // login page: no forms of this plugin
        if ($event->getUser() === null) {
            return;
        }

        $content = file_get_contents(self::ASSETS . $file);
        if ($content === false) {
            return;
        }

        $event->addContent('<' . $tag . '>' . $content . '</' . $tag . '>');
    }
}
