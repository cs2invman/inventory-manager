# Currency Display: Settings Page UI

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-06
**Part of**: Currency Display Feature (USD/CAD)

## Overview

Add currency preferences section to the existing user settings page. Users will be able to select their preferred display currency (USD or CAD) and configure the CAD exchange rate.

## Requirements

- Add new "Currency Preferences" card to settings page
- Display current currency and exchange rate
- Render currency preferences form
- Handle form submission
- Show success/error messages
- Maintain consistent styling with existing settings

## Implementation Steps

1. **Update UserSettingsController** (src/Controller/UserSettingsController.php)

   Modify the `index()` method:

   ```php
   use App\Form\CurrencyPreferencesType;

   #[Route('', name: 'app_settings_index', methods: ['GET', 'POST'])]
   public function index(Request $request): Response
   {
       $user = $this->getUser();
       $config = $this->userConfigService->getUserConfig($user);

       // Steam ID Form (existing)
       $steamIdForm = $this->createForm(SteamIdType::class, [
           'steamId' => $config->getSteamId(),
       ]);

       // Currency Preferences Form (new)
       $currencyForm = $this->createForm(CurrencyPreferencesType::class, [
           'preferredCurrency' => $config->getPreferredCurrency() ?? 'USD',
           'cadExchangeRate' => $config->getCadExchangeRate() ?? 1.38,
       ]);

       // Handle Steam ID form
       $steamIdForm->handleRequest($request);
       if ($steamIdForm->isSubmitted() && $steamIdForm->isValid()) {
           $data = $steamIdForm->getData();
           try {
               $this->userConfigService->setSteamId($user, $data['steamId']);
               $this->addFlash('success', 'Steam ID saved successfully!');
               return $this->redirectToRoute('app_settings_index');
           } catch (\InvalidArgumentException $e) {
               $this->addFlash('error', $e->getMessage());
           }
       }

       // Handle Currency form
       $currencyForm->handleRequest($request);
       if ($currencyForm->isSubmitted() && $currencyForm->isValid()) {
           $data = $currencyForm->getData();
           try {
               $this->userConfigService->setCurrencyPreferences(
                   $user,
                   $data['preferredCurrency'],
                   $data['cadExchangeRate']
               );
               $this->addFlash('success', 'Currency preferences saved successfully!');
               return $this->redirectToRoute('app_settings_index');
           } catch (\InvalidArgumentException $e) {
               $this->addFlash('error', $e->getMessage());
           }
       }

       return $this->render('settings/index.html.twig', [
           'steamIdForm' => $steamIdForm->createView(),
           'currencyForm' => $currencyForm->createView(),
           'currentSteamId' => $config->getSteamId(),
           'currentCurrency' => $config->getPreferredCurrency() ?? 'USD',
           'currentExchangeRate' => $config->getCadExchangeRate() ?? 1.38,
       ]);
   }
   ```

2. **Update Settings Template** (templates/settings/index.html.twig)

   Add new currency section after the Steam Configuration card (after line 85):

   ```twig
   <!-- Currency Preferences Card -->
   <div class="card mb-6">
       <h3 class="text-xl font-semibold text-white mb-4">Currency Preferences</h3>

       {% if currentCurrency %}
           <div class="mb-6 p-4 bg-gray-800 border border-gray-700 rounded-lg">
               <div class="flex items-center justify-between">
                   <div>
                       <p class="text-sm text-gray-400">Current Display Currency</p>
                       <p class="text-lg font-semibold text-white">
                           {% if currentCurrency == 'CAD' %}
                               Canadian Dollar (CAD)
                           {% else %}
                               US Dollar (USD)
                           {% endif %}
                       </p>
                   </div>
                   {% if currentCurrency == 'CAD' %}
                       <div class="text-right">
                           <p class="text-sm text-gray-400">Exchange Rate</p>
                           <p class="text-lg font-semibold text-cs2-orange">{{ currentExchangeRate|number_format(4, '.', ',') }}</p>
                           <p class="text-xs text-gray-500 mt-1">$1.00 USD = CA${{ currentExchangeRate|number_format(2, '.', ',') }}</p>
                       </div>
                   {% endif %}
               </div>
           </div>
       {% endif %}

       {{ form_start(currencyForm) }}
           <div class="space-y-4 mb-6">
               {{ form_row(currencyForm.preferredCurrency) }}

               {{ form_row(currencyForm.cadExchangeRate) }}

               <!-- Help Text with Example -->
               <div class="p-4 bg-gray-800 rounded-lg border border-gray-700">
                   <h4 class="text-sm font-semibold text-white mb-2">How currency display works:</h4>
                   <ul class="list-disc list-inside space-y-1 text-sm text-gray-300">
                       <li>All prices are stored in USD (Steam's default currency)</li>
                       <li>Selecting CAD will convert prices for display only</li>
                       <li>You can update the exchange rate anytime to match current rates</li>
                       <li>Example: If rate is 1.38, a $10.00 USD item displays as CA$13.80</li>
                   </ul>
               </div>
           </div>

           <div class="flex justify-between items-center">
               <a href="{{ path('app_dashboard') }}" class="btn-secondary">
                   Cancel
               </a>
               <button type="submit" class="btn-primary">
                   Save Currency Preferences
               </button>
           </div>
       {{ form_end(currencyForm) }}
   </div>
   ```

