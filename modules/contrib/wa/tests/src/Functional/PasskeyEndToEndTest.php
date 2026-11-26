<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * End-to-end tests exercising real WebAuthn crypto and OTP HTTP flows.
 *
 * @group wa
 */
#[Group('wa')]
#[RunTestsInSeparateProcesses]
class PasskeyEndToEndTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['wa', 'wa_email_otp'];

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
      ->set('allowed_aaguids', ['00000000-0000-0000-0000-000000000000'])
      ->set('user_verification', 'required')
      ->set('resident_key', 'required')
      ->save();

    // Disable OTP by default for the direct WebAuthn E2E test; the OTP E2E
    // test enables it explicitly.
    \Drupal::configFactory()->getEditable('wa_email_otp.settings')
      ->set('enable_otp', FALSE)
      ->save();
  }

  /**
   * Tests a full real ES256 passkey registration and login ceremony over HTTP.
   */
  public function testEndToEndPasskeyRegistrationAndLogin(): void {
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);

    $csrfToken = $this->getSessionCsrfToken();

    // 1. Re-authenticate with current password.
    $this->drupalPostJson('/wa/register/reauth', [
      'method' => 'password',
      'password' => $user->passRaw,
    ], [], ['X-CSRF-Token' => $csrfToken]);
    $this->assertSession()->statusCodeEquals(200);

    // 2. Request registration options.
    $this->drupalPostJson('/wa/register/options', [], [], ['X-CSRF-Token' => $csrfToken]);
    $this->assertSession()->statusCodeEquals(200);
    $regOptions = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('required', $regOptions['authenticatorSelection']['userVerification']);
    $this->assertTrue($regOptions['authenticatorSelection']['requireResidentKey']);

    // 3. Generate a real ECDSA P-256 key pair and build a spec-compliant
    // WebAuthn registration response (no mocks).
    $keyPair = openssl_pkey_new([
      'curve_name' => 'prime256v1',
      'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    $this->assertNotFalse($keyPair);
    $details = openssl_pkey_get_details($keyPair);
    $this->assertIsArray($details);

    $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    // COSE EC2 P-256 key: {1: 2, 3: -7, -1: 1, -2: x, -3: y}.
    $cosePublicKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y;

    $rawCredentialId = random_bytes(32);
    $encodedCredentialId = Base64UrlSafe::encodeUnpadded($rawCredentialId);
    $rpId = (string) parse_url($this->baseUrl, PHP_URL_HOST);
    $origin = $this->getExpectedOrigin();
    $rpIdHash = hash('sha256', $rpId, TRUE);

    // Flags: UP (0x01) | UV (0x04) | AT (0x40) = 0x45.
    $attestedCredData = str_repeat("\x00", 16)
      . pack('n', strlen($rawCredentialId))
      . $rawCredentialId
      . $cosePublicKey;
    $regAuthData = $rpIdHash . chr(0x45) . pack('N', 1) . $attestedCredData;
    $len = strlen($regAuthData);
    $bytesHeader = $len < 256 ? "\x58" . chr($len) : "\x59" . pack('n', $len);
    $attestationObject = "\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData" . $bytesHeader . $regAuthData;

    $regClientDataJson = json_encode([
      'type' => 'webauthn.create',
      'challenge' => $regOptions['challenge'],
      'origin' => $origin,
      'crossOrigin' => FALSE,
    ]);

    // 4. Verify registration through the real controller + webauthn-lib.
    $this->drupalPostJson('/wa/register/verify', [
      'id' => $encodedCredentialId,
      'rawId' => $encodedCredentialId,
      'type' => 'public-key',
      'response' => [
        'clientDataJSON' => Base64UrlSafe::encodeUnpadded((string) $regClientDataJson),
        'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
        'transports' => ['internal'],
      ],
    ], [], ['X-CSRF-Token' => $csrfToken]);
    $this->assertSession()->statusCodeEquals(200);
    $regResult = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('ok', $regResult['status']);

    // 5. Re-authentication marker must be consumed (single-use): a second
    // registerOptions call without re-authenticating must be denied (403).
    $this->drupalPostJson('/wa/register/options', [], [], ['X-CSRF-Token' => $csrfToken]);
    $this->assertSession()->statusCodeEquals(403);
    $secondReg = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertTrue($secondReg['reauth_required'] ?? FALSE);

    // 6. Log out and perform a full real WebAuthn login with ?destination=.
    $this->drupalLogout();

    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $origin,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $loginOptions = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $loginChallenge = $loginOptions['challenge'];

    // Build real signed assertion (UP=1, UV=1 => 0x05, counter=2).
    $loginClientDataJson = (string) json_encode([
      'type' => 'webauthn.get',
      'challenge' => $loginChallenge,
      'origin' => $origin,
      'crossOrigin' => FALSE,
    ]);
    $loginAuthData = $rpIdHash . chr(0x05) . pack('N', 2);
    $signedData = $loginAuthData . hash('sha256', $loginClientDataJson, TRUE);
    $signature = '';
    $this->assertTrue(openssl_sign($signedData, $signature, $keyPair, OPENSSL_ALGO_SHA256));

    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedCredentialId,
      'rawId' => $encodedCredentialId,
      'type' => 'public-key',
      'response' => [
        'clientDataJSON' => Base64UrlSafe::encodeUnpadded($loginClientDataJson),
        'authenticatorData' => Base64UrlSafe::encodeUnpadded($loginAuthData),
        'signature' => Base64UrlSafe::encodeUnpadded($signature),
        'userHandle' => Base64UrlSafe::encodeUnpadded((string) $user->id()),
      ],
    ], ['query' => ['destination' => '/user/' . $user->id() . '/edit']], [
      'Origin' => $origin,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    // loginVerify returns JSON with redirect_url (no double-fetch 302).
    $this->assertSession()->statusCodeEquals(200);
    $loginResult = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('ok', $loginResult['status']);
    $this->assertStringEndsWith('/user/' . $user->id() . '/edit', $loginResult['redirect_url']);

    // Verify the browser session is now authenticated and counter updated to 2.
    $this->drupalGet('/user/' . $user->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($user->getAccountName());

    $storedCounter = (int) \Drupal::database()->select('wa', 'w')
      ->fields('w', ['sign_counter'])
      ->condition('credential_hash', hash('sha256', $rawCredentialId))
      ->execute()
      ->fetchField();
    $this->assertSame(2, $storedCounter);
  }

  /**
   * Tests real HTTP Email OTP Resend after expiry and completion of login.
   */
  public function testEndToEndEmailOtpResendAfterExpiryAndLogin(): void {
    \Drupal::configFactory()->getEditable('wa_email_otp.settings')
      ->set('enable_otp', TRUE)
      ->save();

    $user = $this->drupalCreateUser([]);

    // Seed a real passkey and complete passkey login to trigger wa_email_otp.
    $keyPair = openssl_pkey_new([
      'curve_name' => 'prime256v1',
      'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    $this->assertNotFalse($keyPair);
    $details = openssl_pkey_get_details($keyPair);
    $this->assertIsArray($details);
    $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $cosePublicKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y;

    $rawCredentialId = random_bytes(32);
    $encodedCredentialId = Base64UrlSafe::encodeUnpadded($rawCredentialId);
    $now = \Drupal::time()->getRequestTime();
    \Drupal::database()->insert('wa')->fields([
      'uid' => $user->id(),
      'credential_id' => $rawCredentialId,
      'credential_hash' => hash('sha256', $rawCredentialId),
      'public_key' => base64_encode($cosePublicKey),
      'user_handle' => (string) $user->id(),
      'sign_counter' => 0,
      'transports' => json_encode(['internal']),
      'aaguid' => '00000000-0000-0000-0000-000000000000',
      'created' => $now,
      'last_used' => $now,
    ])->execute();

    $origin = $this->getExpectedOrigin();
    $rpId = (string) parse_url($this->baseUrl, PHP_URL_HOST);
    $rpIdHash = hash('sha256', $rpId, TRUE);

    $this->drupalPostJson('/wa/login/options', [], [], [
      'Origin' => $origin,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $challenge = json_decode($this->getSession()->getPage()->getContent(), TRUE)['challenge'];

    $clientDataJson = (string) json_encode([
      'type' => 'webauthn.get',
      'challenge' => $challenge,
      'origin' => $origin,
      'crossOrigin' => FALSE,
    ]);
    $authData = $rpIdHash . chr(0x05) . pack('N', 1);
    $signature = '';
    $this->assertTrue(openssl_sign($authData . hash('sha256', $clientDataJson, TRUE), $signature, $keyPair, OPENSSL_ALGO_SHA256));

    $this->drupalPostJson('/wa/login/verify', [
      'id' => $encodedCredentialId,
      'rawId' => $encodedCredentialId,
      'type' => 'public-key',
      'response' => [
        'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
        'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
        'signature' => Base64UrlSafe::encodeUnpadded($signature),
        'userHandle' => Base64UrlSafe::encodeUnpadded((string) $user->id()),
      ],
    ], [], [
      'Origin' => $origin,
      'X-Requested-With' => 'XMLHttpRequest',
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $loginResult = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('redirect', $loginResult['status']);
    $this->assertStringContainsString('/wa_otp/' . $user->id() . '/', $loginResult['redirect_url']);

    // Age the OTP row past the 60-second validity window (120 seconds old).
    \Drupal::database()->update('wa_email_otp')
      ->fields(['created' => $now - 120])
      ->condition('uid', $user->id())
      ->execute();

    // Visit the expired OTP page: must render the form with Resend button
    // (not redirect to /user/login).
    $this->drupalGet($loginResult['redirect_url']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Your verification code has expired.');

    // Click "Resend OTP" 5 times via real HTTP form submissions.
    for ($i = 0; $i < 5; $i++) {
      $this->submitForm([], 'Resend OTP');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('A new OTP has been sent to your email.');
    }

    // Extract the latest 6-digit OTP from the captured mail and verify login
    // STILL succeeds (proving 5 resend requests burned zero verify attempts).
    $mails = $this->getMails(['id' => 'wa_email_otp_otp_login']);
    $this->assertNotEmpty($mails);
    $lastMail = end($mails);
    $this->assertMatchesRegularExpression('/\b(\d{6})\b/', $lastMail['body']);
    preg_match('/\b(\d{6})\b/', $lastMail['body'], $matches);
    $otpCode = $matches[1];

    $this->submitForm(['otp_entry' => $otpCode], 'Verify & Login');
    $this->assertSession()->statusCodeEquals(200);

    // Confirm user is now authenticated.
    $this->drupalGet('/user/' . $user->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($user->getAccountName());
  }

  /**
   * Tests SSO-only accounts without a password can re-authenticate by email.
   */
  public function testSsoOnlyUserEmailCodeReauth(): void {
    $ssoUser = $this->drupalCreateUser([]);
    $this->drupalLogin($ssoUser);

    // Clear the user's local password hash to simulate an SSO-only account.
    \Drupal::database()->update('users_field_data')
      ->fields(['pass' => NULL])
      ->condition('uid', $ssoUser->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('user')->resetCache([$ssoUser->id()]);

    // Management page renders the email code re-auth controls instead of a
    // current password input.
    $this->drupalGet('/user/' . $ssoUser->id() . '/passkeys');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#wa-reauth-email-send');
    $this->assertSession()->elementExists('css', '#wa-reauth-email-code');
    $this->assertSession()->elementNotExists('css', '#wa-reauth-password');

    $csrfToken = $this->getSessionCsrfToken();

    // Send email verification code.
    $this->drupalPostJson('/wa/register/reauth', [
      'method' => 'email_send',
    ], [], ['X-CSRF-Token' => $csrfToken]);
    $this->assertSession()->statusCodeEquals(200);

    $mails = $this->getMails(['id' => 'wa_passkey_reauth_code']);
    $this->assertNotEmpty($mails);
    $lastMail = end($mails);
    $this->assertMatchesRegularExpression('/\b(\d{6})\b/', $lastMail['body']);
    preg_match('/\b(\d{6})\b/', $lastMail['body'], $matches);
    $code = $matches[1];

    // Verify email code, then request registration options.
    $this->drupalPostJson('/wa/register/reauth', [
      'method' => 'email_verify',
      'code' => $code,
    ], [], ['X-CSRF-Token' => $csrfToken]);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalPostJson('/wa/register/options', [], [], ['X-CSRF-Token' => $csrfToken]);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Computes the expected origin (scheme://host[:port]) from $this->baseUrl.
   */
  protected function getExpectedOrigin(): string {
    $parts = parse_url($this->baseUrl);
    $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
    if (isset($parts['port'])) {
      $origin .= ':' . $parts['port'];
    }
    return $origin;
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

  /**
   * Returns a CSRF token for the current session via /session/token.
   */
  protected function getSessionCsrfToken(): string {
    $this->drupalGet('/session/token');
    return trim($this->getSession()->getPage()->getContent());
  }

}
