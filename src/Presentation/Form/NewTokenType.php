<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Form;

use Nowo\ApiTokenBundle\Contract\ModuleDefinition;
use Nowo\ApiTokenBundle\Contract\ReachChoice;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

use function array_combine;
use function array_map;

/**
 * The *New token* form (contract §8.1): re-auth step (when not fresh), name, one select per module (*None* plus its
 * levels), one tick per module flag (the MCP *destructive* flag is off by default), reach (*everything* or chosen
 * places, grouped) and expiry. The issuer validates everything again; the form only offers what may be chosen.
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class NewTokenType extends AbstractType
{
    use FormOptionsTrait;
    use ReauthFieldTrait;

    /** Longest token name (the issuer's limit). */
    public const NAME_MAX = 80;

    /** `reach` value of *everything I can reach*. */
    public const REACH_EVERYTHING = 'everything';

    /** `reach` value of *only these places*. */
    public const REACH_LIMITED = 'limited';

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addReauth($builder, $options);
        $builder->add('name', TextType::class, [
            'label'       => 'form.name',
            'help'        => 'form.name_help',
            'constraints' => [new NotBlank(message: 'nowo_api_token.name.blank'), new Length(max: self::NAME_MAX, maxMessage: 'nowo_api_token.name.too_long')],
            'attr'        => ['maxlength' => self::NAME_MAX, 'autocomplete' => 'off'],
        ]);
        /** @var list<ModuleDefinition> $modules */
        $modules = $options['modules'];
        foreach ($modules as $module) {
            $levels = array_combine(array_map(static fn (string $level): string => 'level.' . $level, $module->levels), $module->levels);
            $builder->add('module_' . $module->key, ChoiceType::class, [
                'label'                        => $module->labelKey !== '' ? $module->labelKey : 'module.' . $module->key,
                'label_translation_parameters' => ['%module%' => $module->key],
                'help'                         => 'form.module_help',
                'choices'                      => ['level.none' => ''] + $levels,
                'required'                     => false,
                'placeholder'                  => false,
            ]);
            foreach ($module->flags as $flag) {
                $builder->add('flag_' . $module->key . '_' . $flag, CheckboxType::class, [
                    'label'    => 'flag.' . $flag,
                    'help'     => 'flag.' . $flag . '_help',
                    'required' => false,
                    'data'     => false,
                ]);
            }
        }
        /** @var list<ReachChoice> $places */
        $places = $options['reach_choices'];
        $modes  = [];
        if ($options['allow_everything'] === true) {
            $modes['form.reach_everything'] = self::REACH_EVERYTHING;
        }
        if ($places !== []) {
            $modes['form.reach_limited'] = self::REACH_LIMITED;
        }
        $builder->add('reach', ChoiceType::class, [
            'label'       => 'form.reach',
            'choices'     => $modes,
            'expanded'    => true,
            'data'        => $options['allow_everything'] === true ? self::REACH_EVERYTHING : self::REACH_LIMITED,
            'constraints' => [new NotBlank(message: 'nowo_api_token.reach.blank')],
        ]);
        if ($places !== []) {
            $grouped = [];
            foreach ($places as $place) {
                $grouped[$place->group !== '' ? $place->group : 'form.reach_places'][$place->label] = $place->key;
            }
            $builder->add('places', ChoiceType::class, [
                'label'                     => 'form.places',
                'choices'                   => $grouped,
                'choice_translation_domain' => false,
                'multiple'                  => true,
                'expanded'                  => true,
                'required'                  => false,
            ]);
        }
        /** @var list<int> $days */
        $days = $options['expiry_choices'];
        $builder->add('expires_in', ChoiceType::class, [
            'label'                         => 'form.expires_in',
            'choices'                       => array_combine(array_map(static fn (int $d): string => 'form.days_' . $d, $days), $days),
            'choice_label'                  => static fn (int $d): string => 'form.days',
            'choice_translation_parameters' => static fn (int $d): array => ['%count%' => $d],
            'data'                          => $options['expiry_default'],
        ]);
    }

    /**
     * Array data, CSRF token `nowo_api_token_new`, the modules, reach choices and expiry choices of the owner.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_api_token_new',
            'translation_domain' => 'NowoApiTokenBundle',
            'modules'            => [],
            'reach_choices'      => [],
            'allow_everything'   => true,
            'expiry_choices'     => [90],
            'expiry_default'     => 90,
        ]);
        $this->configureReauth($resolver);
    }

    /**
     * Field names `nowo_api_token[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_api_token';
    }
}
