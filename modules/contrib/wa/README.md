# WebAuthn (Passkey) Module

## AI Development Declaration
This module was written by AI agents. The module maintainer reviews and takes full responsibility for all the work done by AI.

## About the Module
The WebAuthn (passkey) module enables passwordless authentication for Drupal using Passkeys (FIDO2/WebAuthn). It allows users to register authenticators (such as Touch ID, Face ID, YubiKeys, or Windows Hello) and log in securely without entering a password.

The module leverages the robust `web-auth/webauthn-lib` library to handle cryptographic operations, ensuring a secure and standard-compliant implementation.

## Features
-   **Passwordless Login:** Users can log in using only their passkey.
-   **Secure Registration:** Supports registration of multiple authenticators per user.
-   **Configuration Control:**
    -   **Global Toggle:** Administrators can enable or disable passkey login globally.
    -   **Role Restrictions:** Restrict passkey usage to specific user roles.
-   **Management Interface:** Users can view and delete their registered passkeys via a dedicated "Manage Passkeys" tab on their profile.
-   **Email Notifications:** Automatically sends email notifications to users when a new passkey is added to their account, providing an additional security layer by alerting them of any authentication changes.
-   **Service Ticket System:** Generates unique ticket IDs for errors during passkey operations, allowing for easier troubleshooting and support.
-   **Security:**
    -   **Flood Control:** Protects against brute-force attacks on registration and login endpoints.
    -   **Origin Validation:** Strictly validates the origin of authentication requests.
    -   **CSRF Protection:** Secures sensitive management actions.
    -   **AAGUID Validation:** (Optional) Restrict authenticators to specific models.

## Requirements
-   **Drupal:** ^11.2
-   **PHP:** ^8.3
-   **Library:** `web-auth/webauthn-lib` ^5.2
-   **HTTPS:** WebAuthn **requires** a secure context (HTTPS) to function. It will not work over HTTP (except for `localhost`).

## Installation
Install the module via Composer to ensure all dependencies are downloaded:

```bash
composer require drupal/wa
drush en wa
```

## Configuration

### 1. General Settings
Navigate to **Configuration > People > Passkey Settings** (`/admin/config/people/wa`).

