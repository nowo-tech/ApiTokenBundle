<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Form;

use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_a;
use function is_string;

/**
 * The re-auth step shared by the *New token* and *Revoke all* forms (contract §6.10): the gate's own form type under
 * `reauth`, or the password field `reauth_password` (`autocomplete="current-password"`, never re-rendered).
 */
trait ReauthFieldTrait
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    private function addReauth(FormBuilderInterface $builder, array $options): void
    {
        if ($options['reauth'] !== true) {
            return;
        }
        $gateForm = $options['reauth_form'];
        if (is_string($gateForm) && is_a($gateForm, FormTypeInterface::class, true)) {
            $builder->add('reauth', $gateForm, ['label' => 'form.reauth', 'mapped' => false]);

            return;
        }
        $builder->add('reauth_password', PasswordType::class, [
            'label'        => 'form.reauth_password',
            'help'         => 'form.reauth_help',
            'mapped'       => false,
            'always_empty' => true,
            'attr'         => ['autocomplete' => 'current-password', 'maxlength' => 4096],
        ]);
    }

    private function configureReauth(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['reauth' => false, 'reauth_form' => null]);
        $resolver->setAllowedTypes('reauth', 'bool');
        $resolver->setAllowedTypes('reauth_form', ['null', 'string']);
    }
}
