<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests multilingual access and CSRF validation for WA routes.
 *
 * @group wa
 */
class PasskeyMultilingualTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['wa', 'language', 'locale'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create and log in user with permissions.
    $admin_user = $this->drupalCreateUser([
      'administer languages',
    ]);
    $this->drupalLogin($admin_user);

    // Add French language via the UI (following core pattern).
    $edit = ['predefined_langcode' => 'fr'];
    $this->drupalGet('admin/config/regional/language/add');
    $this->submitForm($edit, 'Add language');

    // Enable URL language detection and selection.
    $edit = ['language_interface[enabled][language-url]' => '1'];
    $this->drupalGet('admin/config/regional/language/detection');
    $this->submitForm($edit, 'Save settings');

    // Configure WA settings.
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [RoleInterface::AUTHENTICATED_ID])
      ->save();
  }

  /**
   * Tests CSRF validation on multilingual routes.
   */
  public function testMultilingualCsrf(): void {
    // Create a regular user (the admin is for setup only).
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);

    // Fetch session CSRF token via /session/token and re-authenticate.
    $this->drupalGet('/session/token');
    $token = trim($this->getSession()->getPage()->getContent());

    $this->drupalPostJson('/wa/register/reauth', [
      'method' => 'password',
      'password' => $user->passRaw,
    ], [], [
      'X-CSRF-Token' => $token,
    ]);
    $this->assertSession()->statusCodeEquals(200);

    // Test 1: Request the English (default) route.
    $this->drupalPostJson('/wa/register/options', [], [], [
      'X-CSRF-Token' => $token,
    ]);
    $this->assertSession()->statusCodeEquals(200);

    // Test 2: Request the French route with language prefix using the same
    // session header token (_csrf_request_header_token).
    $this->drupalPostJson('/fr/wa/register/options', [], [], [
      'X-CSRF-Token' => $token,
    ]);
    $this->assertSession()->statusCodeEquals(200);

    // Test 3: Missing or invalid CSRF token is rejected at the routing layer.
    $this->drupalPostJson('/fr/wa/register/options', [], [], [
      'X-CSRF-Token' => 'invalid-token',
    ]);
    $this->assertSession()->statusCodeEquals(403);
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
