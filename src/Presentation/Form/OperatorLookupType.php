<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Form;

use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

use function preg_quote;

/**
 * The operator page's *find a leaked token* search (contract §8.2, audit ZAB-S3-M1): a POST form with CSRF, so a
 * pasted secret never lands in a URL (access logs, request logs, history, analytics). The field is a password input
 * that is never printed back; the controller reads only the lookup id and redirects to `?lookup=<lookup id>`.
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class OperatorLookupType extends AbstractType
{
    use FormOptionsTrait;

    /** Form name (the field is `nowo_api_token_lookup[token]`). */
    public const NAME = 'nowo_api_token_lookup';

    /** CSRF token id. */
    public const CSRF_ID = 'nowo_api_token_operator_lookup';

    /**
     * A whole token, a handle or a bare lookup id; group 1 is the lookup id.
     */
    public static function pattern(string $prefix): string
    {
        return '/^(?:' . preg_quote($prefix, '/') . ')?([a-z2-7]{12})(?:_[A-Za-z0-9_-]*)?$/D';
    }

    /**
     * Adds the field.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $prefix */
        $prefix = $options['token_prefix'];
        $builder->add('token', PasswordType::class, [
            'label'        => 'operator.search_token',
            'help'         => 'operator.search_token_help',
            'always_empty' => true,
            'trim'         => true,
            'attr'         => ['maxlength' => TokenCodec::MAX_LENGTH, 'autocomplete' => 'off', 'spellcheck' => 'false', 'data-testid' => 'api-token-operator-search-token'],
            'constraints'  => [
                new NotBlank(message: 'nowo_api_token.lookup.blank'),
                new Length(max: TokenCodec::MAX_LENGTH, maxMessage: 'nowo_api_token.lookup.invalid'),
                new Regex(pattern: self::pattern($prefix), message: 'nowo_api_token.lookup.invalid'),
            ],
        ]);
    }

    /**
     * POST, CSRF on, `token_prefix` required.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_token_id'      => self::CSRF_ID,
            'translation_domain' => 'NowoApiTokenBundle',
        ]);
        $resolver->setRequired('token_prefix');
        $resolver->setAllowedTypes('token_prefix', 'string');
    }

    /**
     * The form name.
     */
    public function getBlockPrefix(): string
    {
        return self::NAME;
    }
}
