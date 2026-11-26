<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests passkey configuration options.
 *
 * @group wa
 */
class PasskeyConfigurationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['wa', 'user'];

  /**
   * The user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Create a user.
    $this->user = $this->drupalCreateUser([]);
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('allowed_roles', [RoleInterface::AUTHENTICATED_ID])
      ->save();
  }

  /**
   * Helper to post JSON.
   */
  protected function drupalPostJson(string $path, array $data, array $headers = []): void {
    $content = json_encode($data);
    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $driver = $this->getSession()->getDriver();
    $driver->getClient()->request(
      'POST',
      $this->buildUrl($path),
      [],
      [],
      $server,
      $content
    );
  }

  /**
   * Tests global disable switch.
   */
  public function testGlobalDisable(): void {
    $config = \Drupal::configFactory()->getEditable('wa.settings');

    // 1. Enable passkeys (default).
    $config->set('enable_passkey_login', TRUE)->save();

    // Check login page for button.
    $this->drupalGet('/user/login');
    $this->assertSession()->elementExists('css', '#wa-login-btn');

    // Check endpoints.
    $this->drupalLogin($this->user);

    // Get valid session CSRF token and re-authenticate.
    $token = $this->getSessionCsrfToken();
    $this->drupalPostJson('/wa/register/reauth', [
      'method' => 'password',
      'password' => $this->user->passRaw,
    ], ['X-CSRF-Token' => $token]);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalPostJson('/wa/register/options', [], ['X-CSRF-Token' => $token]);
    $this->assertSession()->statusCodeEquals(200);

    // 2. Disable passkeys.
    $config->set('enable_passkey_login', FALSE)->save();
    \Drupal::service('cache.render')->deleteAll();
    \Drupal::service('cache.page')->deleteAll();
    \Drupal::service('cache.dynamic_page_cache')->deleteAll();

    // Check login page for button.
    $this->drupalLogout();
    $this->drupalGet('/user/login');
    $this->assertSession()->elementNotExists('css', '#wa-login-btn');

    // Check endpoints.
    $this->drupalLogin($this->user);
    $token = $this->getSessionCsrfToken();
    $this->drupalPostJson('/wa/register/options', [], ['X-CSRF-Token' => $token]);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests role restrictions.
   */
  public function testRoleRestriction(): void {
    $config = \Drupal::configFactory()->getEditable('wa.settings');
    $config->set('enable_passkey_login', TRUE)->save();

    // Create a role 'passkey_user'.
    $role = $this->createRole([], 'passkey_user', 'Passkey User');

    // Restrict to 'passkey_user'.
    $config->set('allowed_roles', ['passkey_user'])->save();

    // User does not have the role yet.
    $this->drupalLogin($this->user);
    $token = $this->getSessionCsrfToken();

    $this->drupalPostJson('/wa/register/options', [], ['X-CSRF-Token' => $token]);
    $this->assertSession()->statusCodeEquals(403);

    // Grant the role.
    $this->user->addRole('passkey_user');
    $this->user->save();

    // Re-authenticate and retry.
    $this->drupalPostJson('/wa/register/reauth', [
      'method' => 'password',
      'password' => $this->user->passRaw,
    ], ['X-CSRF-Token' => $token]);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalPostJson('/wa/register/options', [], ['X-CSRF-Token' => $token]);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Returns a CSRF token for the current session via /session/token.
   */
  protected function getSessionCsrfToken(): string {
    $this->drupalGet('/session/token');
    return trim($this->getSession()->getPage()->getContent());
  }

}
