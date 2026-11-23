<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures\Ddd;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The DDD host's step-up form (a one-time code), returned by {@see MemberReauthGate::form()}.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class MemberCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, ['label' => false, 'attr' => ['autocomplete' => 'one-time-code']]);
    }
}
