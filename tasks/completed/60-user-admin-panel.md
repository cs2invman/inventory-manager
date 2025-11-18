# User Admin Panel

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-17

## Overview

Create a user management page in the admin panel that allows administrators to create, disable, change passwords, and view statistics for all users. The page will display user information including last login time and total inventory count (main inventory + storage boxes).

## Problem Statement

Administrators need:
- A way to create new users with randomly generated secure passwords
- Ability to enable/disable user accounts
- Ability to reset user passwords
- Ability to manage user roles (ROLE_ADMIN)
- View user activity (last login timestamp)
- View user inventory statistics (total item count including storage boxes)
- A centralized interface for all user management tasks

Currently, user creation is only possible via console command (`app:create-user`), and there's no way to disable accounts or reset passwords through the UI.

## Requirements

### Functional Requirements

**User Table Display:**
- List all users in a table
- Columns: ID, Email, Full Name, Roles, Status (Active/Disabled), Last Login, Item Count, Created Date, Actions
- Sort by created date (newest first)
- Show role badges (ROLE_ADMIN, ROLE_USER)
- Show active/disabled status badge
- Show "Never" if user has never logged in
- Show total item count (main inventory + all storage boxes)

**Create User:**
- Form fields: Email, First Name, Last Name, Role (checkbox for ROLE_ADMIN)
- Generate random 24-character alphanumeric password automatically
- Display generated password in flash message after creation
- New users are active by default
- Validate email uniqueness

**Edit User:**
- Inline or modal form to edit: First Name, Last Name, Email
- Update roles (add/remove ROLE_ADMIN via checkbox)
- Cannot edit own admin status (prevent lockout)

**Change Password:**
- Generate new random 24-character alphanumeric password
- Display new password in flash message
- No old password verification needed (admin override)

**Disable/Enable User:**
- Toggle `isActive` flag
- Show confirmation for disable action
- Cannot disable own account (prevent lockout)
- Disabled users cannot log in

### Non-Functional Requirements

- Secure: only ROLE_ADMIN can access
- CSRF protection on all forms
- Responsive UI (Tailwind CSS)
- Form validation with error messages
- Success/error flash messages
- Generate cryptographically secure random passwords
- Last login tracking already implemented via UserLoginListener
- Item count query optimized (single COUNT query with JOINs)

## Technical Approach

### Controller

#### New Controller: `UserAdminController`
Location: `src/Controller/UserAdminController.php`

**Route Prefix:** `/admin/users`

**Routes:**
1. `GET /admin/users` - Main user management page
2. `POST /admin/users/create` - Create new user
3. `POST /admin/users/{id}/edit` - Edit user details
4. `POST /admin/users/{id}/password` - Change user password
5. `POST /admin/users/{id}/toggle` - Toggle user active status
6. `POST /admin/users/{id}/role` - Toggle ROLE_ADMIN role

**Security:**
- All routes require `ROLE_ADMIN`
- CSRF protection on all forms
- Prevent admin from disabling own account
- Prevent admin from removing own ROLE_ADMIN

**Dependencies:**
- `UserRepository`
- `EntityManagerInterface`
- `UserPasswordHasherInterface`
- `FormFactoryInterface`
- Inject current user via Security component for self-protection checks

**Helper Method:**
- `generateRandomPassword(): string` - Generates secure 24-char alphanumeric password using `random_bytes()` or `bin2hex(random_bytes(12))`

### Repository

#### Update: `UserRepository`
Location: `src/Repository/UserRepository.php`

**New Method:**
```php
public function findAllWithItemCounts(): array
{
    return $this->createQueryBuilder('u')
        ->select('u', 'COUNT(DISTINCT iu.id) as itemCount')
        ->leftJoin('u.inventory', 'iu')
        ->groupBy('u.id')
        ->orderBy('u.createdAt', 'DESC')
        ->getQuery()
        ->getResult();
}
```

This query counts all ItemUser records for each user (including items in storage boxes, since storage box items are still ItemUser records with a non-null storageBox).

### Forms

#### New Form: `UserCreateFormType`
Location: `src/Form/UserCreateFormType.php`

**Purpose:** Create new user

**Fields:**
- `email` (EmailType) - User email (unique)
- `firstName` (TextType) - First name
- `lastName` (TextType) - Last name
- `isAdmin` (CheckboxType) - Grant ROLE_ADMIN role

**Validation:**
- email: Required, valid email format, unique
- firstName: Required, max 100 chars
- lastName: Required, max 100 chars
- isAdmin: Optional (defaults to false)

**Note:** Password is auto-generated, not in form

