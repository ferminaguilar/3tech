<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests user handle enforcement during Passkey login.
 *
 * @group wa
 */
class PasskeyUserHandleTest extends BrowserTestBase {

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
   * Tests login with and without user handle enforcement.
   */
  public function testUserHandleEnforcement(): void {
    $user = $this->drupalCreateUser([]);
    $credentialId = 'test-cred-id';
    $encodedId = base64_encode($credentialId);

    // Insert a dummy credential.
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

    // Get login token.
    $this->drupalGet('/user/login');
    $settings = $this->getDrupalSettings();
    $loginToken = $settings['wa']['loginOptionsToken'] ?? '';

    // Set challenge.
    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    // Case 1: Default behavior (Enforcement Disabled).
    // Missing userHandle should NOT fail immediately.
    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedId,
      'response' => [
        'clientDataJSON' => 'garbage',
        // 'userHandle' is missing.
      ],
    ], [], [
      'X-CSRF-Token' => $loginToken,
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);

    $this->assertSession()->statusCodeEquals(400);
    // Should be generic failure, not policy failure.
    $this->assertSession()->responseNotContains('User handle is required by policy.');

    // Case 2: Enable Enforcement.
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('enforce_user_handle', TRUE)
      ->save();

    // Reset challenge.
    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    // Missing userHandle should now fail with specific message.
    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedId,
      'response' => [
        'clientDataJSON' => 'garbage',
        // 'userHandle' is missing.
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
   * Tests that empty allowed_roles blocks all passkey logins.
   */
  public function testEmptyAllowedRolesBlocksLogin(): void {
    $user = $this->drupalCreateUser([]);
    $credentialId = 'test-cred-id-2';
    $encodedId = base64_encode($credentialId);

    // Insert a dummy credential.
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

    // Set allowed_roles to empty array.
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [])
      ->save();

    // Get login token.
    $this->drupalGet('/user/login');
    $settings = $this->getDrupalSettings();
    $loginToken = $settings['wa']['loginOptionsToken'] ?? '';

    // Set challenge.
    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $this->baseUrl,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    // Attempt login - should fail because no roles are configured.
    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedId,
      'response' => [
        'clientDataJSON' => base64_encode(json_encode([
          'type' => 'webauthn.get',
          'challenge' => 'test-challenge',
          'origin' => $this->baseUrl,
        ])),
        'authenticatorData' => base64_encode('test-auth-data'),
        'signature' => base64_encode('test-signature'),
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
   * Performs a JSON POST request.
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

}
