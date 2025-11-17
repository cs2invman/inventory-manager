<?php

namespace App\Form;

use App\Entity\DiscordWebhook;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DiscordWebhookFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('identifier', TextType::class, [
                'label' => 'Identifier',
                'required' => true,
                'attr' => [
                    'placeholder' => 'e.g., system_events',
                    'maxlength' => 50,
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'help' => 'Unique identifier (lowercase letters, numbers, and underscores only)',
            ])
            ->add('displayName', TextType::class, [
                'label' => 'Display Name',
                'required' => true,
                'attr' => [
                    'placeholder' => 'e.g., System Events',
                    'maxlength' => 100,
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'help' => 'Human-readable name for this webhook',
            ])
            ->add('webhookUrl', TextType::class, [
                'label' => 'Webhook URL',
                'required' => true,
                'attr' => [
                    'placeholder' => 'https://discord.com/api/webhooks/...',
                    'maxlength' => 255,
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'help' => 'Discord webhook URL (must start with https://discord.com/api/webhooks/)',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Optional description of webhook purpose...',
                    'rows' => 3,
                    'maxlength' => 500,
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'help' => 'Optional description (max 500 characters)',
            ])
            ->add('isEnabled', CheckboxType::class, [
                'label' => 'Enabled',
                'required' => false,
                'attr' => [
                    'class' => 'rounded border-gray-700 text-cs2-orange focus:ring-cs2-orange focus:ring-offset-gray-800',
                ],
                'help' => 'Enable or disable this webhook',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DiscordWebhook::class,
        ]);
    }
}
