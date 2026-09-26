<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Form;

use KimaiPlugin\FarbfaecherBundle\Configuration\FarbfaecherConfiguration as Config;
use KimaiPlugin\FarbfaecherBundle\Service\ColorPlanner;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * GET form on the overview: what to recolor and how.
 */
final class PlanForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('scope', ChoiceType::class, [
                'label' => 'farbfaecher.plan.scope',
                'choices' => [
                    'farbfaecher.scope.clashes' => ColorPlanner::SCOPE_CLASHES,
                    'farbfaecher.scope.missing' => ColorPlanner::SCOPE_MISSING,
                    'farbfaecher.scope.all' => ColorPlanner::SCOPE_ALL,
                ],
                'help' => 'farbfaecher.plan.help',
                'search' => false,
            ])
            ->add('strategy', ChoiceType::class, [
                'label' => 'farbfaecher.plan.strategy',
                'choices' => [
                    'farbfaecher.strategy.hierarchical' => Config::STRATEGY_HIERARCHICAL,
                    'farbfaecher.strategy.distinct' => Config::STRATEGY_DISTINCT,
                ],
                'help' => 'farbfaecher.plan.strategy_help',
                'search' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'method' => 'GET',
        ]);
    }
}
