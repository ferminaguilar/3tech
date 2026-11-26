<?php

namespace Drupal\wa\Service;

use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorDataLoader;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CollectedClientData;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;
use Symfony\Component\Uid\Uuid;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialParameters;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for WebAuthn operations.
 */
class WebAuthnService {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The validator factory.
   *
   * @var \Webauthn\CeremonyStep\CeremonyStepManagerFactory
   */
  protected $validatorFactory;

  /**
   * The attestation object loader.
   *
   * @var \Webauthn\AttestationStatement\AttestationObjectLoader
   */
  protected $attestationObjectLoader;

  /**
   * Constructs a WebAuthnService object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(RequestStack $request_stack, ConfigFactoryInterface $config_factory, TimeInterface $time) {
    $this->requestStack = $request_stack;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->validatorFactory = $this->createValidatorFactory();
    // Create a reusable attestation object loader.
    $attestationStatementSupportManager = new AttestationStatementSupportManager([
      new NoneAttestationStatementSupport(),
    ]);
    $this->attestationObjectLoader = AttestationObjectLoader::create($attestationStatementSupportManager);
  }

  /**
   * Creates a CeremonyStepManagerFactory configured for strict origin checks.
   *
   * @param string|null $expectedOrigin
   *   The exact allowed origin (scheme://host[:port]), or NULL to fall back to
   *   the current request's scheme and HTTP host when available.
   *
   * @return \Webauthn\CeremonyStep\CeremonyStepManagerFactory
   *   A configured ceremony step manager factory.
   */
  protected function createValidatorFactory(?string $expectedOrigin = NULL): CeremonyStepManagerFactory {
    $factory = new CeremonyStepManagerFactory();
    if ($expectedOrigin === NULL) {
      $request = $this->requestStack->getCurrentRequest();
      $expectedOrigin = $request ? $request->getSchemeAndHttpHost() : '';
    }
    if ($expectedOrigin !== '') {
      // Strictly match the expected origin without allowing arbitrary
      // subdomains (CheckAllowedOrigins with allowSubdomains: FALSE).
      $factory->setAllowedOrigins([$expectedOrigin], FALSE);
    }
    return $factory;
  }

  /**
   * Creates a configured CeremonyStepManagerFactory.
   */
  public function getValidatorFactory(?string $expectedOrigin = NULL): CeremonyStepManagerFactory {
    if ($expectedOrigin !== NULL) {
      return $this->createValidatorFactory($expectedOrigin);
    }
    return $this->createValidatorFactory();
  }

