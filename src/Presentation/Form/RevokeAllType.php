<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Form;

use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * *Revoke all* (contract §6.6, §6.10): the re-auth step (when not fresh) and an explicit confirmation tick.
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class RevokeAllType extends AbstractType
{
    use FormOptionsTrait;
    use ReauthFieldTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addReauth($builder, $options);
        $builder->add('confirm', CheckboxType::class, [
            'label'       => 'form.revoke_all_confirm',
            'required'    => true,
            'constraints' => [new IsTrue(message: 'nowo_api_token.revoke_all.confirm')],
        ]);
    }

    /**
     * Array data, CSRF token `nowo_api_token_revoke_all`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_api_token_revoke_all',
            'translation_domain' => 'NowoApiTokenBundle',
        ]);
        $this->configureReauth($resolver);
    }

    /**
     * Field names `nowo_api_token_revoke_all[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_api_token_revoke_all';
    }
}
