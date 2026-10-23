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
