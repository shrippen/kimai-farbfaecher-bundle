<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration;
use KimaiPlugin\FarbfaecherBundle\Configuration\FarbfaecherConfiguration as Config;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints\Range;

final class SystemConfigurationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigurationEvent::class => ['onSystemConfiguration', 100],
        ];
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event): void
    {
        $event->addConfiguration(
            (new SystemConfiguration('farbfaecher'))
                ->setTranslationDomain('system-configuration')
                ->setConfiguration([
                    (new Configuration('farbfaecher.threshold'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(IntegerType::class)
                        ->setValue(Config::DEFAULTS['farbfaecher.threshold'])
                        ->setConstraints([new Range(min: 2, max: 50)])
                        ->setOptions(['help' => 'farbfaecher.threshold.help']),
                    (new Configuration('farbfaecher.window_days'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(IntegerType::class)
                        ->setValue(Config::DEFAULTS['farbfaecher.window_days'])
                        ->setConstraints([new Range(min: 7, max: 730)])
                        ->setOptions(['help' => 'farbfaecher.window_days.help']),
                    (new Configuration('farbfaecher.strategy'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(ChoiceType::class)
                        ->setValue(Config::DEFAULTS['farbfaecher.strategy'])
                        ->setOptions([
                            'choices' => [
                                'farbfaecher.strategy.hierarchical' => Config::STRATEGY_HIERARCHICAL,
                                'farbfaecher.strategy.distinct' => Config::STRATEGY_DISTINCT,
                            ],
                            'help' => 'farbfaecher.strategy.help',
                        ]),
                    (new Configuration('farbfaecher.palette'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(ChoiceType::class)
                        ->setValue(Config::DEFAULTS['farbfaecher.palette'])
                        ->setOptions([
                            'choices' => [
                                'farbfaecher.palette.theme' => Config::PALETTE_THEME,
                                'farbfaecher.palette.free' => Config::PALETTE_FREE,
                            ],
                            'help' => 'farbfaecher.palette.help',
                        ]),
                    (new Configuration('farbfaecher.palette_size'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(IntegerType::class)
                        ->setValue(Config::DEFAULTS['farbfaecher.palette_size'])
                        ->setConstraints([new Range(min: 0, max: Config::MAX_PALETTE_SIZE)])
                        ->setOptions(['help' => 'farbfaecher.palette_size.help']),
                    (new Configuration('farbfaecher.free_colors'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(CheckboxType::class)
                        ->setValue(Config::DEFAULTS['farbfaecher.free_colors'])
                        ->setOptions(['help' => 'farbfaecher.free_colors.help']),
                    (new Configuration('farbfaecher.auto_assign'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(CheckboxType::class)
                        ->setValue(Config::DEFAULTS['farbfaecher.auto_assign'])
                        ->setOptions(['help' => 'farbfaecher.auto_assign.help']),
                    (new Configuration('farbfaecher.include_hidden'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(CheckboxType::class)
                        ->setValue(Config::DEFAULTS['farbfaecher.include_hidden']),
                    (new Configuration('farbfaecher.color_blind'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(CheckboxType::class)
                        ->setValue(Config::DEFAULTS['farbfaecher.color_blind'])
                        ->setOptions(['help' => 'farbfaecher.color_blind.help']),
                ])
        );
    }
}