  /**
   * Decodes a Base64 URL-encoded string.
   *
   * @param string $data
   *   The Base64 URL-encoded string to decode.
   *
   * @return string
   *   The decoded binary data.
   *
   * @throws \InvalidArgumentException
   *   If the data cannot be decoded as Base64 URL.
   */
  private function decodeBase64Url(string $data): string {
    if ($data === '') {
      throw new \InvalidArgumentException('Cannot decode empty string.');
    }
    try {
      return Base64UrlSafe::decodeNoPadding($data);
    }
    catch (\Throwable $e) {
      throw new \InvalidArgumentException('Invalid Base64 URL encoding: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Validates a registration response.
   *
   * @param array<string, mixed> $content
   *   The client-provided registration payload.
   * @param \Webauthn\PublicKeyCredentialCreationOptions $options
   *   The credential creation options.
   * @param string $host
   *   The current request host.
   *
   * @return \Webauthn\CredentialRecord
   *   The validated credential record.
   */
  public function validateRegistration(array $content, PublicKeyCredentialCreationOptions $options, string $host): CredentialRecord {
    // Validate required fields exist.
    if (!isset($content['response'])) {
      throw new \Exception('Missing response field in registration data.');
    }
    $requiredFields = ['clientDataJSON', 'attestationObject'];
    foreach ($requiredFields as $field) {
      if (!isset($content['response'][$field])) {
        throw new \Exception("Missing required registration field: {$field}");
      }
    }

    // Load the response using the reusable attestation object loader.
    // Decode Base64 encoded input fields.
    $clientDataJSONDecoded = $this->decodeBase64Url($content['response']['clientDataJSON']);
    $attestationObjectRaw = $this->decodeBase64Url($content['response']['attestationObject']);

    $clientDataArray = json_decode($clientDataJSONDecoded, TRUE);
    if (!is_array($clientDataArray)) {
      throw new \Exception('Invalid clientDataJSON: not a valid JSON string.');
    }
    $clientDataJSON = CollectedClientData::create($clientDataJSONDecoded, $clientDataArray);

    // AttestationObjectLoader expects Base64 encoded string.
    // Since tryDecodeOrRaw returns the binary data
    // (whether input was Base64 or Raw),
    // we must re-encode it to Base64 to satisfy the loader.
    $attestationObjectEncoded = Base64UrlSafe::encodeUnpadded($attestationObjectRaw);

    try {
      $attestationObject = $this->attestationObjectLoader->load($attestationObjectEncoded);
    }
    catch (\Throwable $e) {
      throw new \Exception('Invalid attestationObject: ' . $e->getMessage());
    }

    $authenticatorAttestationResponse = AuthenticatorAttestationResponse::create(
      $clientDataJSON,
      $attestationObject,
      $content['response']['transports'] ?? []
    );

    // Validate using strict per-origin CheckAllowedOrigins (no subdomains).
    $validator = AuthenticatorAttestationResponseValidator::create(
      $this->createValidatorFactory($host)->creationCeremony()
    );

    return $validator->check(
      $authenticatorAttestationResponse,
      $options,
      $host
    );
  }

  /**
   * Builds and validates registration options from request data.
   *
   * @param array<string, mixed> $content
   *   The client-provided registration payload.
   * @param string $rpId
   *   The relying party ID.
   * @param string $challenge
   *   The base64url-encoded challenge.
   * @param string $host
   *   The expected origin (scheme://host[:port]) or host.
   * @param string $username
   *   The account name.
   * @param string $userId
   *   The user ID as string.
   * @param string $displayName
   *   The display name.
   * @param string|null $userVerificationRequirement
   *   Optional user verification requirement ('required' or 'preferred').
   *   Defaults to the configured wa.settings value (or 'required').
   *
   * @return \Webauthn\CredentialRecord
   *   The validated credential record.
   */
  public function validateRegistrationRequest(array $content, string $rpId, string $challenge, string $host, string $username, string $userId, string $displayName, ?string $userVerificationRequirement = NULL): CredentialRecord {
    // Validate critical parameters are not empty.
    if (empty($challenge)) {
      throw new \Exception('Challenge cannot be empty.');
    }
    if (empty($rpId)) {
      throw new \Exception('Relying party ID cannot be empty.');
    }
    if (empty($host)) {
      throw new \Exception('Host cannot be empty.');
    }
    if (empty($userId)) {
      throw new \Exception('User ID cannot be empty.');
    }

    $config = $this->configFactory->get('wa.settings');
    $uvSetting = $userVerificationRequirement ?? $config->get('user_verification') ?? 'required';
    $userVerification = $uvSetting === 'preferred'
      ? AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED
      : AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED;

    $residentKeySetting = $config->get('resident_key') ?? 'required';
    $residentKey = $residentKeySetting === 'preferred'
      ? AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
      : AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED;

    $authenticatorSelection = AuthenticatorSelectionCriteria::create(
      authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
      userVerification: $userVerification,
      residentKey: $residentKey,
    );

    $publicKeyCredentialCreationOptions = PublicKeyCredentialCreationOptions::create(
      PublicKeyCredentialRpEntity::create('Web Authentication', $rpId),
      PublicKeyCredentialUserEntity::create(
        $username,
        $userId,
        $displayName
      ),
      $this->decodeBase64Url($challenge),
      [
        // ES256.
        PublicKeyCredentialParameters::createPk(-7),
        // RS256.
        PublicKeyCredentialParameters::createPk(-257),
      ],
      $authenticatorSelection,
      PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
      [],
      60000
    );

    return $this->validateRegistration(
      $content,
      $publicKeyCredentialCreationOptions,
      $host
    );
  }

  /**
   * Validates a login assertion.
   *
   * @param array<string, mixed> $content
   *   The client-provided WebAuthn assertion payload.
   * @param \Webauthn\PublicKeyCredentialRequestOptions $options
   *   The credential request options.
   * @param \Webauthn\CredentialRecord $source
   *   The stored credential record.
   * @param string $host
   *   The current request host.
   * @param string|null $userHandle
   *   The user handle from the assertion.
   *
   * @return \Webauthn\CredentialRecord
   *   The validated credential record.
   */
  public function validateAssertion(array $content, PublicKeyCredentialRequestOptions $options, CredentialRecord $source, string $host, ?string $userHandle): CredentialRecord {
    // Validate required fields exist.
    if (!isset($content['response'])) {
      throw new \Exception('Missing response field in assertion data.');
    }
    $requiredFields = ['clientDataJSON', 'authenticatorData', 'signature'];
    foreach ($requiredFields as $field) {
      if (!isset($content['response'][$field])) {
        throw new \Exception("Missing required assertion field: {$field}");
      }
    }

    // Decode and validate Base64 data.
    $authenticatorDataDecoded = $this->decodeBase64Url($content['response']['authenticatorData']);
    if ($authenticatorDataDecoded === '') {
      throw new \Exception('Invalid authenticatorData.');
    }

    $signatureDecoded = $this->decodeBase64Url($content['response']['signature']);
    if ($signatureDecoded === '') {
      throw new \Exception('Invalid signature.');
    }

    // Decode userHandle (optional field).
    $userHandleEncoded = $content['response']['userHandle'] ?? '';
    $userHandleDecoded = '';
    if ($userHandleEncoded !== '') {
      $userHandleDecoded = $this->decodeBase64Url($userHandleEncoded);
    }

    // Create response object.
    $clientDataJSONDecoded = $this->decodeBase64Url($content['response']['clientDataJSON']);
    $clientDataArray = json_decode($clientDataJSONDecoded, TRUE);
    if (!is_array($clientDataArray)) {
      throw new \Exception('Invalid clientDataJSON: not a valid JSON string.');
    }
    $clientDataJSON = CollectedClientData::create($clientDataJSONDecoded, $clientDataArray);

    try {
      $authenticatorData = AuthenticatorDataLoader::create()->load($authenticatorDataDecoded);
    }
    catch (\Throwable $e) {
      throw new \Exception('Invalid authenticatorData: ' . $e->getMessage());
    }
    $authenticatorAssertionResponse = AuthenticatorAssertionResponse::create(
      $clientDataJSON,
      $authenticatorData,
      $signatureDecoded,
      $userHandleDecoded
    );

    // Validate using strict per-origin CheckAllowedOrigins (no subdomains).
    $validator = AuthenticatorAssertionResponseValidator::create(
      $this->createValidatorFactory($host)->requestCeremony()
    );

    return $validator->check(
      $source,
      $authenticatorAssertionResponse,
      $options,
      $host,
      $userHandle
    );
  }

  /**
   * Generates registration options.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user account.
   * @param string $rpId
   *   The relying party ID.
   * @param array<int, array{credential_id: string, transports?: string|array<int, string>|null}> $existingCredentials
   *   Existing stored credentials for this user to include in
   *   excludeCredentials so authenticators do not overwrite an existing
   *   discoverable credential and orphan the previous row.
   *
   * @return array<string, mixed>
   *   The registration options array.
   */
  public function getRegistrationOptions(UserInterface $user, string $rpId, array $existingCredentials = []): array {
    // Validate we have an active request with session.
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      throw new \Exception('Cannot generate registration options: no active request.');
    }
    if (!$request->hasSession()) {
      throw new \Exception('Cannot generate registration options: no active session.');
    }

    // Generate random challenge with timestamp for TTL validation.
    $challenge = random_bytes(32);
    $challengeEncoded = Base64UrlSafe::encodeUnpadded($challenge);
    $request->getSession()->set('wa_challenge', [
      'value' => $challengeEncoded,
      'created' => $this->time->getCurrentTime(),
    ]);

    $config = $this->configFactory->get('wa.settings');
    $residentKeyConfig = $config->get('resident_key') ?? 'required';
    // Only 'required' and 'preferred' are compatible with usernameless login;
    // clamp any legacy 'discouraged' setting to 'preferred'.
    $residentKey = $residentKeyConfig === 'preferred' || $residentKeyConfig === 'discouraged'
      ? 'preferred'
      : 'required';

    $userVerificationConfig = $config->get('user_verification') ?? 'required';
    $userVerification = $userVerificationConfig === 'preferred' ? 'preferred' : 'required';

    $excludeCredentials = [];
    foreach ($existingCredentials as $credential) {
      $rawId = $credential['credential_id'];
      if ($rawId === '') {
        continue;
      }
      $descriptor = [
        'type' => 'public-key',
        'id' => Base64UrlSafe::encodeUnpadded($rawId),
      ];
      $transports = $credential['transports'] ?? [];
      if (is_string($transports) && $transports !== '') {
        $decoded = json_decode($transports, TRUE);
        $transports = is_array($decoded) ? $decoded : [];
      }
      if (is_array($transports) && !empty($transports)) {
        $descriptor['transports'] = array_values(array_filter($transports, 'is_string'));
      }
      $excludeCredentials[] = $descriptor;
    }

    // Simplified options generation.
    $options = [
      'rp' => [
        'name' => 'Web Authentication',
        'id' => $rpId,
      ],
      'user' => [
        'id' => Base64UrlSafe::encodeUnpadded((string) $user->id()),
        'name' => $user->getAccountName(),
        'displayName' => $user->getDisplayName(),
      ],
      'challenge' => $challengeEncoded,
      'pubKeyCredParams' => [
        // ES256.
        ['type' => 'public-key', 'alg' => -7],
        // RS256.
        ['type' => 'public-key', 'alg' => -257],
      ],
      'timeout' => 60000,
      'attestation' => 'none',
      'authenticatorSelection' => [
        'residentKey' => $residentKey,
        // Per WebAuthn Level 2/3 §5.4.4, requireResidentKey MUST be true if
        // and only if residentKey is 'required'.
        'requireResidentKey' => $residentKey === 'required',
        'userVerification' => $userVerification,
      ],
    ];

    if (!empty($excludeCredentials)) {
      $options['excludeCredentials'] = $excludeCredentials;
    }

    return $options;
  }

  /**
   * Validates a login request using stored credential data.
   *
   * @param array<string, mixed> $content
   *   The client-provided WebAuthn assertion payload.
   * @param array<string, mixed> $credentialData
   *   The credential data fetched from storage (must include credential_id,
   *   transports, aaguid, public_key, user_handle, sign_counter).
   * @param string $challenge
   *   The base64url-encoded challenge from the session.
   * @param string $rpId
   *   The relying party ID (usually the request host).
   * @param string $host
   *   The current request host.
   * @param string $userVerificationRequirement
   *   The user verification requirement ('preferred' or 'required').
   * @param \Webauthn\CredentialRecord|null $validatedSource
   *   Populated with the validated credential record on success.
   *
   * @return bool
   *   TRUE on successful validation; otherwise, an exception is thrown.
   */
  public function validateLoginRequest(array $content, array $credentialData, string $challenge, string $rpId, string $host, string $userVerificationRequirement, ?CredentialRecord &$validatedSource = NULL): bool {
    // Validate critical parameters are not empty.
    if (empty($challenge)) {
      throw new \Exception('Challenge cannot be empty.');
    }
    if (empty($rpId)) {
      throw new \Exception('Relying party ID cannot be empty.');
    }
    if (empty($host)) {
      throw new \Exception('Host cannot be empty.');
    }
    if (empty($userVerificationRequirement)) {
      throw new \Exception('User verification requirement cannot be empty.');
    }

    // Validate required credential data fields.
    $requiredFields = ['credential_id', 'public_key', 'transports', 'aaguid', 'user_handle', 'sign_counter'];
    foreach ($requiredFields as $field) {
      if (!isset($credentialData[$field])) {
        throw new \Exception("Missing required credential field: {$field}");
      }
    }

    // Validate credential_id is not empty.
    $storedCredentialId = $credentialData['credential_id'];
    if (empty($storedCredentialId)) {
      throw new \Exception('Credential ID cannot be empty.');
    }

    // Decode and validate public key.
    $storedPublicKey = $credentialData['public_key'];
    $decodedPublicKey = base64_decode($storedPublicKey, TRUE);
    if ($decodedPublicKey === FALSE || $decodedPublicKey === '') {
      throw new \Exception('Invalid public key format in credential data.');
    }

    // Validate AAGUID format.
    try {
      $uuid = Uuid::fromString($credentialData['aaguid']);
    }
    catch (\Throwable $e) {
      throw new \Exception('Invalid AAGUID format: ' . $e->getMessage());
    }

    // Validate sign counter.
    $signCounter = $credentialData['sign_counter'];
    if (!is_numeric($signCounter) || $signCounter < 0) {
      throw new \Exception('Invalid sign counter value.');
    }
    $signCounter = (int) $signCounter;

    // Validate and decode transports.
    $transports = json_decode($credentialData['transports'], TRUE);
    if (!is_array($transports)) {
      throw new \Exception('Invalid transports format.');
    }

    // Validate user_handle format.
    $userHandle = $credentialData['user_handle'];
    if (!is_string($userHandle)) {
      throw new \Exception('User handle must be a string.');
    }

    // Use named arguments so any future upstream parameter reordering fails
    // loudly instead of silently mis-binding security-critical fields.
    $publicKeyCredentialSource = CredentialRecord::create(
      publicKeyCredentialId: $storedCredentialId,
      type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
      transports: $transports,
      // Assumed attestation type.
      attestationType: 'none',
      trustPath: new EmptyTrustPath(),
      aaguid: $uuid,
      credentialPublicKey: $decodedPublicKey,
      userHandle: $userHandle,
      counter: $signCounter,
    );

    // Decode and validate challenge.
    $challengeBinary = $this->decodeBase64Url($challenge);
    if ($challengeBinary === '') {
      throw new \Exception('Invalid challenge.');
    }

    $allowCredentials = [
      PublicKeyCredentialDescriptor::create(
        PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
        $storedCredentialId,
        $transports
      ),
    ];

    $userVerification = $userVerificationRequirement === 'required'
      ? PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED
      : PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED;

    $timeout = 60000;

    $publicKeyCredentialRequestOptions = PublicKeyCredentialRequestOptions::create(
      $challengeBinary,
      $rpId,
      $allowCredentials,
      $userVerification,
      $timeout
    );

    try {
      $validatedSource = $this->validateAssertion(
        $content,
        $publicKeyCredentialRequestOptions,
        $publicKeyCredentialSource,
        $host,
        $userHandle
      );
    }
    catch (\Throwable $e) {
      // Throw a more descriptive error for logging.
      throw new \Exception('WebAuthn validation failed: ' . $e->getMessage(), 0, $e);
    }

    return TRUE;
  }

}