3. **Update "More Settings Coming Soon" Card**
   - This placeholder can remain or be removed since we're adding real settings now
   - Optional: Update text to mention other future features

4. **Test Form Styling**
   - Verify form fields match existing Steam ID form styling
   - Ensure labels, inputs, and help text are properly styled
   - Check responsive behavior on mobile

## Acceptance Criteria

- [ ] Currency Preferences card appears on settings page
- [ ] Card positioned after Steam Configuration, before placeholder card
- [ ] Current currency displays prominently (USD or CAD)
- [ ] Current exchange rate displays when CAD is selected
- [ ] Currency selection dropdown renders correctly
- [ ] Exchange rate input field renders correctly
- [ ] Help text explains how currency display works
- [ ] Example conversion shown (e.g., "$1.00 USD = CA$1.38")
- [ ] Save button submits currency form
- [ ] Success flash message appears after saving
- [ ] Error flash message appears on validation failure
- [ ] Form redirects to settings page after successful save
- [ ] Page styling consistent with existing Steam ID section
- [ ] Both forms (Steam ID and Currency) can be submitted independently

## Testing

### Manual Testing Workflow

1. **Navigate to Settings**
   - Go to `/settings`
   - Verify Currency Preferences card is visible
   - Verify current values display correctly

2. **Test USD Selection**
   - Select "US Dollar (USD)"
   - Enter 1.38 for exchange rate (though unused for USD)
   - Click "Save Currency Preferences"
   - Verify success message appears
   - Verify current currency shows "US Dollar (USD)"

3. **Test CAD Selection**
   - Select "Canadian Dollar (CAD)"
   - Enter 1.40 for exchange rate
   - Click "Save Currency Preferences"
   - Verify success message appears
   - Verify current currency shows "Canadian Dollar (CAD)"
   - Verify exchange rate shows 1.4000
   - Verify example text shows correct conversion

4. **Test Validation**
   - Try submitting with empty currency → Error
   - Try exchange rate 0.001 → Error message
   - Try exchange rate 11.00 → Error message
   - Try exchange rate -1.38 → Error message
   - Verify error messages display properly

5. **Test Form Independence**
   - Submit Steam ID form → Only Steam ID changes
   - Submit Currency form → Only currency changes
   - Both forms should work independently

6. **Visual Testing**
   - Check on desktop (1920px, 1366px)
   - Check on tablet (768px)
   - Check on mobile (375px)
   - Verify cards stack properly
   - Verify buttons are properly sized

## Visual Design

Match existing settings page design:
- Card background: `bg-gray-800/50 border-gray-700` or similar
- Headers: `text-xl font-semibold text-white`
- Current value display: Gray box with border
- Form inputs: Match Steam ID form styling
- Buttons: `btn-primary` and `btn-secondary` classes
- Help text: Gray background box with list

## Notes

- Two separate forms on same page (Steam ID and Currency)
- Each form submits independently
- Both forms POST to same route but are handled separately
- Flash messages differentiate which setting was saved
- Consider adding form names to make submission handling clearer:
  ```php
  $steamIdForm = $this->createForm(SteamIdType::class, [...], [
      'attr' => ['id' => 'steam-id-form']
  ]);
  ```

## Edge Cases

- **First-time user**: Defaults to USD and 1.38 (from DB defaults)
- **User without config**: UserConfigService creates config on first access
- **Concurrent form submission**: Symfony handles this via CSRF tokens
- **Invalid form data**: Caught by form validation, displays errors

## Dependencies

- **Requires**:
  - Task 14 (Database & Entity Changes)
  - Task 16 (Service Layer)
  - Task 17 (Forms)
- **Required by**: None (but users need this to configure currency before seeing converted prices)

## Related Tasks

- Task 14: Currency Display - Database & Entity Changes
- Task 16: Currency Display - Service Layer
- Task 17: Currency Display - Forms
- Task 19: Currency Display - Inventory Pages (users will configure currency here first)
