<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Presentation\Form\NewTokenType;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;

/**
 * Audit ZAB-S3-I5 probe: once armed, moves the test clock past the re-auth freshness while the *New token* form is
 * being submitted, i.e. after the form was built without the re-auth step and before the controller checks it.
 */
final class ExpireReauthOnSubmit extends AbstractTypeExtension
{
    public bool $armed = false;

    public function __construct(private readonly MockClock $clock)
    {
    }

    public static function getExtendedTypes(): iterable
    {
        return [NewTokenType::class];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (): void {
            if ($this->armed) {
                $this->armed = false;
                $this->clock->modify('+301 seconds');
            }
        });
    }
}
