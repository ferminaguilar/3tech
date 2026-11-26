<?php

namespace Drupal\Tests\wa\Unit\Service;

use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialCreationOptions;
use Drupal\user\UserInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\wa\Service\WebAuthnService;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Unit tests for WebAuthnService.
 *
 * @group wa
 * @coversDefaultClass \Drupal\wa\Service\WebAuthnService
 */
class WebAuthnServiceTest extends UnitTestCase {

  /**
   * The WebAuthn service under test.
   *
   * @var \Drupal\wa\Service\WebAuthnService
   */
  protected WebAuthnService $service;

  /**
   * The mocked request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestStack;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->requestStack = $this->createMock(RequestStack::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['resident_key', 'preferred'],
        ['user_verification', 'preferred'],
      ]);
    $this->configFactory->method('get')
      ->with('wa.settings')
      ->willReturn($config);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(time());

    $this->service = new WebAuthnService(
      $this->requestStack,
      $this->configFactory,
      $time
    );
  }

  /**
   * Tests validateRegistration with missing response field.
   *
   * @covers ::validateRegistration
   */
  public function testValidateRegistrationMissingResponse(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Missing response field in registration data.');

    $options = $this->createValidCreationOptions();
    $this->service->validateRegistration([], $options, 'example.com');
  }

  /**
   * Tests validateRegistration with missing clientDataJSON.
   *
   * @covers ::validateRegistration
   */
  public function testValidateRegistrationMissingClientDataJson(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Missing required registration field: clientDataJSON');

    $options = $this->createValidCreationOptions();
    $this->service->validateRegistration(
      ['response' => ['attestationObject' => 'test']],
      $options,
      'example.com'
    );
  }

  /**
   * Tests validateRegistration with missing attestationObject.
   *
   * @covers ::validateRegistration
   */
  public function testValidateRegistrationMissingAttestationObject(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Missing required registration field: attestationObject');

    $options = $this->createValidCreationOptions();
    $this->service->validateRegistration(
      ['response' => ['clientDataJSON' => 'test']],
      $options,
      'example.com'
    );
  }

  /**
   * Tests validateRegistration with invalid Base64URL clientDataJSON.
   *
   * @covers ::validateRegistration
   */
  public function testValidateRegistrationInvalidBase64ClientData(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid Base64 URL encoding');

    $options = $this->createValidCreationOptions();
    $this->service->validateRegistration(
      [
        'response' => [
          'clientDataJSON' => '!!!invalid-base64!!!',
          'attestationObject' => 'test',
        ],
      ],
      $options,
      'example.com'
    );
  }

  /**
   * Tests validateAssertion with missing response field.
   *
   * @covers ::validateAssertion
   */
  public function testValidateAssertionMissingResponse(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Missing response field in assertion data.');

    $options = $this->createValidRequestOptions();
    $source = $this->createValidCredentialSource();
    $this->service->validateAssertion([], $options, $source, 'example.com', 'userHandle');
  }

  /**
   * Tests validateAssertion with missing clientDataJSON.
   *
   * @covers ::validateAssertion
   */
  public function testValidateAssertionMissingClientDataJson(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Missing required assertion field: clientDataJSON');

    $options = $this->createValidRequestOptions();
    $source = $this->createValidCredentialSource();
    $this->service->validateAssertion(
      ['response' => ['authenticatorData' => 'test', 'signature' => 'test']],
      $options,
      $source,
      'example.com',
      'userHandle'
    );
  }

  /**
   * Tests validateAssertion with missing authenticatorData.
   *
   * @covers ::validateAssertion
   */
  public function testValidateAssertionMissingAuthenticatorData(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Missing required assertion field: authenticatorData');

    $options = $this->createValidRequestOptions();
    $source = $this->createValidCredentialSource();
    $this->service->validateAssertion(
      ['response' => ['clientDataJSON' => 'test', 'signature' => 'test']],
      $options,
      $source,
      'example.com',
      'userHandle'
    );
  }

  /**
   * Tests validateAssertion with missing signature.
   *
   * @covers ::validateAssertion
   */
  public function testValidateAssertionMissingSignature(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Missing required assertion field: signature');

    $options = $this->createValidRequestOptions();
    $source = $this->createValidCredentialSource();
    $this->service->validateAssertion(
      ['response' => ['clientDataJSON' => 'test', 'authenticatorData' => 'test']],
      $options,
      $source,
      'example.com',
      'userHandle'
    );
  }