---

#### New Form: `UserEditFormType`
Location: `src/Form/UserEditFormType.php`

**Purpose:** Edit existing user

**Fields:**
- `email` (EmailType) - User email
- `firstName` (TextType) - First name
- `lastName` (TextType) - Last name
- `isAdmin` (CheckboxType) - Has ROLE_ADMIN role

**Validation:**
- Same as UserCreateFormType
- Email unique constraint (excluding current user)

### Templates

#### New Template: `templates/user_admin/index.html.twig`
Location: `templates/user_admin/index.html.twig`

**Purpose:** User management page

**Sections:**

1. **Page Header**
   - Title: "User Management"
   - Description: "Manage user accounts, roles, and permissions"

2. **Create User Form**
   - Card with form (email, firstName, lastName, isAdmin checkbox)
   - Submit button: "Create User"
   - Display generated password in flash message after creation
   - Example: "User created successfully! Temporary password: Xy9Kp2Lm3Qr8Wz4Vn7Jt5Hg1"

3. **Users Table**
   - Columns:
     - **ID**: User ID
     - **Email**: User email
     - **Name**: Full name (firstName lastName)
     - **Roles**: Badge for ROLE_ADMIN, ROLE_USER
     - **Status**: Active (green badge) / Disabled (red badge)
     - **Last Login**: Formatted timestamp or "Never"
     - **Items**: Total count (main + storage boxes)
     - **Created**: Account creation date
     - **Actions**: Edit, Change Password, Disable/Enable buttons
   - Action buttons:
     - Edit (pencil icon) - Opens inline form or small modal
     - Change Password (key icon) - Generates new password
     - Disable/Enable (toggle icon) - Toggles isActive
   - Self-protection: Disable "Disable" and "Remove Admin" buttons for current user

4. **Edit User Inline Form** (optional, can be below table or modal)
   - Same fields as create form
   - Pre-populated with user data
   - Save/Cancel buttons

**Styling:**
- Use Tailwind CSS classes consistent with project
- Role badges: ROLE_ADMIN (red), ROLE_USER (blue)
- Status badges: Active (green), Disabled (red)
- Action buttons: Icon buttons with tooltips
- Responsive table (scrollable on mobile)

**Flash Messages:**
- Success: "User {email} created successfully! Temporary password: {password}"
- Success: "User {email} updated successfully"
- Success: "Password changed for {email}. New password: {password}"
- Success: "User {email} enabled/disabled"
- Error: "User with email {email} already exists"
- Error: "Cannot disable your own account"
- Error: "Cannot remove your own admin role"

---

#### Update Template: `templates/admin/index.html.twig`
Location: `templates/admin/index.html.twig`

**Change:** Update "User Management" card to link to new page

**Implementation:**
```twig
<a href="{{ path('user_admin_index') }}" class="card hover:bg-gray-750 transition-colors group">
    <!-- Remove opacity-50 and cursor-not-allowed -->
    <!-- Update description -->
    <p class="text-sm text-gray-400">Manage users, roles, and permissions</p>
</a>
```

## Implementation Steps

1. **Create UserAdminController**
   - Create `src/Controller/UserAdminController.php`
   - Add route annotations with `/admin/users` prefix
   - Security: `#[IsGranted('ROLE_ADMIN')]`
   - Inject dependencies: UserRepository, EntityManagerInterface, UserPasswordHasherInterface, FormFactoryInterface, Security
   - Add helper method: `generateRandomPassword()`

2. **Update UserRepository**
   - Add `findAllWithItemCounts()` method
   - Use QueryBuilder with COUNT and GROUP BY
   - Return array with user entities and itemCount

3. **Implement Main User Management Page (GET /admin/users)**
   - Fetch all users with item counts using repository method
   - Create UserCreateFormType instance
   - Render `user_admin/index.html.twig`
   - Pass users array and create form

4. **Create UserCreateFormType**
   - Create `src/Form/UserCreateFormType.php`
   - Fields: email, firstName, lastName, isAdmin
   - Add validation constraints

5. **Implement Create User (POST /admin/users/create)**
   - Handle UserCreateFormType submission
   - Validate form
   - Check email uniqueness
   - Generate random 24-char alphanumeric password
   - Create User entity, set fields
   - Set roles: ['ROLE_USER'] or ['ROLE_USER', 'ROLE_ADMIN']
   - Hash password and save
   - Add flash message with generated password
   - Redirect back to user management page

6. **Create UserEditFormType**
   - Create `src/Form/UserEditFormType.php`
   - Same fields as create form
   - Pre-populate with existing user data

