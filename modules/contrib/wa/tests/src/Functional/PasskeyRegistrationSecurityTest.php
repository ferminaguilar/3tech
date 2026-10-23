<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests security aspects of Passkey registration.
 *
 * @group wa
 */
class PasskeyRegistrationSecurityTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['wa', 'user'];

  /**
   * A user with permission to use passkeys.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * CSRF token for registration options.
   *
   * @var string
   */
  protected $registerOptionsToken;

  /**
   * CSRF token for registration verify.
   *
   * @var string
   */
  protected $registerVerifyToken;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->user = $this->drupalCreateUser([]);
    $this->drupalLogin($this->user);
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [RoleInterface::AUTHENTICATED_ID])
      ->save();

    // Load management page to populate tokens from drupalSettings.
    $this->drupalGet('/user/' . $this->user->id() . '/passkeys');
    $settings = $this->getDrupalSettings();
    $this->registerOptionsToken = $settings['wa']['registerOptionsToken'] ?? \Drupal::service('csrf_token')->get('wa/register/options');
    $this->registerVerifyToken = $settings['wa']['registerVerifyToken'] ?? \Drupal::service('csrf_token')->get('wa/register/verify');
  }

  /**
   * Tests that registration replay attacks are prevented.
   */
  public function testRegistrationReplay(): void {
    $optionsToken = $this->registerOptionsToken;
    $verifyToken = $this->registerVerifyToken;

    // 1. Get registration options to start the session.
    $this->drupalPost('wa/register/options', [], ['query' => ['_format' => 'json']], ['HTTP_X_CSRF_TOKEN' => $optionsToken]);
    $session = $this->getSession();
    $headers = $session->getResponseHeaders();

    // We will test the *challenge cleanup* mechanism.
    // Verify challenge is in session.
    $session_data = \Drupal::request()->getSession()->get('wa_challenge');
    // Challenge is now an array with 'value'
    // and 'created' keys for TTL validation.
    // Step 1: Get options (sets challenge).
    $this->drupalPost('wa/register/options', [], ['query' => ['_format' => 'json']], ['HTTP_X_CSRF_TOKEN' => $optionsToken]);

    // Step 2: Send a request.
    // It will fail validation (invalid data), but should clear challenge.
    $invalid_response = [
      'id' => 'base64id',
      'rawId' => 'base64id',
      'response' => [
        'clientDataJSON' => 'base64',
        'attestationObject' => 'base64',
      ],
      'type' => 'public-key',
    ];

    $this->drupalPost(
      'wa/register/verify',
      [],
      ['query' => ['_format' => 'json']],
      [
        'content' => json_encode($invalid_response),
        'HTTP_X_CSRF_TOKEN' => $verifyToken,
      ]
    );
    $this->assertSession()->statusCodeEquals(400);

    // Ensure the challenge was cleared after the first attempt.
    $challengeData = \Drupal::request()->getSession()->get('wa_challenge');
    $this->assertEmpty($challengeData);

    // Step 3: Send the same request again; should fail generically.
    $this->drupalPost(
      'wa/register/verify',
      [],
      ['query' => ['_format' => 'json']],
      [
        'content' => json_encode($invalid_response),
        'HTTP_X_CSRF_TOKEN' => $verifyToken,
      ]
    );
    $this->assertSession()->statusCodeEquals(400);
    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertEquals('Passkey registration failed.', $response['message']);
  }

  /**
   * Tests flood control on registration.
   */
  public function testRegistrationFloodControl(): void {
    // Attempt 5 times (allowed).
    $invalid_response = ['id' => 'test'];
    for ($i = 0; $i < 5; $i++) {
      $this->drupalPost(
        'wa/register/verify',
        [],
        ['query' => ['_format' => 'json']],
        [
          'content' => json_encode($invalid_response),
          'HTTP_X_CSRF_TOKEN' => $this->registerVerifyToken,
        ]
      );
      $this->assertSession()->statusCodeEquals(400);
    }

    // 6th time should be blocked (429).
    $this->drupalPost(
      'wa/register/verify',
      [],
      ['query' => ['_format' => 'json']],
      [
        'content' => json_encode($invalid_response),
        'HTTP_X_CSRF_TOKEN' => $this->registerVerifyToken,
      ]
    );
    $this->assertSession()->statusCodeEquals(429);
    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertEquals('Too many registration attempts. Please try again later.', $response['message']);
  }

  /**
   * Helper to post JSON.
   */
  protected function drupalPost(string $path, array $edit, array $options = [], array $headers = []): void {
    $driver = $this->getSession()->getDriver();
    $client = $driver->getClient();
    $url = $this->buildUrl($path, $options);
    $content = $headers['content'] ?? '';
    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
      if ($name === 'content') {
        continue;
      }
      $server[$name] = $value;
    }
    $client->request('POST', $url, $edit, [], $server, $content);
  }

}
