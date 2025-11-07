<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form type for currency preferences configuration.
 */
class CurrencyPreferencesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('preferredCurrency', ChoiceType::class, [
                'label' => 'Preferred Currency',
                'choices' => [
                    'US Dollar (USD)' => 'USD',
                    'Canadian Dollar (CAD)' => 'CAD',
                ],
                'expanded' => false, // Dropdown instead of radio buttons
                'placeholder' => 'Select currency...',
                'attr' => [
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please select a currency',
                    ]),
                    new Assert\Choice([
                        'choices' => ['USD', 'CAD'],
                        'message' => 'Please select a valid currency',
                    ]),
                ],
            ])
            ->add('cadExchangeRate', NumberType::class, [
                'label' => 'CAD Exchange Rate',
                'help' => 'Exchange rate for converting USD to CAD (e.g., 1.38 means $1 USD = $1.38 CAD)',
                'scale' => 4, // 4 decimal places
                'html5' => true,
                'attr' => [
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                    'step' => '0.0001',
                    'min' => '0.01',
                    'max' => '10.00',
                    'placeholder' => '1.3800',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please enter an exchange rate',
                    ]),
                    new Assert\Positive([
                        'message' => 'Exchange rate must be a positive number',
                    ]),
                    new Assert\Range([
                        'min' => 0.01,
                        'max' => 10.00,
                        'notInRangeMessage' => 'Exchange rate must be between {{ min }} and {{ max }}',
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // No data class - uses array data
        ]);
    }
}