  /**
   * Tests validateLoginRequest with empty challenge.
   *
   * @covers ::validateLoginRequest
   */
  public function testValidateLoginRequestEmptyChallenge(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Challenge cannot be empty.');

    $this->service->validateLoginRequest(
      [],
      $this->createValidCredentialData(),
      '',
      'example.com',
      'example.com',
      'preferred'
    );
  }

  /**
   * Tests validateLoginRequest with empty rpId.
   *
   * @covers ::validateLoginRequest
   */
  public function testValidateLoginRequestEmptyRpId(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Relying party ID cannot be empty.');

    $this->service->validateLoginRequest(
      [],
      $this->createValidCredentialData(),
      'dGVzdC1jaGFsbGVuZ2U',
      '',
      'example.com',
      'preferred'
    );
  }

  /**
   * Tests validateLoginRequest with empty host.
   *
   * @covers ::validateLoginRequest
   */
  public function testValidateLoginRequestEmptyHost(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Host cannot be empty.');

    $this->service->validateLoginRequest(
      [],
      $this->createValidCredentialData(),
      'dGVzdC1jaGFsbGVuZ2U',
      'example.com',
      '',
      'preferred'
    );
  }

  /**
   * Tests validateLoginRequest with missing credential_id.
   *
   * @covers ::validateLoginRequest
   */
  public function testValidateLoginRequestMissingCredentialId(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Missing required credential field: credential_id');

    $credentialData = $this->createValidCredentialData();
    unset($credentialData['credential_id']);

    $this->service->validateLoginRequest(
      [],
      $credentialData,
      'dGVzdC1jaGFsbGVuZ2U',
      'example.com',
      'example.com',
      'preferred'
    );
  }

  /**
   * Tests validateLoginRequest with invalid public key format.
   *
   * @covers ::validateLoginRequest
   */
  public function testValidateLoginRequestInvalidPublicKey(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid public key format in credential data.');

    $credentialData = $this->createValidCredentialData();
    $credentialData['public_key'] = '!!!not-valid-base64!!!';

    $this->service->validateLoginRequest(
      [],
      $credentialData,
      'dGVzdC1jaGFsbGVuZ2U',
      'example.com',
      'example.com',
      'preferred'
    );
  }

  /**
   * Tests validateLoginRequest with empty public key after decode.
   *
   * @covers ::validateLoginRequest
   */
  public function testValidateLoginRequestEmptyPublicKey(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid public key format in credential data.');

    $credentialData = $this->createValidCredentialData();
    // Base64 encode of empty string.
    $credentialData['public_key'] = '';

    $this->service->validateLoginRequest(
      [],
      $credentialData,
      'dGVzdC1jaGFsbGVuZ2U',
      'example.com',
      'example.com',
      'preferred'
    );
  }

  /**
   * Tests validateLoginRequest with invalid sign counter.
   *
   * @covers ::validateLoginRequest
   */
  public function testValidateLoginRequestInvalidSignCounter(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid sign counter value.');

    $credentialData = $this->createValidCredentialData();
    $credentialData['sign_counter'] = -1;

    $this->service->validateLoginRequest(
      [],
      $credentialData,
      'dGVzdC1jaGFsbGVuZ2U',
      'example.com',
      'example.com',
      'preferred'
    );
  }

  /**
   * Tests validateLoginRequest with invalid transports format.
   *
   * @covers ::validateLoginRequest
   */
  public function testValidateLoginRequestInvalidTransports(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid transports format.');

    $credentialData = $this->createValidCredentialData();
    $credentialData['transports'] = 'not-json';

    $this->service->validateLoginRequest(
      [],
      $credentialData,
      'dGVzdC1jaGFsbGVuZ2U',
      'example.com',
      'example.com',
      'preferred'
    );
  }

  /**
   * Tests validateRegistrationRequest with empty challenge.
   *
   * @covers ::validateRegistrationRequest
   */
  public function testValidateRegistrationRequestEmptyChallenge(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Challenge cannot be empty.');

    $this->service->validateRegistrationRequest(
      [],
      'example.com',
      '',
      'example.com',
      'username',
      '1',
      'Display Name'
    );
  }

