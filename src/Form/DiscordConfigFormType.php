<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DiscordConfigFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $configs = $options['configs'] ?? [];

        foreach ($configs as $config) {
            $configBuilder = $builder->create('config_' . $config->getId(), FormType::class, [
                'mapped' => false,
            ]);

            $configBuilder
                ->add('value', TextType::class, [
                    'label' => false,
                    'required' => false,
                    'data' => $config->getConfigValue(),
                    'attr' => [
                        'class' => 'w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                        'placeholder' => 'Value',
                    ],
                ])
                ->add('enabled', CheckboxType::class, [
                    'label' => 'Enabled',
                    'required' => false,
                    'data' => $config->getIsEnabled(),
                    'attr' => [
                        'class' => 'rounded border-gray-700 text-cs2-orange focus:ring-cs2-orange focus:ring-offset-gray-800',
                    ],
                ]);

            $builder->add($configBuilder);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'configs' => [],
        ]);
    }
}
