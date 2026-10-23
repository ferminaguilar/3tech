# AGENTS.md

Guidance for AI coding agents working in this repository. For end-user
documentation see [README.md](README.md).

## Project

`drupal/wa` — "Web Authentication". A Drupal contrib module that adds
passwordless WebAuthn / FIDO2 passkey authentication (Touch ID, Face ID,
YubiKey, Windows Hello, password managers, etc.) to Drupal's user login.

- Module machine name: `wa`
- Composer package: `drupal/wa`
- Default branch: `2.0.x` (PRs target this; `1.0.x` is the legacy
  release branch and only takes security backports)
- License: GPL-2.0-or-later

Ships with one submodule:

- `wa_email_otp` — step-up email OTP after passkey verification, built on the
  `hook_wa_login` hook the parent module exposes.

## Stack & requirements

- PHP `>= 8.2` (CI targets `8.3`; `phpstan` is run on 8.3).
- Drupal core `^10.5 || ^11.2`.
- Library `web-auth/webauthn-lib ^5.3.1` (pinned minimum — do not downgrade).
- Hard runtime requirement: HTTPS (WebAuthn refuses to run on insecure
  origins, except `localhost`).
- CI runs through the standard Drupal GitLab CI templates
  ([.gitlab-ci.yml](.gitlab-ci.yml)) with `OPT_IN_TEST_NEXT_MINOR=1` and
  `OPT_IN_TEST_PREVIOUS_MAJOR=1`. Frontend ESLint is disabled
  (`SKIP_ESLINT=1`).

## Layout

```
wa.info.yml / wa.module / wa.install / wa.routing.yml / wa.services.yml
wa.libraries.yml / wa.links.{menu,task}.yml / wa.permissions.yml
wa.api.php                              # documents hook_wa_login
config/install/                         # default config + bundled view
config/schema/                          # config + views schema
js/passkey.js                           # WebAuthn client (uses jQuery)
src/
  Access/PasskeyManageAccessCheck.php   # _wa_passkey_manage_access route req
  Controller/PasskeyController.php      # all /wa/* JSON endpoints + UI
  Form/PasskeySettingsForm.php          # /admin/config/people/wa
  Plugin/views/{field,filter}/          # PasskeyProvider plugins
  Service/
    WebAuthnService.php                 # webauthn-lib wrapper
    SafeLoginFinalizer.php              # hardened user_login_finalize()
    PasskeyLabeler.php                  # AAGUID -> human label, allow-list
    PasskeyTicketService.php            # logAndReturnError + ticket IDs
tests/src/{Functional,Unit}/            # BrowserTestBase + PHPUnit
modules/wa_email_otp/                   # submodule (own services/routes/db)
local_test/                             # adversarial scripts; gitignored
                                         # and excluded from phpstan
```
## Integration with web-auth/webauthn-lib

The module wraps `web-auth/webauthn-lib` inside [WebAuthnService.php](src/Service/WebAuthnService.php). It uses the library's spec-compliant objects and validators for WebAuthn ceremonies:

- **Setup & Config:** Instantiates a `CeremonyStepManagerFactory` for cryptographic checks, configured with a `NoneAttestationStatementSupport` parser and `AttestationObjectLoader` in the constructor.
- **Registration Options:** Generates a secure random challenge stored in the session, configuring dynamic parameters (resident keys, user verification) and formatting them as standard options arrays for frontend consumption.
- **Registration Verification:** Uses `CollectedClientData::create()`, `AttestationObjectLoader::load()`, and `AuthenticatorAttestationResponse::create()`. The signature, origin, and challenge are validated using `AuthenticatorAttestationResponseValidator::check()`. Upon success, a `CredentialRecord` is returned.
- **Login Verification:** Reconstructs a `CredentialRecord` from database details, builds request options, loads assertion components using `AuthenticatorDataLoader::load()`, and validates the assertion using `AuthenticatorAssertionResponseValidator::check()`. The signature and sign counter checks are handled automatically by the validator.

## Routes (the API surface — learn these first)

All under [wa.routing.yml](wa.routing.yml):

