# Currency Display: Forms

**Status**: Completed
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-06
**Part of**: Currency Display Feature (USD/CAD)

## Overview

Create a Symfony form type for currency preferences that handles user input for currency selection and exchange rate configuration. Includes client-side and server-side validation.

## Requirements

Create a form with:
- Currency selection field (USD or CAD)
- Exchange rate input field (decimal, 4 places)
- Validation constraints
- Tailwind CSS styling compatibility

## Implementation Steps

1. **Create Form Type** (src/Form/CurrencyPreferencesType.php)

   ```php
   <?php

   namespace App\Form;

   use Symfony\Component\Form\AbstractType;
   use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
   use Symfony\Component\Form\Extension\Core\Type\NumberType;
   use Symfony\Component\Form\FormBuilderInterface;
   use Symfony\Component\OptionsResolver\OptionsResolver;
   use Symfony\Component\Validator\Constraints as Assert;

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
                       'class' => 'form-input',
                   ],
                   'label_attr' => [
                       'class' => 'form-label',
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
                       'class' => 'form-input',
                       'step' => '0.0001',
                       'min' => '0.01',
                       'max' => '10.00',
                       'placeholder' => '1.3800',
                   ],
                   'label_attr' => [
                       'class' => 'form-label',
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

       public function configureOptions(OptionsResolver $OptionsResolver): void
       {
           $OptionsResolver->setDefaults([
               // No data class - uses array data
           ]);
       }
   }
   ```

2. **Verify Tailwind Classes Exist**
   - Check that `form-input` and `form-label` classes are defined
   - If not, add to main CSS or use inline Tailwind classes

3. **Consider Alternative: Radio Buttons for Currency**
   - If preferred, change `expanded` to `true` for radio buttons
   - May provide better UX for binary choice

## Usage in Controller

```php
use App\Form\CurrencyPreferencesType;

// Create form
$form = $this->createForm(CurrencyPreferencesType::class, [
    'preferredCurrency' => $config->getPreferredCurrency(),
    'cadExchangeRate' => $config->getCadExchangeRate(),
]);

// Handle request
$form->handleRequest($request);

if ($form->isSubmitted() && $form->isValid()) {
    $data = $form->getData();

    $userConfigService->setCurrencyPreferences(
        $user,
        $data['preferredCurrency'],
        $data['cadExchangeRate']
    );

    $this->addFlash('success', 'Currency preferences updated!');
    return $this->redirectToRoute('app_settings_index');
}
```

## Acceptance Criteria

- [ ] CurrencyPreferencesType.php created in src/Form/
- [ ] Form has `preferredCurrency` field as ChoiceType
- [ ] Form has `cadExchangeRate` field as NumberType
- [ ] Currency field has choices for USD and CAD only
- [ ] Exchange rate field accepts decimals with 4 decimal places
- [ ] Exchange rate field has min=0.01, max=10.00, step=0.0001
- [ ] NotBlank validation on both fields
- [ ] Choice validation on currency field
- [ ] Range validation on exchange rate field
- [ ] Positive validation on exchange rate field
- [ ] Help text explains exchange rate meaning
- [ ] Form styling compatible with existing Tailwind theme
- [ ] Form can be instantiated and rendered without errors

## Testing

### Form Rendering Test

Create a test route to render the form:

```php
#[Route('/test/currency-form', name: 'test_currency_form')]
public function testCurrencyForm(Request $request): Response
{
    $form = $this->createForm(CurrencyPreferencesType::class, [
        'preferredCurrency' => 'USD',
        'cadExchangeRate' => 1.38,
    ]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        dump($form->getData());
    }

    return $this->render('test/currency_form.html.twig', [
        'form' => $form->createView(),
    ]);
}
```

### Validation Test Cases

Submit the form with various inputs:

**Valid submissions:**
- USD, 1.38 → Success
- CAD, 1.40 → Success
- CAD, 0.01 → Success (minimum)
- CAD, 10.00 → Success (maximum)
- CAD, 1.3845 → Success (4 decimals)

**Invalid submissions:**
- Empty currency → Error: "Please select a currency"
- Empty exchange rate → Error: "Please enter an exchange rate"
- Exchange rate: 0.001 → Error: "Exchange rate must be between 0.01 and 10.00"
- Exchange rate: 11.00 → Error: "Exchange rate must be between 0.01 and 10.00"
- Exchange rate: -1.38 → Error: "Exchange rate must be a positive number"

### Visual Testing

- [ ] Form fields render properly in settings page
- [ ] Labels are styled correctly
- [ ] Input fields have proper width and height
- [ ] Help text displays below exchange rate field
- [ ] Validation errors display in red
- [ ] Dropdown/select has proper styling
- [ ] Number input has increment/decrement arrows (HTML5)

## Alternative Implementations

### Option 1: Radio Buttons for Currency
```php
'expanded' => true, // Radio buttons instead of dropdown
'attr' => [
    'class' => 'flex gap-4', // Horizontal layout
],
```

### Option 2: Separate the Forms
Instead of one combined form, create separate forms for Steam ID and Currency. This provides more modularity but requires more controller logic.

## Notes

- Form uses array data instead of entity binding (simpler for this use case)
- Validation is duplicated in service layer (defense in depth)
- HTML5 attributes provide client-side validation for better UX
- Step of 0.0001 allows precise exchange rates (e.g., 1.3845)
- Consider adding JavaScript to show live conversion example as user types

## Future Enhancements (Out of Scope)

- Add "Fetch Current Rate" button that populates field via AJAX
- Show live conversion example: "$10.00 USD = $X.XX CAD"
- Add tooltip with historical exchange rate information

## Dependencies

- **Requires**: Task 14 (Database & Entity Changes) - for field names
- **Required by**: Task 18 (Settings Page) - will use this form

## Related Tasks

- Task 14: Currency Display - Database & Entity Changes
- Task 16: Currency Display - Service Layer (validation mirrors this)
- Task 18: Currency Display - Settings Page (will render this form)
