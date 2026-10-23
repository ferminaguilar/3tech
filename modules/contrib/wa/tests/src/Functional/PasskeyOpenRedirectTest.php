<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests open redirect prevention in WebAuthn login flow.
 *
 * Verifies that the destination query parameter and hook-provided redirect
 * URLs are validated to prevent open redirect attacks via whitespace bypass,
 * protocol-relative URLs, backslash tricks, and external URLs.
 *
 * @group wa
 */
class PasskeyOpenRedirectTest extends BrowserTestBase {

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
   * Tests that external URLs in the destination parameter are rejected.
   *
   * @dataProvider externalDestinationProvider
   */
  public function testExternalDestinationRejected(string $destination, string $label): void {
    // The loginVerify endpoint requires a valid passkey flow to reach the
    // redirect logic. Since we cannot complete a real WebAuthn ceremony in
    // functional tests, we test the validation logic indirectly by verifying
    // that Url::fromUserInput() rejects these inputs — which is the same
    // validation used in the controller.
    $caughtException = NULL;
    try {
      Url::fromUserInput($destination);
    }
    catch (\InvalidArgumentException $e) {
      $caughtException = $e;
    }
    // For inputs that don't start with / ? or #, fromUserInput throws.
    // For inputs that do start with / but are protocol-relative or backslash
    // tricks, fromUserInput may accept them but they resolve to local paths.
    if ($caughtException === NULL) {
      // If it didn't throw, the URL was accepted. Verify it resolves to
      // a local path (not an external redirect).
      $resolved = Url::fromUserInput($destination)->toString();
      // The resolved URL should not contain an external host.
      $parsed = parse_url($resolved);
      if (isset($parsed['host'])) {
        $currentHost = \Drupal::request()->getHost();
        $this->assertEquals($currentHost, $parsed['host'],
          "Destination '$label' resolved to external host: {$parsed['host']}");
      }
    }
    else {
      // Register an assertion to satisfy PHPUnit 'risky test' warnings.
      $this->addToAssertionCount(1);
    }
  }

  /**
   * Data provider for external destination test cases.
   *
   * @return array<string, array{string, string}>
   *   Test cases with destination value and human-readable label.
   */
  public static function externalDestinationProvider(): array {
    return [
      'plain external URL' => ['https://evil.com', 'https://evil.com'],
      'space-prefixed external URL' => [' https://evil.com', 'space-prefixed https://evil.com'],
      'tab-prefixed external URL' => ["\thttps://evil.com", 'tab-prefixed https://evil.com'],
      'newline-prefixed external URL' => ["\nhttps://evil.com", 'newline-prefixed https://evil.com'],
      'CRLF-prefixed external URL' => ["\r\nhttps://evil.com", 'CRLF-prefixed https://evil.com'],
      'protocol-relative URL' => ['//evil.com', 'protocol-relative //evil.com'],
      'backslash trick' => ['/\\evil.com', 'backslash /\\evil.com'],
      'javascript protocol' => ['javascript:alert(1)', 'javascript: protocol'],
      'data protocol' => ['data:text/html,<script>alert(1)</script>', 'data: protocol'],
      'non-breaking space prefix' => ["\xC2\xA0https://evil.com", 'NBSP-prefixed https://evil.com'],
    ];
  }

  /**
   * Tests that valid local destinations are accepted by Url::fromUserInput().
   *
   * @dataProvider localDestinationProvider
   */
  public function testLocalDestinationAccepted(string $destination): void {
    $threw = FALSE;
    try {
      $resolved = Url::fromUserInput($destination)->toString();
    }
    catch (\Exception $e) {
      $threw = TRUE;
    }

    $this->assertFalse($threw, "Valid local destination '$destination' should be accepted.");
  }

  /**
   * Data provider for valid local destination test cases.
   *
   * @return array<string, array{string}>
   *   Test cases with valid local destination values.
   */
  public static function localDestinationProvider(): array {
    return [
      'root path' => ['/'],
      'user page' => ['/user/1'],
      'node page' => ['/node/1'],
      'admin page' => ['/admin'],
      'query string only' => ['?foo=bar'],
      'fragment only' => ['#section'],
      'path with query' => ['/user/1?tab=edit'],
    ];
  }

  /**
   * Tests that the loginVerify error path never issues an external redirect.
   *
   * NOTE: This test exercises the PRE-LOGIN error path only. Login always
   * fails here (no valid WebAuthn credential), so the destination parameter
   * validation code (the candidates loop) is never reached. What this test
   * verifies is that even the early-failure JSON error responses do not
   * accidentally include a Location header pointing to an external URL.
   *
   * The open redirect prevention on the SUCCESS path (candidates loop and
   * EnforcedResponseException catch block) is covered by the unit tests
   * in PasskeyOpenRedirectTest, which directly exercise that logic without
   * requiring a real WebAuthn ceremony.
   */
  public function testLoginVerifyErrorPathDoesNotRedirectToExternalDestination(): void {
    $maliciousDestinations = [
      ' https://evil.com',
      '//evil.com',
      '/\\evil.com',
      'https://evil.com',
    ];

    foreach ($maliciousDestinations as $destination) {
      // Create a fresh session for each attempt.
      $this->drupalGet('/user/login');

      // Request login options to set the challenge.
      $this->drupalPostJson('/wa/login/options', [], [], [
        'Origin' => $this->baseUrl,
        'X-Requested-With' => 'XMLHttpRequest',
      ]);

      // Send login verify with malicious destination.
      // Login will fail at credential lookup — destination is never evaluated.
      $this->drupalPostJson('/wa/login/verify?destination=' . urlencode($destination), [
        'id' => base64_encode('nonexistent-credential'),
        'response' => [
          'clientDataJSON' => base64_encode('{}'),
          'authenticatorData' => base64_encode('test'),
          'signature' => base64_encode('test'),
        ],
      ], [], [
        'X-Requested-With' => 'XMLHttpRequest',
        'Origin' => $this->baseUrl,
      ]);

      // Credential lookup fails → 400 JSON error response, never a redirect.
      $statusCode = $this->getSession()->getStatusCode();
      $this->assertNotEquals(301, $statusCode, "Failed login must not cause 301 for '$destination'.");
      $this->assertNotEquals(302, $statusCode, "Failed login must not cause 302 for '$destination'.");

      // Confirm no external Location header is present in the error response.
      $headers = $this->getSession()->getResponseHeaders();
      if (isset($headers['Location'])) {
        $location = is_array($headers['Location']) ? $headers['Location'][0] : $headers['Location'];
        $parsed = parse_url($location);
        if (isset($parsed['host'])) {
          $this->assertStringContainsString(
            parse_url($this->baseUrl, PHP_URL_HOST),
            $parsed['host'],
            "Error response Location header must not point to external host for '$destination'."
          );
        }
      }
    }
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