| Route | Path | Method | Notes |
| --- | --- | --- | --- |
| `wa.settings` | `/user/{user}/passkeys` | GET | UI; uses `_wa_passkey_manage_access` |
| `wa.passkey_delete` | `/wa/passkey/delete` | POST | CSRF token required |
| `wa.register_options` | `/wa/register/options` | POST | CSRF; flood-controlled |
| `wa.register_verify` | `/wa/register/verify` | POST | CSRF; flood-controlled |
| `wa.login_options` | `/wa/login/options` | POST | XHR + Origin/Referer check; flood |
| `wa.login_verify` | `/wa/login/verify` | POST | XHR + Origin/Referer check; flood |
| `wa.admin_settings` | `/admin/config/people/wa` | GET | `administer all user passkey` |

All return `JsonResponse`. Errors go through
`PasskeyTicketService::logAndReturnError()` so the user sees a generic
message + ticket id, while the real cause goes to the `wa` log channel.

Submodule adds `/wa_otp/{uid}/{hash}` and `/admin/config/people/wa/email-otp`.

## Database

- `wa` table — created in [wa.install](wa.install) `wa_schema()`. Stores
  one row per credential: `credential_id` (raw binary), `public_key`
  (base64), `credential_hash` (sha256 of credential id, unique),
  `user_handle`, `aaguid`, `sign_counter`, `transports`, timestamps.
- `wa_update_10001` normalized legacy base64/base64url credential ids to
  raw binary. New code must always read/write raw binary for
  `credential_id` and recompute `credential_hash` when it changes.
- Submodule owns `wa_email_otp` (PK = `hash`, OTP stored as
  `password_hash()`).

## Security model — read before changing controllers

These invariants are load-bearing. Don't relax them without a very good
reason and a corresponding test.

- **CSRF**: every mutating endpoint validates `X-CSRF-Token` against its
  own internal route path (e.g. `wa/register/options`).
  Tokens are minted in `PasskeyController::managePasskeys()` and the form
  alter, then exposed via `drupalSettings.wa`.
- **Origin/Referer**: `loginOptions` and `loginVerify` additionally
  enforce `X-Requested-With: XMLHttpRequest` and a host match on
  `Origin` (falling back to `Referer`).
- **Flood control** uses Drupal's flood service keyed on **client IP**
  for the public endpoints. If you change keying, document reverse-proxy
  implications — the module relies on `getClientIp()` honouring
  `reverse_proxy` settings (see README troubleshooting).
- **Challenge TTL**: 120 seconds. Stored in the session under
  `wa_challenge` and removed in a `finally` block. One-time use.
- **Session fixation**: `loginVerify` calls `$session->migrate(TRUE)`
  before `user_login_finalize()`.
- **AAGUID allow-list**: enforced both at registration and at login.
  Empty list = deny all (intentional — see `userHasAllowedRole()`).
- **Role allow-list**: same default-deny semantics. The "authenticated"
  role acts as "any authenticated user".
- **Open-redirect protection**: `SafeLoginFinalizer` is the single
  entry point that calls `user_login_finalize()`. It strips
  `?destination`, installs an `ob_start` callback that intercepts
  rogue `Location:` headers from `hook_user_login` implementations,
  and validates every redirect candidate through `Url::fromUserInput()`
  (which rejects external URLs). `EnforcedResponseException` is also
  caught in `loginVerify` and run through the same validation. Any new
  redirect path must reuse this service — do not call
  `user_login_finalize()` directly.
- **User-handle hardening**: when the client sends a `userHandle`, it
  must decode to a positive integer Drupal UID; non-numeric values are
  rejected (would otherwise silently coerce to 0 in the DB layer).
- **Views access**: view-level access config is treated as untrusted — a
  misconfigured or custom view must never expose credential data. Three
  code-level layers enforce `administer all user passkey`:
  `Drupal\wa\Routing\RouteSubscriber` forces the permission onto every
  Views route over the `wa` base table (the whole page 403s, so exposed
  filters and their user autocomplete never render); every Views query on
  the table is tagged `wa_access` (see `wa_views_data()`) and
  `wa_query_wa_access_alter()` returns zero rows without the permission;
  and `wa_views_pre_view()` strips exposed filters on displays without a
  route (blocks, embeds). Covered by `PasskeyViewsAccessTest`.
- **Logging**: credential ids are masked to `****` + last 6 chars when
  logged. Keep it that way.
- The `local_test/` directory contains adversarial probes against these
  endpoints. It is gitignored and excluded from PHPStan; treat it as a
  scratch area.

## hook_wa_login