  /**
   * Tests validateRegistrationRequest with empty rpId.
   *
   * @covers ::validateRegistrationRequest
   */
  public function testValidateRegistrationRequestEmptyRpId(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Relying party ID cannot be empty.');

    $this->service->validateRegistrationRequest(
      [],
      '',
      'dGVzdC1jaGFsbGVuZ2U',
      'example.com',
      'username',
      '1',
      'Display Name'
    );
  }

  /**
   * Tests getRegistrationOptions without active request.
   *
   * @covers ::getRegistrationOptions
   */
  public function testGetRegistrationOptionsNoActiveRequest(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Cannot generate registration options: no active request.');

    $this->requestStack->method('getCurrentRequest')
      ->willReturn(NULL);

    $user = $this->createMock(UserInterface::class);
    $this->service->getRegistrationOptions($user, 'example.com');
  }

  /**
   * Tests getRegistrationOptions without session.
   *
   * @covers ::getRegistrationOptions
   */
  public function testGetRegistrationOptionsNoSession(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Cannot generate registration options: no active session.');

    $request = $this->createMock(Request::class);
    $request->method('hasSession')->willReturn(FALSE);
    $this->requestStack->method('getCurrentRequest')
      ->willReturn($request);

    $user = $this->createMock(UserInterface::class);
    $this->service->getRegistrationOptions($user, 'example.com');
  }

  /**
   * Tests getRegistrationOptions returns correct structure.
   *
   * @covers ::getRegistrationOptions
   */
  public function testGetRegistrationOptionsStructure(): void {
    $session = $this->createMock(SessionInterface::class);
    $session->expects($this->once())
      ->method('set')
      ->with(
        'wa_challenge',
        $this->callback(function ($value) {
          return is_array($value) &&
            isset($value['value']) &&
            isset($value['created']) &&
            strlen($value['value']) > 0;
        })
      );

    $request = $this->createMock(Request::class);
    $request->method('hasSession')->willReturn(TRUE);
    $request->method('getSession')->willReturn($session);
    $this->requestStack->method('getCurrentRequest')
      ->willReturn($request);

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(123);
    $user->method('getAccountName')->willReturn('testuser');
    $user->method('getDisplayName')->willReturn('Test User');

    $options = $this->service->getRegistrationOptions($user, 'example.com');

    $this->assertArrayHasKey('rp', $options);
    $this->assertArrayHasKey('user', $options);
    $this->assertArrayHasKey('challenge', $options);
    $this->assertArrayHasKey('pubKeyCredParams', $options);
    $this->assertArrayHasKey('timeout', $options);
    $this->assertArrayHasKey('attestation', $options);
    $this->assertArrayHasKey('authenticatorSelection', $options);

    $this->assertEquals('Web Authentication', $options['rp']['name']);
    $this->assertEquals('example.com', $options['rp']['id']);
    $this->assertEquals('testuser', $options['user']['name']);
    $this->assertEquals('Test User', $options['user']['displayName']);
    $this->assertEquals('none', $options['attestation']);
    $this->assertEquals(60000, $options['timeout']);
    // When resident_key is 'preferred', requireResidentKey MUST be FALSE.
    $this->assertSame('preferred', $options['authenticatorSelection']['residentKey']);
    $this->assertFalse($options['authenticatorSelection']['requireResidentKey']);
    $this->assertSame('preferred', $options['authenticatorSelection']['userVerification']);
    $this->assertArrayNotHasKey('excludeCredentials', $options);
  }

  /**
   * Tests getRegistrationOptions with required settings and excludeCredentials.
   *
   * @covers ::getRegistrationOptions
   */
  public function testGetRegistrationOptionsRequiredAndExcludeCredentials(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['resident_key', 'required'],
        ['user_verification', 'required'],
      ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('wa.settings')->willReturn($config);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(time());

    $session = $this->createMock(SessionInterface::class);
    $request = $this->createMock(Request::class);
    $request->method('hasSession')->willReturn(TRUE);
    $request->method('getSession')->willReturn($session);
    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn($request);

    $service = new WebAuthnService($requestStack, $configFactory, $time);

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(42);
    $user->method('getAccountName')->willReturn('alice');
    $user->method('getDisplayName')->willReturn('Alice');

    $existing = [
      [
        'credential_id' => "\x00\x01\x02raw-id",
        'transports' => json_encode(['internal', 'hybrid']),
      ],
    ];

    $options = $service->getRegistrationOptions($user, 'example.com', $existing);

    $this->assertSame('required', $options['authenticatorSelection']['residentKey']);
    $this->assertTrue($options['authenticatorSelection']['requireResidentKey']);
    $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
    $this->assertCount(1, $options['excludeCredentials']);
    $this->assertSame('public-key', $options['excludeCredentials'][0]['type']);
    $this->assertSame(['internal', 'hybrid'], $options['excludeCredentials'][0]['transports']);
  }

