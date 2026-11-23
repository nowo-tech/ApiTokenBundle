<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Form;

use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function strlen;

/**
 * Filters of the operator page (contract §8.2), a GET form: owner key (`type:id`) and lookup id or handle
 * (`<prefix><lookup id>`). A whole token never goes here (it would land in the URL): the POST search of
 * {@see OperatorLookupType} reads its lookup id and redirects to this filter (audit ZAB-S3-M1).
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
        /** @var string $prefix */
        $prefix = $options['token_prefix'];
        $builder->add('lookup', TextType::class, ['label' => 'operator.filter_lookup', 'required' => false, 'attr' => ['maxlength' => self::lookupMaxLength($prefix), 'autocomplete' => 'off', 'spellcheck' => 'false']]);
    }

    /**
     * The longest value of the `lookup` filter: a handle (`<prefix><lookup id>`).
     */
    public static function lookupMaxLength(string $prefix): int
    {
        return strlen($prefix) + LookupId::LENGTH;
    }

    /**
     * GET, no CSRF (read-only filter); `token_prefix` sizes the lookup field.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'method'             => 'GET',
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            'translation_domain' => 'NowoApiTokenBundle',
            'token_prefix'       => 'nwt_',
        ]);
        $resolver->setAllowedTypes('token_prefix', 'string');
    }

    /**
     * Nameless: `?owner=…&lookup=…`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