-   **Enable Passkey Login:** Toggle this off to disable the "Sign in with Passkey" button and block all passkey API endpoints.
-   **Allowed Roles:** Select which user roles are permitted to use passkeys. If no roles are selected, all authenticated users can use passkeys.
-   **Allowed Authenticators:** Select which authenticator types (by AAGUID) are permitted. Common options include YubiKey, Touch ID, Windows Hello, and Generic/Software Authenticators. If no authenticators are selected, no types are allowed (deny-all default).
-   **Additional AAGUIDs:** To add a specific authenticator not in the default list, enter its AAGUID (one per line) in lowercase UUID format (e.g., `00000000-0000-0000-0000-000000000000`). You can find AAGUIDs in the Drupal logs after a registration attempt or by consulting the [FIDO Alliance Metadata Service](https://fidoalliance.org/metadata/).
-   **User Verification:** Choose whether user verification (PIN, biometric) is "Preferred" (default) or "Required" for login.
-   **Resident Key Requirement:** Controls whether the authenticator must create a client-side discoverable credential (resident key). Options include:
    -   **Discouraged:** Server stores the key handle; saves storage space on hardware keys but requires entering a username first.
    -   **Preferred (default):** Attempts to create a resident key when possible.
    -   **Required:** Forces resident key creation; necessary for the "Sign in with Passkey" button (usernameless login) to work.
-   **Enforce User Handle Validation:** When enabled, login will fail if the authenticator doesn't provide a user handle. Disable this if you encounter compatibility issues with older devices.
-   **Send Email Notification on Passkey Addition:** When enabled, users will receive an email notification whenever a new passkey is added to their account. This provides an additional security layer by alerting users of authentication changes. The email template can be customized at **Configuration > People > Account settings** (`/admin/config/people/accounts`).

### 2. Permissions
Configure permissions at `/admin/people/permissions`:


-   `administer all user passkey`: Allows administrators to manage (delete) passkeys for other users.

### 3. Passkey Overview
Administrators can view a list of all registered passkeys on the site by navigating to **Administration > People > Passkeys** (`/admin/people/passkeys`). This view provides details such as:
-   **User:** The owner of the passkey.
-   **Provider:** The authenticator type/provider (e.g., Touch ID, YubiKey).
-   **Created:** The date and time of registration.
-   **Last used:** The last time the passkey was used for authentication.

### 4. User Passkey Management
Users can manage their own passkeys via their user profile:
1.  Go to **My account** (`/user`).
2.  Click on the **Passkeys** tab.
3.  Here, users can:
    -   **Add Passkey:** Register a new device (Touch ID, YubiKey, etc.).
    -   **Delete:** Remove an existing passkey.

## Troubleshooting

### "Sign in with Passkey" button is missing
-   Check if the module is enabled.
-   Check if **Enable Passkey Login** is checked in the configuration.
-   Ensure you are on the default user login form.

### Rate limiting blocks requests unexpectedly
-   Flood control in this module keys on client IP for both options and verify endpoints. If you're behind a load balancer or proxy, ensure Drupal is configured for reverse proxy handling so `getClientIp()` resolves the real client IP: set `reverse_proxy` to `TRUE` and list proxy addresses/ranges in `reverse_proxy_addresses` (and trusted headers) in `settings.php`. See Drupal's guidance for reverse proxies: https://www.drupal.org/docs/getting-started/installing-drupal/using-a-load-balancer-or-reverse-proxy

### Registration/Login fails immediately
-   **HTTPS:** Ensure you are accessing the site over HTTPS. Browsers block WebAuthn API on insecure origins.
-   **Console Errors:** Check the browser's developer console for JavaScript errors.
-   **Drupal Logs:** Check **Reports > Recent log messages** for server-side errors (e.g., "Invalid Origin", "Flood control").

### "The operation either timed out or was not allowed"
-   This is a browser-level error. It often happens if the user cancels the dialog or if the device doesn't support the requested authenticator type.

### "This authenticator is not allowed for passkey login."
-   This error appears when adding a new passkey, and there is an error "Passkey registration blocked for disallowed AAGUID" in the Drupal logs if you have configured **Allowed authenticators** in the settings and the user is trying to use a restricted device.
-   **Solution:** Go to `/admin/config/people/wa` and ensure the authenticator type (AAGUID) is checked.
-   **Firefox/Chrome/iCloud:** If using a software authenticator (like Touch ID on Mac via Chrome/Firefox), ensure **"Generic / Software Authenticator"** is checked.

### Clock Synchronization
-   WebAuthn relies on time-sensitive challenges. Ensure your server's clock is synchronized (NTP).

### Login fails with "User handle is required by policy"
-   This error occurs if **Enforce User Handle Validation** is enabled in the configuration, but the user's authenticator (or browser) did not send the `userHandle` in the assertion response.
-   **Cause:** Some older authenticators or specific browser/OS combinations (e.g., some Android devices or older Chrome versions) may not return the user handle during login.
-   **Solution:** If your users encounter this, disable **Enforce User Handle Validation** in `/admin/config/people/wa`. This relaxes the check while maintaining standard security.

## Known Issues

### Interaction with other login modules (Redirects)
This module uses an AJAX-based login flow. Modules that inject redirects during `hook_user_login` (e.g., `login_destination`, `user_redirect`, `r4032login`) are fully supported, with one important restriction:

-   **Internal redirects work normally.** If another module sets a redirect to an internal path (e.g., `/admin/dashboard`, `/user/1/edit`), this module captures the redirect URL and navigates the browser to it after login completes.
-   **External redirects are blocked by design.** Redirects to external URLs (e.g., `https://example.com`) are rejected to prevent open redirect attacks. This is a deliberate security measure. If an external redirect is detected, the user is redirected to their profile page instead, and a warning is logged.
-   **How it works:** The module intercepts redirects from three possible sources: the `?destination` query parameter, `Location` headers set directly by modules during `hook_user_login`, and `EnforcedResponseException` responses. All redirect URLs are validated through `Url::fromUserInput()`, which only accepts paths starting with `/`, `?`, or `#`. Additionally, `LocalRedirectResponse` provides a secondary safety check.
-   **If your redirect is not working:** Check the Drupal log for warnings containing "Redirect URL rejected" — this indicates the target URL failed validation. Ensure the redirect target is an internal Drupal path, not an external URL.

## 2.0 New features

### New Hook: `hook_wa_login`

A new hook `hook_wa_login` has been introduced to allow other modules to intervene during the passkey login process. This hook is invoked after the passkey has been successfully verified but before the user is fully logged in.

**Usage:**

Modules can implement this hook to:
-   Perform additional security checks (e.g., check IP allowlists, time of day).
-   Prevent login by setting `allowed_login` to `FALSE`.
-   Redirect the user to a secondary verification page (e.g., OTP, TFA) by providing a `redirect_url`.

**Example:**

```php
function mymodule_wa_login(\Drupal\user\UserInterface $user) {
  // Check strict IP policy.
  if (!in_array(\Drupal::request()->getClientIp(), ['127.0.0.1'])) {
    return [[
      'allowed_login' => FALSE,
      'redirect_url' => Url::fromRoute('system.403')->toString(),
    ]];
  }
}
```

### New Sub-module: WA Email OTP (`wa_email_otp`)

The `wa` module now ships with a submodule called `wa_email_otp`. This module demonstrates the power of the new `hook_wa_login` by implementing an Email One-Time Password (OTP) verification step after passkey authentication.

**Features:**
-   **Step-up Authentication:** Adds a second layer of security by requiring an email OTP after the passkey check.
-   **Session Binding:** securely binds the OTP session to the authenticated user to prevent replay attacks or link sharing.
-   **Configurable Email:** Administrators can customize the OTP email subject and body via the settings form.
-   **Flood Control:** Built-in flood control to prevent brute-force attacks on the OTP entry form.