  /**
   * Tests that legacy 'discouraged' resident_key is clamped to 'preferred'.
   *
   * @covers ::getRegistrationOptions
   */
  public function testGetRegistrationOptionsClampsDiscouragedResidentKey(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['resident_key', 'discouraged'],
        ['user_verification', NULL],
      ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('wa.settings')->willReturn($config);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(time());

    $session = $this->createMock(SessionInterface::class);
    $request = $this->createMock(Request::class);
    $request->method('hasSession')->willReturn(TRUE);
    $request->method('getSession')->willReturn($session);
    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn($request);

    $service = new WebAuthnService($requestStack, $configFactory, $time);

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(7);
    $user->method('getAccountName')->willReturn('bob');
    $user->method('getDisplayName')->willReturn('Bob');

    $options = $service->getRegistrationOptions($user, 'example.com');
    $this->assertSame('preferred', $options['authenticatorSelection']['residentKey']);
    $this->assertFalse($options['authenticatorSelection']['requireResidentKey']);
    $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
  }

  /**
   * Tests that subdomain origins are strictly rejected during assertion checks.
   *
   * @covers ::validateLoginRequest
   * @covers ::validateAssertion
   */
  public function testSubdomainOriginRejectedOnLogin(): void {
    $challenge = rtrim(strtr(base64_encode('test-challenge-32-bytes-12345678'), '+/', '-_'), '=');
    $clientDataJson = rtrim(strtr(base64_encode(json_encode([
      'type' => 'webauthn.get',
      'challenge' => $challenge,
      'origin' => 'https://attacker.example.com',
    ])), '+/', '-_'), '=');
    $rpIdHash = hash('sha256', 'example.com', TRUE);
    // UP=1, UV=1 (0x05), signCount=1.
    $authData = rtrim(strtr(base64_encode($rpIdHash . chr(0x05) . pack('N', 1)), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(str_repeat("\x01", 64)), '+/', '-_'), '=');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Subdomains are not allowed.');

    $this->service->validateLoginRequest(
      [
        'id' => rtrim(strtr(base64_encode('test-credential-id'), '+/', '-_'), '='),
        'response' => [
          'clientDataJSON' => $clientDataJson,
          'authenticatorData' => $authData,
          'signature' => $signature,
          'userHandle' => rtrim(strtr(base64_encode('1'), '+/', '-_'), '='),
        ],
      ],
      $this->createValidCredentialData(),
      $challenge,
      'example.com',
      'https://example.com',
      'required'
    );
  }

