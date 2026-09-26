<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Free hex color input. The JavaScript of this plugin enhances it with a color picker,
 * clash hints and suggestions (see Resources/assets/color-field.js).
 */
final class FreeColorType extends AbstractType
{
    private const JS_TEXTS = ['pick', 'ok', 'none', 'similar', 'unrelated', 'suggestions', 'use', 'weeks', 'same_customer', 'same_project', 'both_global'];
    private const LEVELS = ['critical', 'warning', 'info'];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            fn (?string $value) => $value,
            function (?string $value): ?string {
                if ($value === null || trim($value) === '') {
                    return null;
                }
                $value = strtolower(trim($value));
                if ($value[0] !== '#') {
                    $value = '#' . $value;
                }
                if (preg_match('/^#[0-9a-f]{3}$/', $value) === 1) {
                    $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
                }

                // invalid values are passed on and rejected by the HexColor constraint of the entity
                return $value;
            }
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => 'color',
            'required' => false,
            'empty_data' => null,
            'documentation' => [
                'type' => 'string',
                'description' => 'Any hexadecimal color code, e.g. #3a7bd5 (Farbfächer plugin)',
            ],
            'farbfaecher_type' => null,
            'farbfaecher_id' => null,
            'farbfaecher_url' => null,
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['attr'] = array_merge($view->vars['attr'], [
            'placeholder' => '#rrggbb',
            'maxlength' => 7,
            'autocomplete' => 'off',
            'data-farbfaecher-type' => $options['farbfaecher_type'],
            'data-farbfaecher-id' => $options['farbfaecher_id'],
            'data-farbfaecher-url' => $options['farbfaecher_url'],
            'data-farbfaecher-i18n' => json_encode($this->texts(), \JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Texts for color-field.js (GUIDELINES 6: no text in JS without a key).
     *
     * @return array<string, string>
     */
    private function texts(): array
    {
        $texts = [];
        foreach (self::JS_TEXTS as $name) {
            $texts[$name] = $this->translator->trans('farbfaecher.field.' . $name);
        }
        foreach (self::LEVELS as $level) {
            $texts['level_' . $level] = $this->translator->trans('farbfaecher.level.' . $level);
        }

        return $texts;
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
