<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests security aspects of WebAuthn login and registration.
 *
 * @group wa
 */
class PasskeySecurityTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['wa'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [RoleInterface::AUTHENTICATED_ID])
      ->save();
  }

  /**
   * Tests unauthorized registration attempts.
   */
  public function testUnauthorizedRegistration(): void {
    // Create a user without the allowed role.
    $noRoleUser = $this->drupalCreateUser([]);
    $this->drupalLogin($noRoleUser);

    // Restrict passkeys to a specific role.
    $config = \Drupal::configFactory()->getEditable('wa.settings');
    $config->set('allowed_roles', ['administrator'])->save();

    $token = \Drupal::service('csrf_token')->get('wa/register/options');
    $verifyToken = \Drupal::service('csrf_token')->get('wa/register/verify');

    // Attempt to access registration options.
    $this->drupalPostJson('/wa/register/options', [], [], ['X-CSRF-Token' => $token]);
    $this->assertSession()->statusCodeEquals(403);

    // Attempt to submit registration verification.
    $this->drupalPostJson('/wa/register/verify', [], [], ['X-CSRF-Token' => $verifyToken]);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests registration with missing or invalid challenge.
   */
  public function testRegistrationChallengeValidation(): void {
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);

    // Get token from the management page.
    $this->drupalGet('/user/' . $user->id() . '/passkeys');
    $settings = $this->getDrupalSettings();

    $registerOptionsToken = $settings['wa']['registerOptionsToken'] ?? '';
    $registerVerifyToken = $settings['wa']['registerVerifyToken'] ?? $registerOptionsToken;

    // Case 1: Missing challenge in session.
    // We send a request without first calling
    // (which sets the challenge).
    $this->drupalPostJson('/wa/register/verify', ['some' => 'data'], [], ['X-CSRF-Token' => $registerVerifyToken]);
    // The controller throws an exception
    // "No challenge found in session", caught and returns 400.
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSession()->responseContains('Passkey registration failed.');

    // Case 2: Invalid challenge.
    $this->drupalPostJson('/wa/register/options', [], [], ['X-CSRF-Token' => $registerOptionsToken]);

    $this->assertSession()->statusCodeEquals(200);

    // Now send garbage data.
    // The WebAuthn library will fail to validate it against the challenge.
    $this->drupalPostJson('/wa/register/verify', [
      'id' => 'garbage',
      'rawId' => 'garbage',
      'response' => ['clientDataJSON' => 'garbage'],
    ], [], ['X-CSRF-Token' => $registerVerifyToken]);
    $this->assertSession()->statusCodeEquals(400);
  }

  /**
   * Tests login with missing or invalid challenge.
   */
  public function testLoginChallengeValidation(): void {
    $user = $this->drupalCreateUser([]);
    // Create a dummy credential in DB to pass the initial lookup.
    $credentialId = 'test-cred-id';
    $encodedId = base64_encode($credentialId);

    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $user->id(),
        'credential_id' => $credentialId,
        'credential_hash' => hash('sha256', $credentialId),
        'public_key' => base64_encode('publickey'),
        'user_handle' => 'handle',
        'sign_counter' => 0,
        'transports' => json_encode([]),
        'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        'created' => \Drupal::time()->getRequestTime(),
        'last_used' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    // Get token from login page.
    if (\Drupal::currentUser()->isAuthenticated()) {
      $this->drupalLogout();
    }
    $this->drupalGet('/user/login');
    $settings = $this->getDrupalSettings();
    $loginToken = $settings['wa']['loginOptionsToken'] ?? '';

    // Case 1: Missing challenge.
    // Login options now requires Origin header and X-Requested-With header.
    // We simulate a valid options request first to set the challenge.
    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    // Test "Missing challenge",.
    $this->getSession()->restart();
    $this->drupalPostJson('/wa/login/verify', ['id' => $encodedId], [], [
      'X-CSRF-Token' => $loginToken,
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);
    if ($this->getSession()->getStatusCode() !== 400) {
      dump($this->getSession()->getPage()->getContent());
    }
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSession()->responseContains('Passkey login failed.');

    // Case 2: Challenge exists but verification fails (garbage data).
    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);
    // Ensure this succeeds.
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedId,
      'rawId' => $encodedId,
      'response' => ['clientDataJSON' => 'garbage'],
    ], [], [
      'X-CSRF-Token' => $loginToken,
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);
    $this->assertSession()->statusCodeEquals(400);
  }

  /**
   * Tests login attempts with non-existent credentials.
   */
  public function testNonExistentCredential(): void {
    $this->drupalGet('/user/login');
    $settings = $this->getDrupalSettings();
    $loginToken = $settings['wa']['loginOptionsToken'] ?? '';

    // Use Origin header instead of CSRF token.
    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    // Ensure challenge is set.
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalPostJson('/wa/login/verify', ['id' => base64_encode('non-existent')], [], [
      'X-CSRF-Token' => $loginToken,
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);
    $this->assertSession()->statusCodeEquals(400);
    // Should fail with a generic message.
    $this->assertSession()->responseContains('Passkey login failed.');
  }

  /**
   * Tests login attempts by unauthorized users.
   */
  public function testUnauthorizedUserLogin(): void {
    // Restrict passkeys to a specific role.
    $config = \Drupal::configFactory()->getEditable('wa.settings');
    $config->set('allowed_roles', ['administrator'])->save();

    // User without the allowed role.
    $user = $this->drupalCreateUser([]);
    $credentialId = 'test-cred-no-perm';
    $encodedId = base64_encode($credentialId);

    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $user->id(),
        'credential_id' => $credentialId,
        'credential_hash' => hash('sha256', $credentialId),
        'public_key' => base64_encode('publickey'),
        'user_handle' => 'handle',
        'sign_counter' => 0,
        'transports' => json_encode([]),
        'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        'created' => \Drupal::time()->getRequestTime(),
        'last_used' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);
    $this->drupalPostJson('/wa/login/verify', ['id' => $encodedId], [], [
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);

    // Should fail because user lacks role.
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSession()->responseContains('Passkey login failed.');

    // Blocked users cannot log in even with valid credentials.
    // Reset allowed roles so this user can try.
    $config->set('allowed_roles', [])->save();
    $blockedUser = $this->drupalCreateUser([]);
    $blockedUser->block();
    $blockedUser->save();
    $blockedCredentialId = 'blocked-cred-id';
    $blockedEncodedId = base64_encode($blockedCredentialId);

    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $blockedUser->id(),
        'credential_id' => $blockedCredentialId,
        'credential_hash' => hash('sha256', $blockedCredentialId),
        'public_key' => base64_encode('publickey'),
        'user_handle' => 'handle',
        'sign_counter' => 0,
        'transports' => json_encode([]),
        'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        'created' => \Drupal::time()->getRequestTime(),
        'last_used' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);
    $this->drupalPostJson('/wa/login/verify', ['id' => $blockedEncodedId], [], [
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSession()->responseContains('Passkey login failed.');
  }

  /**
   * Tests that malformed public key data is rejected.
   *
   * This test verifies the fix for the critical security issue where
   * malformed public key data could bypass validation.
   */
  public function testMalformedPublicKeyRejection(): void {
    $user = $this->drupalCreateUser([]);

    // Test Case 1: Non-base64 public key data.
    $credentialId1 = 'test-cred-malformed-1';
    $encodedId1 = base64_encode($credentialId1);

    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $user->id(),
        'credential_id' => $credentialId1,
        'credential_hash' => hash('sha256', $credentialId1),
        // Invalid base64.
        'public_key' => 'this-is-not-valid-base64!!!',
        'user_handle' => (string) $user->id(),
        'sign_counter' => 0,
        'transports' => json_encode([]),
        'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        'created' => \Drupal::time()->getRequestTime(),
        'last_used' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    $this->drupalGet('/user/login');
    $settings = $this->getDrupalSettings();
    $loginToken = $settings['wa']['loginOptionsToken'] ?? '';

    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    // Attempt login with malformed public key - should fail.
    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedId1,
      'response' => [
        'clientDataJSON' => base64_encode(json_encode([
          'type' => 'webauthn.get',
          'challenge' => 'test',
          'origin' => $this->baseUrl,
        ])),
        'authenticatorData' => base64_encode('test'),
        'signature' => base64_encode('test'),
        'userHandle' => base64_encode((string) $user->id()),
      ],
    ], [], [
      'X-CSRF-Token' => $loginToken,
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);

    $this->assertSession()->statusCodeEquals(400);
    $this->assertSession()->responseContains('Passkey login failed.');

    // Test Case 2: Empty public key after base64 decode.
    $credentialId2 = 'test-cred-malformed-2';
    $encodedId2 = base64_encode($credentialId2);

    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $user->id(),
        'credential_id' => $credentialId2,
        'credential_hash' => hash('sha256', $credentialId2),
        // Empty after decode.
        'public_key' => base64_encode(''),
        'user_handle' => (string) $user->id(),
        'sign_counter' => 0,
        'transports' => json_encode([]),
        'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        'created' => \Drupal::time()->getRequestTime(),
        'last_used' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedId2,
      'response' => [
        'clientDataJSON' => base64_encode(json_encode([
          'type' => 'webauthn.get',
          'challenge' => 'test',
          'origin' => $this->baseUrl,
        ])),
        'authenticatorData' => base64_encode('test'),
        'signature' => base64_encode('test'),
        'userHandle' => base64_encode((string) $user->id()),
      ],
    ], [], [
      'X-CSRF-Token' => $loginToken,
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);

    $this->assertSession()->statusCodeEquals(400);
    $this->assertSession()->responseContains('Passkey login failed.');
  }

  /**
   * Tests WebAuthnService::validateLoginRequest().
   */
  public function testStolenPublicKeyAttack(): void {
    $user = $this->drupalCreateUser([]);
    $credentialId = 'test-stolen-key-attack';
    $encodedId = base64_encode($credentialId);

    // 1. Simulate a compromised database: Insert a valid credential.
    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $user->id(),
        'credential_id' => $credentialId,
        'credential_hash' => hash('sha256', $credentialId),
        // A valid public key would normally go here.
        // For this test, any string works as long as it's base64 encoded,
        // because the signature validation will fail before the key is used
        // (or when the key is used to verify the invalid signature).
        'public_key' => base64_encode('publickey'),
        'user_handle' => (string) $user->id(),
        'sign_counter' => 0,
        'transports' => json_encode([]),
        'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        'created' => \Drupal::time()->getRequestTime(),
        'last_used' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    // 2. Get a valid challenge from the server.
    $this->drupalGet('/user/login');
    $settings = $this->getDrupalSettings();
    $loginToken = $settings['wa']['loginOptionsToken'] ?? '';

    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $challenge = $response['challenge'];

    // 3. Construct the malicious payload.
    // The attacker knows the credential ID and has the challenge.
    // But they CANNOT generate a valid signature.
    // Construct clientDataJSON (Base64Url encoded).
    $clientData = [
      'type' => 'webauthn.get',
      'challenge' => $challenge,
      'origin' => $this->baseUrl,
    ];
    $clientDataJson = rtrim(strtr(base64_encode(json_encode($clientData)), '+/', '-_'), '=');

    // Construct authenticatorData (Base64Url encoded).
    // Valid structure: 32 bytes RP ID hash + 1 byte flags + 4 bytes counter.
    $rpIdHash = hash('sha256', parse_url($this->baseUrl, PHP_URL_HOST), TRUE);
    $authenticatorData = rtrim(strtr(base64_encode(
      $rpIdHash .
        // Flags: User Present.
        chr(1) .
        // Counter: 0.
        pack('N', 0)
    ), '+/', '-_'), '=');

    // Construct Signature (Base64Url encoded).
    // We send a dummy signature in Raw format (64 bytes).
    // This passes format checks but fails cryptographic verification.
    $signature = rtrim(strtr(base64_encode(str_repeat("\1", 64)), '+/', '-_'), '=');

    // Encode user handle (Base64Url encoded).
    $userHandle = rtrim(strtr(base64_encode((string) $user->id()), '+/', '-_'), '=');

    // 4. Attempt to log in.
    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedId,
      'rawId' => $encodedId,
      'response' => [
        'clientDataJSON' => $clientDataJson,
        'authenticatorData' => $authenticatorData,
        'signature' => $signature,
        'userHandle' => $userHandle,
      ],
    ], [], [
      'X-CSRF-Token' => $loginToken,
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);

    // 5. Assert failure.
    // The server should reject the login because the signature is invalid.
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSession()->responseContains('Passkey login failed.');
  }

  /**
   * Tests WebAuthnService::validateLoginRequest() with raw input.
   */
  public function testRawInputFallback(): void {
    $user = $this->drupalCreateUser([]);
    $credentialId = 'test-cred-raw-input';
    $encodedId = base64_encode($credentialId);

    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $user->id(),
        'credential_id' => $credentialId,
        'credential_hash' => hash('sha256', $credentialId),
        'public_key' => base64_encode('publickey'),
        'user_handle' => (string) $user->id(),
        'sign_counter' => 0,
        'transports' => json_encode([]),
        'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        'created' => \Drupal::time()->getRequestTime(),
        'last_used' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    $this->drupalGet('/user/login');
    $settings = $this->getDrupalSettings();
    $loginToken = $settings['wa']['loginOptionsToken'] ?? '';

    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    // Send raw JSON string for clientDataJSON instead of Base64.
    // This simulates the client behavior that caused the regression.
    // The service should catch the decode error and use the raw value.
    $rawClientDataJSON = json_encode([
      'type' => 'webauthn.get',
      'challenge' => 'test',
      'origin' => $this->baseUrl,
    ]);

    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedId,
      'response' => [
        // Raw JSON string.
        'clientDataJSON' => $rawClientDataJSON,
        'authenticatorData' => base64_encode('test'),
        'signature' => base64_encode('test'),
        'userHandle' => base64_encode((string) $user->id()),
      ],
    ], [], [
      'X-CSRF-Token' => $loginToken,
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);

    // We expect a 400 because the signature validation will fail (dummy data),
    // BUT we should NOT get a 500 or "sodium_base642bin" error.
    // The response should contain "Passkey login failed." which comes from
    // the catch block in the controller, indicating the service didn't crash.
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSession()->responseContains('Passkey login failed.');
  }

  /**
   * Performs a JSON POST request.
   *
   * @param string $path
   *   The path to request.
   * @param array $data
   *   The JSON data to send.
   * @param array $options
   *   (optional) Options to be passed to buildUrl().
   * @param array $headers
   *   (optional) Headers to send with the request.
   */
  protected function drupalPostJson(string $path, array $data, array $options = [], array $headers = []): void {
    $content = json_encode($data);
    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $driver = $this->getSession()->getDriver();
    $driver->getClient()->request(
      'POST',
      $this->buildUrl($path, $options),
      [],
      [],
      $server,
      $content
    );
  }

  /**
   * Returns a CSRF token for the current session.
   */
  protected function getSessionCsrfToken(): string {
    $this->drupalGet('/session/token');
    return trim($this->getSession()->getPage()->getContent());
  }

}