7. **Implement Edit User (POST /admin/users/{id}/edit)**
   - Find user by ID
   - Handle UserEditFormType submission
   - Validate form
   - Check email uniqueness (excluding current user)
   - Self-protection: Cannot remove own ROLE_ADMIN
   - Update user entity
   - Update roles based on isAdmin checkbox
   - Add flash message: "User updated successfully"
   - Redirect back to user management page

8. **Implement Change Password (POST /admin/users/{id}/password)**
   - Find user by ID
   - Generate new random 24-char alphanumeric password
   - Hash password
   - Update user password
   - Add flash message with new password
   - Redirect back to user management page

9. **Implement Toggle Active Status (POST /admin/users/{id}/toggle)**
   - Find user by ID
   - Self-protection: Cannot disable own account
   - Toggle `isActive` flag
   - Add flash message: "User {email} enabled/disabled"
   - Redirect back to user management page

10. **Implement Toggle Admin Role (POST /admin/users/{id}/role)**
    - Find user by ID
    - Self-protection: Cannot remove own ROLE_ADMIN
    - Toggle ROLE_ADMIN in roles array
    - Add flash message: "Admin role updated for {email}"
    - Redirect back to user management page

11. **Create User Admin Template**
    - Create `templates/user_admin/index.html.twig`
    - Extend base layout
    - Add page header
    - Add create user form section
    - Add users table with all columns
    - Add action buttons (edit, change password, disable/enable)
    - Add inline edit form (or modal)
    - Use Tailwind CSS for styling
    - Add role and status badges
    - Format timestamps (last login, created date)
    - Display item counts

12. **Update Admin Panel Homepage**
    - Edit `templates/admin/index.html.twig`
    - Update "User Management" card to link to `user_admin_index`
    - Remove opacity and cursor-not-allowed classes
    - Update description text

13. **Add Flash Message Handling**
    - Ensure flash messages display in template
    - Use existing flash message styles (success, error)
    - Display generated passwords prominently in success messages

14. **Test Self-Protection Logic**
    - Verify admin cannot disable own account
    - Verify admin cannot remove own ROLE_ADMIN
    - Verify buttons are disabled for current user in UI

## Edge Cases & Error Handling

- **Duplicate email**: Show form validation error, don't save
- **Invalid email format**: Show form validation error
- **Admin tries to disable own account**: Show error flash message, prevent action
- **Admin tries to remove own ROLE_ADMIN**: Show error flash message, prevent action
- **User with no items**: Show "0" in item count column
- **User never logged in**: Show "Never" in last login column
- **Generated password display**: Show in flash message (not persistent), admin should copy it immediately
- **Editing non-existent user**: Return 404 error
- **Very long names/emails**: Truncate in table with tooltips
- **Concurrent edits**: Last save wins (no locking needed for admin-only page)
- **Disabled user tries to login**: Symfony security handles this automatically
- **Password generation randomness**: Use `random_bytes()` for cryptographically secure randomness

## Dependencies

### Blocking Dependencies
- None (User entity and authentication system already exist)

### Related Tasks
- Task 59: Admin panel with Discord management (provides admin panel homepage and navigation pattern)

### Can Be Done in Parallel With
- Task 47: Admin settings unified panel (both extend admin panel)
- Any other admin features

### External Dependencies
- Symfony Form component (already in project)
- Symfony Validator component (already in project)
- Symfony Security component (already in project)
- Symfony PasswordHasher component (already in project)
- Tailwind CSS (already in project)

## Acceptance Criteria

**General:**
- [ ] UserAdminController created with all required routes
- [ ] All routes restricted to ROLE_ADMIN
- [ ] User management page displays all sections: Create form, Users table
- [ ] Admin panel homepage updated with working link to user management

**Create User:**
- [ ] UserCreateFormType created and functional
- [ ] Form validates email, firstName, lastName
- [ ] Email uniqueness validated
- [ ] Random 24-character alphanumeric password generated on creation
- [ ] Generated password displayed in flash message
- [ ] User created successfully with hashed password
- [ ] ROLE_ADMIN granted if isAdmin checkbox checked
- [ ] New users are active by default

**Edit User:**
- [ ] UserEditFormType created and functional
- [ ] Form pre-populated with existing user data
- [ ] Email uniqueness validated (excluding current user)
- [ ] Roles updated based on isAdmin checkbox
- [ ] Self-protection: Cannot remove own ROLE_ADMIN
- [ ] Flash message appears on save

