<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Form;

use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Filters of the operator page (contract §8.2), a GET form: owner key (`type:id`) and lookup id or visible token prefix
 * (an operator finds a token reported as leaked from its first characters).
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class OperatorFilterType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('owner', TextType::class, ['label' => 'operator.filter_owner', 'required' => false, 'attr' => ['maxlength' => 161, 'autocomplete' => 'off']]);
        $builder->add('lookup', TextType::class, ['label' => 'operator.filter_lookup', 'required' => false, 'attr' => ['maxlength' => 64, 'autocomplete' => 'off', 'spellcheck' => 'false']]);
    }

    /**
     * GET, no CSRF (read-only filter).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'method'             => 'GET',
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            'translation_domain' => 'NowoApiTokenBundle',
        ]);
    }

    /**
     * Nameless: `?owner=…&lookup=…`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
