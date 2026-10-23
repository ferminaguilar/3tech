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

    // Get a valid token by visiting the passkey management page.
    $this->drupalGet('user/' . $user->id() . '/passkeys');
    $settings = $this->getDrupalSettings();
    $token = $settings['wa']['registerOptionsToken'];

    // Test 1: Request the English (default) route.
    // This should work with the internal path-based token.
    $this->drupalPostJson('/wa/register/options', [], [], [
      'X-CSRF-Token' => $token,
    ]);
    $this->assertSession()->statusCodeEquals(200);

    // Test 2: Request the French route with language prefix.
    // WITH THE FIX: The controller uses Url::fromRoute()->getInternalPath()
    // which returns 'wa/register/options' for both '/wa/register/options'
    // and '/fr/wa/register/options', so the same token works.
    $this->drupalPostJson('/fr/wa/register/options', [], [], [
      'X-CSRF-Token' => $token,
    ]);
    $this->assertSession()->statusCodeEquals(200);

    // Both English and French routes work with the same token because
    // the controller uses Url::fromRoute()->getInternalPath() which
    // returns the language-independent path 'wa/register/options'.
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