**Change Password:**
- [ ] New random 24-character alphanumeric password generated
- [ ] Password hashed and saved
- [ ] New password displayed in flash message
- [ ] Flash message appears after password change

**Toggle Active Status:**
- [ ] isActive flag toggled successfully
- [ ] Self-protection: Cannot disable own account
- [ ] Flash message appears for enable/disable actions
- [ ] Disabled users cannot log in (Symfony security handles)

**Users Table:**
- [ ] All users displayed in table
- [ ] Columns: ID, Email, Name, Roles, Status, Last Login, Items, Created, Actions
- [ ] Sorted by created date (newest first)
- [ ] Role badges displayed (ROLE_ADMIN, ROLE_USER)
- [ ] Status badges displayed (Active, Disabled)
- [ ] Last login shows timestamp or "Never"
- [ ] Item count shows total (main inventory + storage boxes)
- [ ] Action buttons: Edit, Change Password, Disable/Enable
- [ ] Self-protection: Disable/Remove Admin buttons disabled for current user

**Repository:**
- [ ] UserRepository.findAllWithItemCounts() method created
- [ ] Method returns users with item counts
- [ ] Query optimized with single COUNT and JOINs

**Template:**
- [ ] Template uses Tailwind CSS and matches project styling
- [ ] Flash messages appear for all actions (success/error)
- [ ] CSRF protection enabled on all forms
- [ ] Page is responsive (works on mobile/tablet)
- [ ] Generated passwords prominently displayed in flash messages

## Manual Verification Steps

### Access User Management Page
```bash
# 1. Ensure you have admin user
docker compose exec php php bin/console app:create-user admin@example.com Admin User --admin

# 2. Login to web application as admin
# Navigate to: http://localhost
# Click "Admin" link in header
# Click "User Management" card
# Should navigate to: http://localhost/admin/users
```

### Test Create User
1. **Fill Create User Form**
   - Email: `test@example.com`
   - First Name: `Test`
   - Last Name: `User`
   - Is Admin: Unchecked
   - Click "Create User"

2. **Verify Creation**
   - Should see success flash message: "User test@example.com created successfully! Temporary password: {24-char password}"
   - Copy the password for later use
   - User should appear in table below
   - Status: Active (green badge)
   - Roles: ROLE_USER (blue badge)
   - Last Login: "Never"
   - Items: 0

3. **Test Login with New User**
   - Logout as admin
   - Login with test@example.com and the generated password
   - Should successfully log in
   - Note the current timestamp

4. **Verify Last Login Tracking**
   - Logout and login as admin again
   - Navigate back to User Management
   - test@example.com should now show last login timestamp

### Test Create Admin User
1. **Fill Create User Form**
   - Email: `admin2@example.com`
   - First Name: `Admin`
   - Last Name: `Two`
   - Is Admin: **Checked**
   - Click "Create User"

2. **Verify Admin Creation**
   - Should see success flash message with password
   - User should appear in table
   - Roles: Should show both ROLE_USER and ROLE_ADMIN badges

### Test Edit User
1. **Click Edit button** next to test@example.com
2. **Edit form should appear** (inline or modal)
   - Email: Change to `updated@example.com`
   - First Name: Change to `Updated`
   - Last Name: Change to `Name`
   - Is Admin: Check to grant admin rights
   - Click "Save"

3. **Verify Update**
   - Should see success flash message: "User updated@example.com updated successfully"
   - Table should reflect changes
   - Roles should now include ROLE_ADMIN

### Test Change Password
1. **Click "Change Password"** button next to updated@example.com
2. **Verify Password Change**
   - Should see success flash message: "Password changed for updated@example.com. New password: {24-char password}"
   - Copy the new password

3. **Test New Password**
   - Logout as admin
   - Login with updated@example.com and new password
   - Should successfully log in

### Test Toggle Active Status
1. **Login as admin**
2. **Click "Disable"** button next to updated@example.com
3. **Verify Disable**
   - Should see flash message: "User updated@example.com disabled"
   - Status badge should change to "Disabled" (red)

4. **Test Disabled Login**
   - Logout as admin
   - Try to login with updated@example.com
   - Login should fail (Symfony security denies disabled users)

5. **Re-enable User**
   - Login as admin
   - Click "Enable" button next to updated@example.com
   - Should see flash message: "User updated@example.com enabled"
   - Status badge should change to "Active" (green)

### Test Self-Protection Logic
1. **Identify Current Admin**
   - Note which email you're logged in as (e.g., admin@example.com)

2. **Try to Disable Own Account**
   - Click "Disable" button next to your own email
   - Should see error flash message: "Cannot disable your own account"
   - Status should remain "Active"