  /**
   * Tests that subdomain origins and UV=0 are rejected during registration.
   *
   * @covers ::validateRegistrationRequest
   * @covers ::validateRegistration
   */
  public function testSubdomainOriginAndPresenceOnlyRejectedOnRegistration(): void {
    $challenge = rtrim(strtr(base64_encode('reg-challenge-32-bytes-123456789'), '+/', '-_'), '=');
    // Minimal valid CBOR map: {"fmt":"none","attStmt":{},"authData":<bytes>}
    // Build authData with rpIdHash (32B) + flags + counter (4B) +
    // attestedCredentialData (16B aaguid + 2B credIdLen=4 + 4B credId +
    // minimal CBOR key).
    $rpIdHash = hash('sha256', 'example.com', TRUE);
    // Minimal CBOR EC2 key map: {1:2, 3:-7, -1:1, -2:h'01..', -3:h'02..'}.
    $coseKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . str_repeat("\x01", 32) . "\x22\x58\x20" . str_repeat("\x02", 32);
    $attestedCredData = str_repeat("\x00", 16) . pack('n', 4) . 'cred' . $coseKey;

    // Helper to encode attestationObject CBOR map with given flags byte.
    $encodeAttestation = static function (int $flags) use ($rpIdHash, $attestedCredData): string {
      $authData = $rpIdHash . chr($flags) . pack('N', 0) . $attestedCredData;
      $len = strlen($authData);
      $bytesHeader = $len < 256 ? "\x58" . chr($len) : "\x59" . pack('n', $len);
      // 3-item map: "fmt":"none", "attStmt":{}, "authData":<bytes>.
      $cbor = "\xa3"
        . "\x63fmt\x64none"
        . "\x67attStmt\xa0"
        . "\x68authData" . $bytesHeader . $authData;
      return rtrim(strtr(base64_encode($cbor), '+/', '-_'), '=');
    };

    // 1. Subdomain origin must be rejected on registration
    // (AT=0x40|UP=0x01|UV=0x04 = 0x45).
    $subdomainClientData = rtrim(strtr(base64_encode(json_encode([
      'type' => 'webauthn.create',
      'challenge' => $challenge,
      'origin' => 'https://attacker.example.com',
    ])), '+/', '-_'), '=');

    try {
      $this->service->validateRegistrationRequest(
        [
          'response' => [
            'clientDataJSON' => $subdomainClientData,
            'attestationObject' => $encodeAttestation(0x45),
          ],
        ],
        'example.com',
        $challenge,
        'https://example.com',
        'alice',
        '1',
        'Alice',
        'required'
      );
      $this->fail('Expected subdomain origin to be rejected during registration.');
    }
    catch (\Throwable $e) {
      $this->assertStringContainsString('Subdomains are not allowed.', $e->getMessage());
    }

    // 2. Presence-only (AT=0x40|UP=0x01 = 0x41, UV=0) must be rejected when
    // user verification is required.
    $exactOriginClientData = rtrim(strtr(base64_encode(json_encode([
      'type' => 'webauthn.create',
      'challenge' => $challenge,
      'origin' => 'https://example.com',
    ])), '+/', '-_'), '=');

    $this->expectException(\Throwable::class);
    $this->expectExceptionMessage('User authentication required.');

    $this->service->validateRegistrationRequest(
      [
        'response' => [
          'clientDataJSON' => $exactOriginClientData,
          'attestationObject' => $encodeAttestation(0x41),
        ],
      ],
      'example.com',
      $challenge,
      'https://example.com',
      'alice',
      '1',
      'Alice',
      'required'
    );
  }

  /**
   * Tests getValidatorFactory returns a valid factory.
   *
   * @covers ::getValidatorFactory
   */
  public function testGetValidatorFactory(): void {
    $factory = $this->service->getValidatorFactory();
    // Verify the factory produces ceremony managers.
    // This test ensures the service properly initializes the factory.
    $creationCeremony = $factory->creationCeremony();
    $requestCeremony = $factory->requestCeremony();
    // If we get here without exceptions, the factory is working correctly.
    $this->addToAssertionCount(1);
  }

  /**
   * Creates valid PublicKeyCredentialCreationOptions for testing.
   */
  protected function createValidCreationOptions(): PublicKeyCredentialCreationOptions {
    return PublicKeyCredentialCreationOptions::create(
      PublicKeyCredentialRpEntity::create('Test', 'example.com'),
      PublicKeyCredentialUserEntity::create('test', '1', 'Test User'),
      random_bytes(32),
      [PublicKeyCredentialParameters::createPk(-7)]
    );
  }

  /**
   * Creates valid PublicKeyCredentialRequestOptions for testing.
   */
  protected function createValidRequestOptions(): PublicKeyCredentialRequestOptions {
    return PublicKeyCredentialRequestOptions::create(
      random_bytes(32),
      'example.com'
    );
  }

  /**
   * Creates valid credential record for testing.
   *
   * @return \Webauthn\CredentialRecord
   *   The credential record.
   */
  protected function createValidCredentialSource(): CredentialRecord {
    return CredentialRecord::create(
      publicKeyCredentialId: 'credential-id',
      type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
      transports: [],
      attestationType: 'none',
      trustPath: new EmptyTrustPath(),
      aaguid: Uuid::v4(),
      credentialPublicKey: 'public-key-data',
      userHandle: 'user-handle',
      counter: 0,
    );
  }

  /**
   * Creates valid credential data array for testing.
   *
   * @return array<string, mixed>
   *   The credential data.
   */
  protected function createValidCredentialData(): array {
    return [
      'credential_id' => 'test-credential-id',
      'public_key' => base64_encode('test-public-key'),
      'transports' => json_encode(['internal']),
      'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
      'user_handle' => '1',
      'sign_counter' => 0,
    ];
  }

}