Documented in [wa.api.php](wa.api.php). Implementers return `array[]`
where each element has `allowed_login` (bool) and `redirect_url`
(`string|\Drupal\Core\Url|null`). Any `allowed_login === FALSE` short-
circuits login. `redirect_url` is **always** revalidated through
`Url::fromUserInput()` and must be a local path. The bundled
`wa_email_otp` submodule is the canonical example.

## Coding standards

Follow Drupal core conventions; the file headers, doc comments, and
`{@inheritdoc}` style throughout the codebase are the model. Notable
project-specific points:

- PHPStan **level 6** ([phpstan.neon](phpstan.neon)). Do not silence
  errors by adding new ignore rules without justification — the
  existing ones cover Drupal-isms (`new static()`, `iterableValue`).
- Services are wired in YAML (`*.services.yml`) using arguments by
  name, not autowiring. Add new services there.
- Controllers extend `ControllerBase` and use the explicit
  `__construct` + `create(ContainerInterface)` pattern (no
  `AutowireTrait`). Match the existing style in `PasskeyController`.
- Use `$this->ticketService->logAndReturnError(...)` for any error
  surface that returns to the client. Never echo raw exception
  messages to users.
- Translatable strings via `t()` / `$this->t()`; user-facing strings
  must be translatable. Tests in
  [tests/src/Functional/PasskeyMultilingualTest.php](tests/src/Functional/PasskeyMultilingualTest.php)
  exercise this.
- `cspell` lives at [.cspell-project-words.txt](.cspell-project-words.txt);
  add new domain words there if a spell-check job complains.

## Tests

- Location: [tests/src/Functional](tests/src/Functional) (Drupal
  `BrowserTestBase`) and [tests/src/Unit](tests/src/Unit) (PHPUnit).
- Group: `@group wa` is used throughout — preserve it on new tests so
  `phpunit --group=wa` still works.
- Default theme in functional tests is `stark`.
- Functional tests typically enable the module, set
  `enable_passkey_login=TRUE`, and add `RoleInterface::AUTHENTICATED_ID`
  to `allowed_roles` in `setUp()`. Mirror that pattern.
- WebAuthn cryptographic flows are not exercised end-to-end — there is
  no real authenticator in CI. Tests focus on access control, CSRF,
  flood control, redirect safety, AAGUID enforcement, and the user-
  handle policy. Add new tests in the same vein; do not try to mock
  `web-auth/webauthn-lib` ceremonies.
- The `local_test/` PHP scripts are manual reproduction harnesses and
  are not run by CI.

Run locally (from a Drupal site that has this module installed):

```bash
# Functional + unit tests
vendor/bin/phpunit -c core --group wa modules/contrib/wa

# Static analysis (run from the module root)
vendor/bin/phpstan analyze
```

## Configuration

Defaults in [config/install/wa.settings.yml](config/install/wa.settings.yml).
Schema in [config/schema/wa.schema.yml](config/schema/wa.schema.yml). When
you add a new setting:

1. Add it to the schema file.
2. Add a default to the install YAML (intentional default-deny for
   anything security-relevant — see `enable_passkey_login: false`,
   `allowed_roles: []`).
3. Expose it in `PasskeySettingsForm` and persist it in `submitForm()`.
4. Add cache-tag invalidation if a render cache depends on it
   (`config:wa.settings` is already attached to the user login form).

## Things to leave alone unless asked

- `local_test/` and `local_test.zip` — adversarial probes / manual
  reproductions; not part of the module distribution.
- Composer `minimum-stability: dev` — required because of the upstream
  webauthn-lib release cadence.

## When in doubt

- Endpoint behavior → [src/Controller/PasskeyController.php](src/Controller/PasskeyController.php).
- Cryptographic boundary → [src/Service/WebAuthnService.php](src/Service/WebAuthnService.php).
- Login finalization / redirect safety → [src/Service/SafeLoginFinalizer.php](src/Service/SafeLoginFinalizer.php).
- AAGUID allow-list and labels → [src/Service/PasskeyLabeler.php](src/Service/PasskeyLabeler.php).
- Manage-page access policy → [src/Access/PasskeyManageAccessCheck.php](src/Access/PasskeyManageAccessCheck.php).
- The `hook_wa_login` contract → [wa.api.php](wa.api.php) and the
  submodule under [modules/wa_email_otp](modules/wa_email_otp).
