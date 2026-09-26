<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Form;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Form\ActivityEditForm;
use App\Form\CustomerEditForm;
use App\Form\ProjectEditForm;
use KimaiPlugin\FarbfaecherBundle\Configuration\FarbfaecherConfiguration;
use KimaiPlugin\FarbfaecherBundle\Model\ColorNode;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Replaces Kimai's fixed color list with a free color field in the customer, project and activity forms.
 * The API forms use these forms as parent, so the API accepts any hex color as well.
 */
final class ColorFieldExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly FarbfaecherConfiguration $configuration,
        private readonly AuthorizationCheckerInterface $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [CustomerEditForm::class, ProjectEditForm::class, ActivityEditForm::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$builder->has('color') || !$this->configuration->isFreeColors()) {
            return;
        }

        $data = $options['data'] ?? null;
        $type = match (true) {
            $data instanceof Customer => ColorNode::CUSTOMER,
            $data instanceof Project => ColorNode::PROJECT,
            $data instanceof Activity => ColorNode::ACTIVITY,
            default => null,
        };

        $url = null;
        if ($type !== null && $this->security->isGranted('farbfaecher')) {
            $url = $this->urlGenerator->generate('farbfaecher_suggest');
        }

        $builder->add('color', FreeColorType::class, [
            'farbfaecher_type' => $type,
            'farbfaecher_id' => $type !== null ? $data->getId() : null,
            'farbfaecher_url' => $url,
            'help' => $url !== null ? null : 'farbfaecher.field.help',
        ]);
    }
}
