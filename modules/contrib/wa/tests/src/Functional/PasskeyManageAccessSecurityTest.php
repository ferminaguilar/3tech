<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests access control security fixes for PasskeyManageAccessCheck.
 *
 * Tests the following security fixes:
 * - Blocked users cannot access passkey management.
 * - Empty allowed_roles configuration denies regular users but allows admins.
 * - Users with disallowed roles cannot manage their own passkeys.
 * - Admins can view/delete passkeys for users with disallowed roles.
 *
 * @group wa
 */
#[RunTestsInSeparateProcesses]
class PasskeyManageAccessSecurityTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['wa'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with admin permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * A regular user with allowed role.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $allowedUser;

  /**
   * A role ID that is allowed for passkey management.
   *
   * @var string
   */
  protected $allowedRole;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a custom role for testing role restrictions.
    $this->allowedRole = $this->createRole([], 'passkey_allowed');

    // Enable passkey login with the allowed role.
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [$this->allowedRole])
      ->save();

    // Create admin user.
    $this->adminUser = $this->drupalCreateUser(['administer all user passkey']);

    // Create a user with the allowed role.
    $this->allowedUser = $this->drupalCreateUser([]);
    $this->allowedUser->addRole($this->allowedRole);
    $this->allowedUser->save();
  }

  /**
   * Tests access control for blocked users' passkey management.
   */
  public function testBlockedUserAccessDenied(): void {
    // Create a blocked user with the allowed role.
    $blockedUser = $this->drupalCreateUser([]);
    $blockedUser->addRole($this->allowedRole);
    $blockedUser->block();
    $blockedUser->save();

    // Admin CAN access blocked user's passkey page (to view/delete passkeys).
    // Admin permission check happens before blocked user check.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('user/' . $blockedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);

    // Verify the allowed user's own page is still accessible.
    $this->drupalLogin($this->allowedUser);
    $this->drupalGet('user/' . $this->allowedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that empty allowed_roles denies regular users but allows admins.
   */
  public function testEmptyAllowedRolesConfiguration(): void {
    // Clear allowed roles.
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('allowed_roles', [])
      ->save();

    // Regular user cannot access their own passkey page.
    $this->drupalLogin($this->allowedUser);
    $this->drupalGet('user/' . $this->allowedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(403);

    // Admin can still access any user's passkey page (for view/delete).
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('user/' . $this->allowedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);

    // Admin can access their own passkey page too.
    $this->drupalGet('user/' . $this->adminUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that users with disallowed roles cannot manage their own passkeys.
   */
  public function testDisallowedRoleUserDenied(): void {
    // Create a user without the allowed role.
    $disallowedUser = $this->drupalCreateUser([]);

    // User without allowed role cannot access their own passkey page.
    $this->drupalLogin($disallowedUser);
    $this->drupalGet('user/' . $disallowedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(403);

    // User with allowed role can access their own passkey page.
    $this->drupalLogin($this->allowedUser);
    $this->drupalGet('user/' . $this->allowedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that admins can access passkey page for users with disallowed roles.
   *
   * Admins should be able to view and delete passkeys for any user,
   * even if that user's role is not in the allowed_roles configuration.
   */
  public function testAdminCanViewDisallowedRoleUserPasskeys(): void {
    // Create a user without the allowed role.
    $disallowedUser = $this->drupalCreateUser([]);

    // Seed a credential for the disallowed user.
    $credentialId = 'cred-disallowed-user';
    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $disallowedUser->id(),
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

    // Admin can access the disallowed user's passkey page.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('user/' . $disallowedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);

    // Verify the passkey is visible (table with delete button).
    $this->assertSession()->buttonExists('Delete');
  }

  /**
   * Tests that admins cannot add passkeys for users with disallowed roles.
   *
   * When the current user (admin) tries to register a passkey for themselves
   * but their role is not allowed, registration should be denied.
   */
  public function testAdminCannotAddPasskeyWhenRoleNotAllowed(): void {
    // Admin user doesn't have the allowed role.
    $this->drupalLogin($this->adminUser);

    // Get CSRF token from the passkey management page.
    $this->drupalGet('user/' . $this->adminUser->id() . '/passkeys');
    $settings = $this->getDrupalSettings();
    $registerOptionsToken = $settings['wa']['registerOptionsToken'] ?? '';

    // Attempt to get registration options - should be denied because
    // the admin's role is not in allowed_roles.
    $this->drupalPostJson('/wa/register/options', [], [], ['X-CSRF-Token' => $registerOptionsToken]);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->responseContains('Passkey registration not permitted for your role.');
  }

  /**
   * Tests that users with allowed roles can add passkeys.
   */
  public function testAllowedRoleUserCanAddPasskey(): void {
    $this->drupalLogin($this->allowedUser);

    // Get CSRF token from the passkey management page.
    $this->drupalGet('user/' . $this->allowedUser->id() . '/passkeys');
    $settings = $this->getDrupalSettings();
    $registerOptionsToken = $settings['wa']['registerOptionsToken'] ?? '';

    // Attempt to get registration options - should succeed.
    $this->drupalPostJson('/wa/register/options', [], [], ['X-CSRF-Token' => $registerOptionsToken]);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests access when passkey login is globally disabled.
   */
  public function testPasskeyLoginDisabled(): void {
    // Disable passkey login globally.
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('enable_passkey_login', FALSE)
      ->save();

    // Admin CAN still access passkey pages (to view/delete existing passkeys).
    // Admin permission check happens before global disable check.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('user/' . $this->allowedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);

    // Regular user cannot access their own passkey page.
    $this->drupalLogin($this->allowedUser);
    $this->drupalGet('user/' . $this->allowedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that anonymous users cannot access passkey management.
   */
  public function testAnonymousAccessDenied(): void {
    // Anonymous user cannot access any passkey page.
    $this->drupalGet('user/' . $this->allowedUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(403);
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