3. **Try to Remove Own Admin Role**
   - Click "Edit" button next to your own email
   - Uncheck "Is Admin" checkbox
   - Click "Save"
   - Should see error flash message: "Cannot remove your own admin role"
   - Roles should still include ROLE_ADMIN

4. **Verify UI Protections**
   - "Disable" and "Remove Admin" buttons should be disabled/hidden for current user
   - Or show warning on click

### Test Item Count Display
1. **Create Test User with Items**
   ```bash
   # Create user via console
   docker compose exec php php bin/console app:create-user items@example.com Test Items --password test123
   ```

2. **Import Inventory for Test User**
   - Login as items@example.com
   - Import Steam inventory (upload JSON)
   - Note the number of items imported

3. **Create Storage Box and Add Items**
   - Create a storage box
   - Deposit some items into the box

4. **Verify Item Count**
   - Logout and login as admin
   - Navigate to User Management
   - Find items@example.com in table
   - Item count should show total of all items (main inventory + storage boxes)

### Test Edge Cases
1. **Duplicate Email**
   - Try to create user with email that already exists
   - Should see validation error: "User with email {email} already exists"

2. **Invalid Email Format**
   - Try to create user with invalid email: `notanemail`
   - Should see validation error: "Invalid email format"

3. **Empty Required Fields**
   - Try to submit create form with empty email/first name/last name
   - Should see validation errors for required fields

4. **User with No Login**
   - Check a newly created user who has never logged in
   - Last Login column should show "Never"

5. **User with No Items**
   - Check a user who has not imported inventory
   - Items column should show "0"

### Test Responsive Design
1. **Open browser developer tools**
2. **Toggle device toolbar** (mobile/tablet view)
3. **Navigate through user management page**
4. **Verify:**
   - Table is scrollable or stacks on small screens
   - Forms are usable on small screens
   - Buttons are touch-friendly
   - Flash messages are readable

### Test Role Badges
1. **Verify Role Badge Display**
   - Users with only ROLE_USER: Show blue "USER" badge
   - Users with ROLE_ADMIN: Show red "ADMIN" badge (may also show blue "USER" badge)
   - Badges should be styled consistently with project

### Test Generated Password Format
1. **Create multiple new users**
2. **Verify each generated password:**
   - Length: Exactly 24 characters
   - Characters: Only alphanumeric (a-z, A-Z, 0-9)
   - Uniqueness: Each password should be different
   - Randomness: Should not follow predictable patterns

## Notes & Considerations

**Security:**
- Generated passwords use `random_bytes()` for cryptographic security
- Passwords are hashed using Symfony's 'auto' algorithm (bcrypt/argon2)
- CSRF protection on all forms
- Self-protection prevents admin lockout
- Disabled users cannot log in (Symfony security handles this automatically)

**Password Generation:**
- Use `bin2hex(random_bytes(12))` for 24-char hex string (0-9, a-f)
- Or use custom function with `random_bytes()` and alphanumeric charset (0-9, a-z, A-Z)
- Example:
  ```php
  private function generateRandomPassword(): string
  {
      $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
      $password = '';
      $max = strlen($chars) - 1;
      for ($i = 0; $i < 24; $i++) {
          $password .= $chars[random_int(0, $max)];
      }
      return $password;
  }
  ```

**Item Count Query:**
- Uses single query with COUNT and GROUP BY for efficiency
- Counts all ItemUser records for each user
- Includes items in main inventory (storageBox = null) and storage boxes (storageBox != null)
- No need for separate queries per user

**Last Login Tracking:**
- Already implemented via UserLoginListener (src/EventListener/UserLoginListener.php)
- Updates `lastLoginAt` on each successful login
- No additional work needed for this feature

**UI/UX:**
- Flash messages should be prominent and include full generated password
- Admin should copy password immediately (not stored anywhere after page refresh)
- Consider adding "Copy to Clipboard" button for generated passwords (future enhancement)
- Self-protection messages should be clear and explain why action was blocked

**Testing:**
- No automated tests in this project
- Manual verification steps cover all functionality
- Focus on testing self-protection logic thoroughly
- Test with multiple users to verify item count accuracy

**Consistency with Discord Admin:**
- Follow same layout patterns as Discord admin page (Task 59)
- Use same Tailwind CSS classes and component styles
- Use same flash message styles
- Keep admin panel navigation consistent

## Related Tasks

- Task 59: Admin panel with Discord management - Provides admin panel homepage and navigation pattern (completed)
- Task 47: Admin settings unified panel - Another admin feature (can be done in parallel)
