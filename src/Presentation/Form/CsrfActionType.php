<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Form;

use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The CSRF-only form of a row action (revoke one token, `REQ-SEC-005`): a hidden marker and `_token`.
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class CsrfActionType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the hidden marker.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('confirm', HiddenType::class, ['mapped' => false, 'data' => '1']);
    }

    /**
     * POST, CSRF on, `csrf_token_id` required.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired('csrf_token_id');
    }

    /**
     * Nameless: the fields are `confirm` and `_token`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
