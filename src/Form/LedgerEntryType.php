<?php

namespace App\Form;

use App\Entity\LedgerEntry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class LedgerEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('transactionDate', DateType::class, [
                'label' => 'Transaction Date',
                'widget' => 'single_text',
                'required' => true,
                'attr' => [
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please select a transaction date',
                    ]),
                ],
            ])
            ->add('transactionType', ChoiceType::class, [
                'label' => 'Transaction Type',
                'required' => true,
                'choices' => [
                    'Investment (Money In)' => 'investment',
                    'Withdrawal (Money Out)' => 'withdrawal',
                ],
                'attr' => [
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please select a transaction type',
                    ]),
                    new Assert\Choice([
                        'choices' => ['investment', 'withdrawal'],
                        'message' => 'Please select a valid transaction type',
                    ]),
                ],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Amount',
                'required' => true,
                'scale' => 2,
                'attr' => [
                    'placeholder' => '0.00',
                    'step' => '0.01',
                    'min' => '0.01',
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'help' => 'Enter the amount with up to 2 decimal places',
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please enter an amount',
                    ]),
                    new Assert\Positive([
                        'message' => 'Amount must be greater than zero',
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^\d+(\.\d{1,2})?$/',
                        'message' => 'Amount must have at most 2 decimal places',
                    ]),
                ],
            ])
            ->add('currency', ChoiceType::class, [
                'label' => 'Currency',
                'required' => true,
                'choices' => [
                    'USD' => 'USD',
                    'CAD' => 'CAD',
                ],
                'attr' => [
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
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
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Optional notes about this transaction...',
                    'rows' => 3,
                    'maxlength' => 1000,
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'help' => 'Optional description (max 1000 characters)',
                'constraints' => [
                    new Assert\Length([
                        'max' => 1000,
                        'maxMessage' => 'Description cannot be longer than {{ limit }} characters',
                    ]),
                ],
            ])
            ->add('category', TextType::class, [
                'label' => 'Category',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., Trading, Cases, Market Investment...',
                    'maxlength' => 100,
                    'class' => 'w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cs2-orange focus:border-transparent',
                ],
                'help' => 'Optional category for organization (max 100 characters)',
                'constraints' => [
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Category cannot be longer than {{ limit }} characters',
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LedgerEntry::class,
        ]);
    }
}
