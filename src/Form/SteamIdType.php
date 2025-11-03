<?php

namespace App\Form;

use App\Validator\Constraints\SteamId;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for Steam ID configuration.
 */
class SteamIdType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('steamId', TextType::class, [
                'label' => 'Steam ID (SteamID64)',
                'required' => false,
                'attr' => [
                    'placeholder' => '76561198XXXXXXXXX (17 digits)',
                    'maxlength' => 17,
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'help' => 'Your 17-digit Steam ID (SteamID64 format). Find it at <a href="https://steamid.io/" target="_blank" class="text-cs2-orange hover:underline">steamid.io</a> or <a href="https://www.steamidfinder.com/" target="_blank" class="text-cs2-orange hover:underline">steamidfinder.com</a>',
                'help_html' => true,
                'constraints' => [
                    new SteamId(),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}
