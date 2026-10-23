<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests access control for WA routes.
 *
 * @group wa
 */
class PasskeyAccessTest extends BrowserTestBase {

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
   * Tests access to the manage passkeys route.
   */
  public function testManagePasskeysAccess(): void {
    $owner = $this->drupalCreateUser([]);
    $other = $this->drupalCreateUser([]);
    $admin = $this->drupalCreateUser(['administer all user passkey']);

    // Owners can access their own page.
    $this->drupalLogin($owner);
    $this->drupalGet('user/' . $owner->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);

    // Non-owners without admin permission are denied.
    $this->drupalGet('user/' . $other->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(403);

    // Admins can access others' pages.
    $this->drupalLogin($admin);
    $this->drupalGet('user/' . $other->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests access to the admin settings form.
   */
  public function testAdminSettingsAccess(): void {
    $regular = $this->drupalCreateUser([]);
    $admin = $this->drupalCreateUser(['administer all user passkey']);

    // Anonymous denied.
    $this->drupalGet('/admin/config/people/wa');
    $this->assertSession()->statusCodeEquals(403);

    // Authenticated without permission denied.
    $this->drupalLogin($regular);
    $this->drupalGet('/admin/config/people/wa');
    $this->assertSession()->statusCodeEquals(403);

    // Admin allowed.
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/people/wa');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests access to the passkey delete route.
   */
  public function testDeleteAccess(): void {
    $owner = $this->drupalCreateUser([]);
    $noPerm = $this->drupalCreateUser([]);

    // Seed a credential for the owner.
    $credentialId = 'cred123';
    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $owner->id(),
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

    $encodedId = base64_encode($credentialId);

    // User without permission cannot delete.
    $this->drupalLogin($noPerm);
    // Try without token first to verify route existence
    // (should be 403 due to missing token).
    $this->drupalPostJson('/wa/passkey/delete', ['id' => $encodedId]);
    $this->assertSession()->statusCodeEquals(403);

    // Owner with permission can delete.
    $this->drupalLogin($owner);
    // Get a valid token for the owner by visiting the page.
    $this->drupalGet('user/' . $owner->id() . '/passkeys');
    $settings = $this->getDrupalSettings();
    $token = $settings['wa']['passkeyDeleteToken'];

    $this->drupalPostJson('/wa/passkey/delete', ['id' => $encodedId], [], ['X-CSRF-Token' => $token]);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests access to registration verification.
   */
  public function testRegisterVerifyAccess(): void {
    // Create a custom role and restrict to it.
    $config = \Drupal::configFactory()->getEditable('wa.settings');
    $allowed_role = $this->createRole([], 'passkey_allowed_role');
    $config->set('enable_passkey_login', TRUE)->set('allowed_roles', [$allowed_role])->save();

    $noRole = $this->drupalCreateUser([]);
    $withRole = $this->drupalCreateUser([]);
    $withRole->addRole($allowed_role);
    $withRole->save();

    // Anonymous denied.
    $this->drupalPostJson('/wa/register/verify', []);
    $this->assertSession()->statusCodeEquals(403);

    // Authenticated without role denied.
    $this->drupalLogin($noRole);
    $verifyToken = \Drupal::service('csrf_token')->get('wa/register/verify');
    $this->drupalPostJson('/wa/register/verify', [], [], ['X-CSRF-Token' => $verifyToken]);
    $this->assertSession()->statusCodeEquals(403);

    // Authenticated with role reaches controller
    // (challenge missing -> 400).
    $this->drupalLogin($withRole);
    // Fetch token from passkey management page to ensure session-bound token.
    $this->drupalGet('user/' . $withRole->id() . '/passkeys');
    $settings = $this->getDrupalSettings();
    $verifyToken = $settings['wa']['registerVerifyToken'] ?? \Drupal::service('csrf_token')->get('wa/register/verify');
    $this->drupalPostJson('/wa/register/verify', [], [], ['X-CSRF-Token' => $verifyToken]);
    $this->assertSession()->statusCodeEquals(400);
  }

  /**
   * Tests flood control on login verification.
   */
  public function testLoginVerifyFlood(): void {
    $user = $this->drupalCreateUser([]);
    $credentialId = 'cred-flood-test';

    // Authenticated user making repeated bad attempts.
    $this->drupalLogin($user);

    // 5 failed attempts allowed (missing challenge triggers failure).
    for ($i = 0; $i < 5; $i++) {
      $this->drupalPostJson('/wa/login/verify', ['id' => $credentialId], [], [
        'X-Requested-With' => 'XMLHttpRequest',
        'Origin' => $this->baseUrl,
      ]);
      $this->assertSession()->statusCodeEquals(400);
    }

    // 6th attempt should be blocked by flood (429).
    $this->drupalPostJson('/wa/login/verify', ['id' => $credentialId], [], [
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);
    $this->assertSession()->statusCodeEquals(429);

    // Even if IP changes, per-credential/user throttling should still apply.
    $this->drupalLogout();
    $this->drupalLogin($user);
    $this->drupalPostJson('/wa/login/verify', ['id' => $credentialId], [], [
      'X-Requested-With' => 'XMLHttpRequest',
      'Origin' => $this->baseUrl,
    ]);
    $this->assertSession()->statusCodeEquals(429);
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

}
