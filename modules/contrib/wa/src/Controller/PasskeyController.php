<?php

namespace Drupal\wa\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Token;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\wa\Service\PasskeyLabeler;
use Drupal\wa\Service\WebAuthnService;
use Drupal\wa\Service\PasskeyTicketService;
use Drupal\wa\Service\SafeLoginFinalizer;
use Drupal\wa\WaPermissions;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Drupal\user\UserInterface;
use Drupal\user\RoleInterface;

/**
 * Controller for Passkey registration, authentication, and management.
 *
 * This controller handles the routes for:
 * - Listing and managing user passkeys.
 * - Generating options for WebAuthn registration and authentication.
 * - Verifying WebAuthn responses for registration and login.
 */
class PasskeyController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The passkey labeler service.
   *
   * @var \Drupal\wa\Service\PasskeyLabeler
   */
  protected $passkeyLabeler;

  /**
   * The WebAuthn service.
   *
   * @var \Drupal\wa\Service\WebAuthnService
   */
  protected $webAuthnService;

  /**
   * The ticket service.
   *
   * @var \Drupal\wa\Service\PasskeyTicketService
   */
  protected $ticketService;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * Constructs a PasskeyController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\wa\Service\PasskeyLabeler $passkey_labeler
   *   The passkey labeler service.
   * @param \Drupal\wa\Service\WebAuthnService $web_authn_service
   *   The WebAuthn service.
   * @param \Drupal\wa\Service\PasskeyTicketService $ticket_service
   *   The ticket service.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrf_token
   *   The CSRF token generator.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\wa\Service\SafeLoginFinalizer $safe_login_finalizer
   *   The safe login finalizer service.
   */
  public function __construct(Connection $database, TimeInterface $time, PasskeyLabeler $passkey_labeler, WebAuthnService $web_authn_service, PasskeyTicketService $ticket_service, CsrfTokenGenerator $csrf_token, FloodInterface $flood, MailManagerInterface $mail_manager, Token $token, ModuleHandlerInterface $module_handler, SafeLoginFinalizer $safe_login_finalizer) {
    $this->database = $database;
    $this->time = $time;
    $this->passkeyLabeler = $passkey_labeler;
    $this->webAuthnService = $web_authn_service;
    $this->ticketService = $ticket_service;
    $this->csrfToken = $csrf_token;
    $this->flood = $flood;
    $this->mailManager = $mail_manager;
    $this->token = $token;
    $this->moduleHandler = $module_handler;
    $this->safeLoginFinalizer = $safe_login_finalizer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('wa.passkey_labeler'),
      $container->get('wa.webauthn'),
      $container->get('wa.ticket_service'),
      $container->get('csrf_token'),
      $container->get('flood'),
      $container->get('plugin.manager.mail'),
      $container->get('token'),
      $container->get('module_handler'),
      $container->get('wa.safe_login_finalizer')
    );
  }

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The safe login finalizer.
   *
   * @var \Drupal\wa\Service\SafeLoginFinalizer
   */
  protected $safeLoginFinalizer;

  /**
   * Checks if a user has an allowed role for passkey operations.
   *
   * @param \Drupal\user\UserInterface|null $user
   *   The user entity to check. If NULL, the current user is loaded.
   *
   * @return bool
   *   TRUE if the user has an allowed role based on configuration rules, FALSE
   *   otherwise. Selecting the authenticated role allows all authenticated
   *   users; selecting no roles allows nobody.
   */
  protected function userHasAllowedRole(?UserInterface $user = NULL): bool {
    // Load current user if not provided.
    if ($user === NULL) {
      $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    }

    // Check if user is blocked.
    if ($user instanceof UserInterface && $user->isBlocked()) {
      return FALSE;
    }

    // User must be a valid entity.
    if (!$user instanceof UserInterface) {
      return FALSE;
    }

    $config = $this->config('wa.settings');
    $allowed_roles = $config->get('allowed_roles') ?? [];
    $allowed_roles = array_filter($allowed_roles);

    if (empty($allowed_roles)) {
      return FALSE;
    }

    // If authenticated role is selected, allow any authenticated user.
    if (in_array(RoleInterface::AUTHENTICATED_ID, $allowed_roles, TRUE)) {
      return $user->isAuthenticated();
    }

    $user_roles = $user->getRoles();
    return !empty(array_intersect($user_roles, $allowed_roles));
  }

  /**
   * Renders the Passkey management page.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity whose passkeys are being managed.
   *
   * @return array<string, mixed>
   *   A Drupal render array.
   */
  public function managePasskeys(UserInterface $user): array {
    $delete_path = ltrim(Url::fromRoute('wa.passkey_delete')->getInternalPath(), '/');
    $delete_token = $this->csrfToken->get($delete_path);
    $register_path = ltrim(Url::fromRoute('wa.register_options')->getInternalPath(), '/');
    $register_token = $this->csrfToken->get($register_path);
    $register_verify_path = ltrim(Url::fromRoute('wa.register_verify')->getInternalPath(), '/');
    $register_verify_token = $this->csrfToken->get($register_verify_path);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['passkey-management']],
      '#attached' => [
        'library' => ['wa/passkey'],
        'drupalSettings' => [
          'wa' => [
            'passkeyDeleteToken' => $delete_token,
            'registerOptionsToken' => $register_token,
            'registerVerifyToken' => $register_verify_token,
            'routes' => [
              'passkeyDelete' => Url::fromRoute('wa.passkey_delete')->toString(),
              'registerOptions' => Url::fromRoute('wa.register_options')->toString(),
              'registerVerify' => Url::fromRoute('wa.register_verify')->toString(),
            ],
          ],
        ],
      ],
    ];

    $build['title'] = [
      '#markup' => '<h2>' . $this->t('Manage Passkeys') . '</h2>',
    ];

    $build['description'] = [
      '#markup' => '<p>' . $this->t('Passkeys allow you to log in securely without a password.') . '</p>',
    ];

    // List existing passkeys.
    $header = [
      'authenticator' => $this->t('Authenticator'),
      'created' => $this->t('Created'),
      'last_used' => $this->t('Last Used'),
      'actions' => $this->t('Actions'),
    ];

    $rows = [];
    $query = $this->database->select('wa', 'c')
      ->fields('c', ['credential_id', 'created', 'last_used', 'aaguid'])
      ->condition('uid', $user->id())
      ->execute();

    foreach ($query as $record) {
      $credentialIdEncoded = base64_encode($record->credential_id);
      $rows[] = [
        $this->passkeyLabeler->getKeyTypeFromRecord($record),
        date('Y-m-d H:i', $record->created),
        date('Y-m-d H:i', $record->last_used),
        [
          'data' => [
            '#type' => 'button',
            '#value' => $this->t('Delete'),
            '#attributes' => [
              'class' => ['button', 'button--danger', 'passkey-delete'],
              'data-credential-id' => $credentialIdEncoded,
            ],
          ],
        ],
      ];
    }

    $build['passkey_management'] = [
      '#type' => 'details',
      '#title' => $this->t('Your Passkeys'),
      '#open' => TRUE,
    ];

    // Only show 'Add New Passkey' button
    // if viewing own page AND role is allowed.
    $canRegister = (int) $this->currentUser()->id() === (int) $user->id()
      && $this->userHasAllowedRole($user);

    if (empty($rows)) {
      if ($canRegister) {
        $build['passkey_management']['add_button'] = [
          '#type' => 'button',
          '#value' => $this->t('Add New Passkey'),
          '#attributes' => [
            'id' => 'register-passkey',
            'class' => ['button', 'button--primary'],
          ],
        ];
      }
      $build['passkey_management']['table'] = [
        '#markup' => '<p>' . $this->t('No passkeys registered yet.') . '</p>',
      ];
    }
    else {
      $build['passkey_management']['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No passkeys registered yet.'),
      ];
      if ($canRegister) {
        $build['passkey_management']['add_button'] = [
          '#type' => 'button',
          '#value' => $this->t('Add New Passkey'),
          '#attributes' => [
            'id' => 'register-passkey',
            'class' => ['button', 'button--primary'],
          ],
        ];
      }
    }

    $build['passkey_management']['status'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'passkey-status'],
    ];

    return $build;
  }

  /**
   * Provides registration options for passkey creation.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The registration options response.
   */
  public function registerOptions(Request $request) {
    // Flood control: limit registration option requests.
    $floodName = 'wa_register_options';
    $window = 300;
    $maxAttempts = 10;
    $ipIdentifier = 'ip:' . ($request->getClientIp() ?? 'unknown');

    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $ipIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many registration attempts. Please try again later.', 'Flood control blocked register options (IP)', [], 429);
    }

    $token = $request->headers->get('X-CSRF-Token');
    $path = ltrim(Url::fromRoute('wa.register_options')->getInternalPath(), '/');

    $config = $this->config('wa.settings');
    if ($config->get('enable_passkey_login') === FALSE) {
      return $this->ticketService->logAndReturnError('Passkey login is disabled.', 'Passkey login disabled check failed', [], 403);
    }

    $valid = $this->csrfToken->validate($token, $path);

    if (!$valid) {
      $this->flood->register($floodName, $window, $ipIdentifier);
      return $this->ticketService->logAndReturnError('Invalid CSRF token.', 'CSRF Fail: Token=@token, Path=@path', [
        '@token' => $token ?? '',
        '@path' => $path,
      ], 403);
    }

    if (!$this->userHasAllowedRole()) {
      return $this->ticketService->logAndReturnError('Passkey registration not permitted for your role.', 'Role check failed for registration', [], 403);
    }

    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$user instanceof UserInterface) {
      return $this->ticketService->logAndReturnError('Unable to process request.', 'User load failed for registration', [], 400);
    }
    $rpId = $request->getHost();

    $options = $this->webAuthnService->getRegistrationOptions($user, $rpId);

    return new JsonResponse($options);
  }

  /**
   * Persists a newly registered credential.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request containing the credential response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The verification result response.
   */
  public function registerVerify(Request $request) {
    $floodName = 'wa_register';
    // 1 hour
    $window = 3600;
    $maxAttempts = 5;
    $ipIdentifier = 'ip:' . ($request->getClientIp() ?? 'unknown');
    $userIdentifier = 'user:' . $this->currentUser()->id();

    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $ipIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many registration attempts. Please try again later.', 'Flood control blocked registration (IP)', [], 429);
    }
    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $userIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many registration attempts. Please try again later.', 'Flood control blocked registration (User)', [], 429);
    }

    try {
      $token = $request->headers->get('X-CSRF-Token');
      $path = ltrim(Url::fromRoute('wa.register_verify')->getInternalPath(), '/');

      $valid = $this->csrfToken->validate($token, $path);

      if (!$valid) {
        return $this->ticketService->logAndReturnError('Invalid CSRF token.', 'CSRF Fail on verify: Token=@token, Path=@path', [
          '@token' => $token ?? '',
          '@path' => $path,
        ], 403);
      }

      $config = $this->config('wa.settings');
      if ($config->get('enable_passkey_login') === FALSE) {
        return $this->ticketService->logAndReturnError('Passkey login is disabled.', 'Passkey login disabled check failed during verify', [], 403);
      }

      if (!$this->userHasAllowedRole()) {
        return $this->ticketService->logAndReturnError('Passkey registration not permitted for your role.', 'Role check failed for registration verify', [], 403);
      }

      $content = json_decode($request->getContent(), TRUE);
      // Current user entity.
      $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
      if (!$user instanceof UserInterface) {
        throw new \Exception('User not found.');
      }
      $rpId = $request->getHost();
      $challengeData = $request->getSession()->get('wa_challenge');

      if (empty($challengeData) || !is_array($challengeData)) {
        throw new \Exception('No challenge found in session.');
      }

      // Validate challenge TTL (2 minutes max).
      $challengeTtl = 120;
      if (!isset($challengeData['created']) || ($this->time->getCurrentTime() - $challengeData['created']) > $challengeTtl) {
        throw new \Exception('Challenge expired. Please try again.');
      }

      $challenge = $challengeData['value'] ?? '';

      // Reconstruct creation options for validation.
      $publicKeyCredentialSource = $this->webAuthnService->validateRegistrationRequest(
        $content,
        $rpId,
        $challenge,
        $request->getHost(),
        $user->getAccountName(),
        (string) $user->id(),
        $user->getDisplayName()
      );

      $aaguid = strtolower($publicKeyCredentialSource->aaguid->toRfc4122());
      if (!$this->passkeyLabeler->isAaguidAllowed($aaguid)) {
        return $this->ticketService->logAndReturnError('This authenticator is not allowed for passkey login.', 'Passkey registration blocked for disallowed AAGUID: @aaguid', ['@aaguid' => $aaguid], 403);
      }

      $credentialHash = hash('sha256', $publicKeyCredentialSource->publicKeyCredentialId);

      // Check for duplicate credential ID.
      $exists = $this->database->select('wa', 'c')
        ->fields('c', ['id'])
        ->condition('credential_hash', $credentialHash)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($exists) {
        throw new \Exception('This passkey is already registered.');
      }

      // Store in DB.
      $timestamp = $this->time->getRequestTime();
      $this->database->insert('wa')
        ->fields([
          'uid' => $this->currentUser()->id(),
          'credential_id' => $publicKeyCredentialSource->publicKeyCredentialId,
          // Store as base64 for safe storage.
          'public_key' => base64_encode($publicKeyCredentialSource->credentialPublicKey),
          'credential_hash' => $credentialHash,
          'user_handle' => $publicKeyCredentialSource->userHandle,
          'sign_counter' => $publicKeyCredentialSource->counter,
          'transports' => json_encode($publicKeyCredentialSource->transports),
          'aaguid' => $publicKeyCredentialSource->aaguid->toRfc4122(),
          'created' => $timestamp,
          'last_used' => $timestamp,
        ])
        ->execute();

      $encodedCredentialId = base64_encode($publicKeyCredentialSource->publicKeyCredentialId);
      $maskedCredentialId = '****' . substr($encodedCredentialId, -6);
      $this->getLogger('wa')->info('Passkey added for user @uid. Credential ID: @credential_id, AAGUID: @aaguid.', [
        '@uid' => $this->currentUser()->id(),
        '@credential_id' => $maskedCredentialId,
        '@aaguid' => $publicKeyCredentialSource->aaguid->toRfc4122(),
      ]);

      // Send notification email.
      $module = 'wa';
      $key = 'passkey_add_notification';
      $to = $user->getEmail();
      $langcode = $user->getPreferredLangcode();
      $params['context'] = [
        'user' => $user,
        'wa_passkey' => [
          'aaguid' => $publicKeyCredentialSource->aaguid->toRfc4122(),
        ],
      ];
      $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, TRUE);

      return new JsonResponse(['status' => 'ok']);
    }
    catch (\Throwable $e) {
      $this->flood->register($floodName, $window, $ipIdentifier);
      $this->flood->register($floodName, $window, $userIdentifier);

      return $this->ticketService->logAndReturnError('Passkey registration failed.', 'Passkey registration failed: @message', ['@message' => $e->getMessage()], 400);
    }
    finally {
      $request->getSession()->remove('wa_challenge');
    }
  }

  /**
   * Deletes a stored passkey for the current user (or admin).
   */
  public function deletePasskey(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token');
    $path = ltrim(Url::fromRoute('wa.passkey_delete')->getInternalPath(), '/');

    if (!$this->csrfToken->validate($token, $path)) {
      return $this->ticketService->logAndReturnError('Invalid CSRF token.', 'CSRF Fail on delete: Token=@token, Path=@path', [
        '@token' => $token ?? '',
        '@path' => $path,
      ], 403);
    }

    $data = json_decode($request->getContent(), TRUE) ?? [];
    $credentialId = $data['id'] ?? $request->request->get('id');

    if (empty($credentialId)) {
      return $this->ticketService->logAndReturnError('Missing credential id', 'Missing credential id for deletion', [], 400);
    }

    $canAdminister = $this->currentUser()->hasPermission(WaPermissions::ADMINISTER_ALL_USER_PASSKEY);
    if (!$canAdminister) {
      // Check if user has allowed role for API endpoint consistency.
      if (!$this->userHasAllowedRole()) {
        return $this->ticketService->logAndReturnError('Deletion not permitted.', 'Role check failed for deletion', [], 403);
      }
    }

    $current_uid = (int) $this->currentUser()->id();

    // Decode base64-encoded credential ID from UI.
    // The database now stores credential IDs as raw binary.
    $decodedCredentialId = base64_decode($credentialId, TRUE);
    if ($decodedCredentialId === FALSE || $decodedCredentialId === '') {
      return $this->ticketService->logAndReturnError('Invalid credential id format', 'Invalid credential id format for deletion', [], 400);
    }

    // Fetch record details before deletion so we can log the owner correctly.
    $query = $this->database->select('wa', 'c')
      ->fields('c', ['aaguid', 'uid'])
      ->condition('credential_id', $decodedCredentialId);
    $result = $query->execute()->fetchAssoc();
    $target_uid = $result ? (int) $result['uid'] : NULL;
    $aaguid = $result ? $result['aaguid'] : 'Unknown';

    // Delete for owner.
    $deleted = $this->deleteByCredentialId($decodedCredentialId, $current_uid);

    // Allow administrators with permission to remove any credential.
    if (!$deleted && $this->currentUser()->hasPermission(WaPermissions::ADMINISTER_ALL_USER_PASSKEY)) {
      $deleted = $this->deleteByCredentialId($decodedCredentialId);
    }

    if ($deleted) {
      $this->getLogger('wa')->info('Passkey deleted for user @uid. Credential ID: @credential_id, AAGUID: @aaguid.', [
        '@credential_id' => $credentialId,
        '@uid' => $target_uid ?? $current_uid,
        '@aaguid' => $aaguid,
      ]);
      return new JsonResponse(['status' => 'ok']);
    }

    return $this->ticketService->logAndReturnError('Deletion failed or unauthorized', 'Deletion failed or unauthorized', [], 403);
  }

  /**
   * Deletes a credential by its ID.
   *
   * @param string $credentialId
   *   The raw binary credential ID to delete.
   * @param int|null $uid
   *   Optional user ID to restrict deletion to a specific user.
   *
   * @return int
   *   Number of credentials deleted (0 or 1).
   */
  protected function deleteByCredentialId(string $credentialId, ?int $uid = NULL): int {
    if ($credentialId === '') {
      return 0;
    }

    $query = $this->database->delete('wa')
      ->condition('credential_id', $credentialId);

    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }

    return (int) $query->execute();
  }

  /**
   * Provides login options for passkey authentication.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The login options response.
   */
  public function loginOptions(Request $request) {
    $floodName = 'wa_login_options';
    $window = 300;
    $maxAttempts = 10;
    $ipIdentifier = 'ip:' . ($request->getClientIp() ?? 'unknown');

    // Check the Origin/Referer header.
    $config = $this->config('wa.settings');
    if ($config->get('enable_passkey_login') === FALSE) {
      return $this->ticketService->logAndReturnError('Passkey login is disabled.', 'Passkey login disabled check failed (login options)', [], 403);
    }

    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $ipIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many login attempts. Please try again later.', 'Flood control blocked login options (IP)', [], 429);
    }

    // Enforce X-Requested-With header to prevent CSRF.
    // This works because browsers do not allow cross-site requests to set
    // custom headers unless explicitly allowed by CORS.
    if (!$request->isXmlHttpRequest()) {
      return $this->ticketService->logAndReturnError('Invalid request.', 'Missing X-Requested-With header (CSRF check)', [], 403);
    }

    $origin = $request->headers->get('Origin');
    $referer = $request->headers->get('Referer');
    $host = $request->getHost();

    // Allow if Origin matches host (standard for CORS/fetch).
    // If Origin is missing, check Referer.
    $valid_origin = FALSE;
    if ($origin) {
      $parsed_origin = parse_url($origin);
      if (isset($parsed_origin['host']) && $parsed_origin['host'] === $host) {
        $valid_origin = TRUE;
      }
    }
    elseif ($referer) {
      $parsed_referer = parse_url($referer);
      if (isset($parsed_referer['host']) && $parsed_referer['host'] === $host) {
        $valid_origin = TRUE;
      }
    }

    if (!$valid_origin) {
      return $this->ticketService->logAndReturnError('Invalid Origin or Referer.', 'Invalid Origin or Referer', [], 403);
    }

    $rpId = $request->getHost();
    $challenge = random_bytes(32);
    $challengeEncoded = Base64UrlSafe::encodeUnpadded($challenge);
    $request->getSession()->set('wa_challenge', [
      'value' => $challengeEncoded,
      'created' => $this->time->getCurrentTime(),
    ]);

    $userVerification = $config->get('user_verification') ?? 'preferred';

    $options = [
      'challenge' => $challengeEncoded,
      'timeout' => 60000,
      'rpId' => $rpId,
      'userVerification' => $userVerification,
    ];

    return new JsonResponse($options);
  }

  /**
   * Validates a passkey assertion and logs the user in.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request containing the assertion response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The verification result response or a redirect.
   */
  public function loginVerify(Request $request) {
    $floodName = 'wa_login';
    $window = 900;
    $maxAttempts = 5;
    $ipIdentifier = 'ip:' . ($request->getClientIp() ?? 'unknown');
    $credentialIdentifier = NULL;
    $registerFloodOnFailure = TRUE;
    $credentialId = '';
    $uid = 0;

    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $ipIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many login attempts. Please try again later.', 'Flood control blocked login verify (IP)', [], 429);
    }

    // Enforce X-Requested-With header to prevent CSRF.
    if (!$request->isXmlHttpRequest()) {
      return $this->ticketService->logAndReturnError('Invalid request.', 'Missing X-Requested-With header on loginVerify (CSRF check)', [], 403);
    }

    // Validate Origin or Referer header.
    $origin = $request->headers->get('Origin');
    $referer = $request->headers->get('Referer');
    $host = $request->getHost();
    $valid_origin = FALSE;
    if ($origin) {
      $parsed_origin = parse_url($origin);
      if (isset($parsed_origin['host']) && $parsed_origin['host'] === $host) {
        $valid_origin = TRUE;
      }
    }
    elseif ($referer) {
      $parsed_referer = parse_url($referer);
      if (isset($parsed_referer['host']) && $parsed_referer['host'] === $host) {
        $valid_origin = TRUE;
      }
    }

    if (!$valid_origin) {
      return $this->ticketService->logAndReturnError('Invalid Origin or Referer.', 'Invalid Origin or Referer on loginVerify', [], 403);
    }

    try {
      $config = $this->config('wa.settings');
      if ($config->get('enable_passkey_login') === FALSE) {
        return $this->ticketService->logAndReturnError('Passkey login is disabled.', 'Passkey login disabled check failed (login verify)', [], 403);
      }

      $content = json_decode($request->getContent(), TRUE);
      $credentialId = $content['id'] ?? '';
      if ($credentialId === '') {
        throw new \Exception('Missing credential id.');
      }

      $credentialIdentifier = 'cred:' . hash('sha256', $credentialId);
      if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $credentialIdentifier)) {
        return $this->ticketService->logAndReturnError('Too many login attempts. Please try again later.', 'Flood control blocked login verify (Credential)', [], 429);
      }

      // Decode base64url-encoded credential ID from client.
      // The database stores credential IDs as raw binary.
      $decodedCredentialId = '';
      try {
        $decodedCredentialId = Base64UrlSafe::decodeNoPadding($credentialId);
      }
      catch (\Throwable $e) {
        throw new \Exception('Invalid credential id format.');
      }
      if ($decodedCredentialId === '') {
        throw new \Exception('Invalid credential id format.');
      }

      $challengeData = $request->getSession()->get('wa_challenge');

      if (empty($challengeData) || !is_array($challengeData)) {
        throw new \Exception('No challenge found in session.');
      }

      // Validate challenge TTL (2 minutes max).
      $challengeTtl = 120;
      if (!isset($challengeData['created']) || ($this->time->getCurrentTime() - $challengeData['created']) > $challengeTtl) {
        throw new \Exception('Challenge expired. Please try again.');
      }

      $challenge = $challengeData['value'] ?? '';

      // Find user by credential ID.
      $query = $this->database->select('wa', 'c')
        ->fields('c', ['uid', 'credential_id', 'public_key', 'user_handle', 'sign_counter', 'transports', 'aaguid'])
        ->condition('credential_id', $decodedCredentialId);

      // If the client provided the user handle (user ID), use it
      // to narrow down the search.
      $userHandleEncoded = $content['response']['userHandle'] ?? '';
      $enforce_user_handle = $config->get('enforce_user_handle');

      // Check if we must enforce user handle presence.
      if ($enforce_user_handle && $userHandleEncoded === '') {
        throw new \Exception('User handle is required by policy.');
      }

      if ($userHandleEncoded !== '') {
        $userHandle = '';
        try {
          $userHandle = Base64UrlSafe::decodeNoPadding($userHandleEncoded);
        }
        catch (\Throwable $e) {
          if ($enforce_user_handle) {
            // When enforcement is enabled, reject malformed user handles.
            throw new \Exception('User handle decode failed.');
          }
          // When enforcement is disabled, ignore invalid user handle encoding.
          // This is just an optimization hint, so we fall back to the standard
          // lookup by credential_id.
        }

        if ($userHandle !== '') {
          // Validate that the decoded user handle is a positive integer UID.
          // The user handle is set to the Drupal UID during registration
          // (see WebAuthnService::getRegistrationOptions()). Without this
          // validation, a non-numeric string would be silently coerced to 0
          // by the database layer, bypassing the UID narrowing entirely.
          if (!ctype_digit($userHandle) || (int) $userHandle <= 0) {
            throw new \Exception('Invalid user handle format.');
          }
          // Add uid condition with AND logic.
          $query->condition('uid', (int) $userHandle);
        }
        elseif ($enforce_user_handle) {
          // If we must enforce user handle presence,
          // The user ID must be matched.
          throw new \Exception('User handle is required by policy.');
        }
      }

      $statement = $query->execute();

      $result = $statement->fetchAssoc();

      if (!$result) {
        throw new \Exception('Credential not found.');
      }

      // Load and validate user BEFORE expensive WebAuthn validation
      // to avoid wasted computation for blocked/invalid users.
      $uid = (int) $result['uid'];
      $user = $this->entityTypeManager()->getStorage('user')->load($uid);

      if (!$user instanceof UserInterface) {
        throw new \Exception('User not found or invalid.');
      }

      // Check if the user is blocked or disallowed before WebAuthn validation.
      if ($user->isBlocked()) {
        throw new \Exception('User account is blocked.');
      }

      if (!$this->userHasAllowedRole($user)) {
        throw new \Exception('Passkey login not permitted for this account.');
      }

      if (!$this->passkeyLabeler->isAaguidAllowed(strtolower((string) $result['aaguid']))) {
        throw new \Exception('This authenticator is not allowed for passkey login. AAGUID: ' . $result['aaguid']);
      }

      // Validate assertion via WebAuthn service.
      $storedCredentialId = $result['credential_id'];
      $storedPublicKey = $result['public_key'] ?? '';

      $publicKeyCredentialSource = NULL;
      $validated = $this->webAuthnService->validateLoginRequest(
        $content,
        [
          'credential_id' => $storedCredentialId,
          'transports' => $result['transports'] ?? '[]',
          'aaguid' => $result['aaguid'],
          'public_key' => $storedPublicKey,
          'user_handle' => $result['user_handle'],
          'sign_counter' => $result['sign_counter'],
        ],
        $challenge,
        // RP ID (domain is usually correct for RP ID).
        $request->getHost(),
        // Expected Origin (must include scheme).
        $request->getSchemeAndHttpHost(),
        $config->get('user_verification') ?? 'preferred',
        $publicKeyCredentialSource
      );
      if ($validated !== TRUE || !$publicKeyCredentialSource) {
        throw new \Exception('Passkey validation failed.');
      }

      // Update counter and last used.
      $this->database->update('wa')
        ->fields([
          'last_used' => $this->time->getRequestTime(),
          'sign_counter' => $publicKeyCredentialSource->counter,
        ])
        ->condition('credential_id', $storedCredentialId)
        ->execute();

      // Ensure we have a valid user ID before logging in.
      // Note: $credentialId is already validated to be non-empty at
      // line 647-650.
      if ($uid === 0) {
        throw new \Exception('Invalid login state: Missing user ID.');
      }

      // Allow modules to react to passkey login and potentially redirect.
      $allow_login = TRUE;
      $redirect_url = NULL;

      $results = $this->moduleHandler->invokeAll('wa_login', [$user]);
      foreach ($results as $result) {
        if (isset($result['allowed_login']) && $result['allowed_login'] === FALSE) {
          $allow_login = FALSE;
          if (!empty($result['redirect_url'])) {
            $redirect_url = $result['redirect_url'];
          }
          break;
        }
        if (!empty($result['redirect_url'])) {
          $redirect_url = $result['redirect_url'];
        }
      }

      if ($redirect_url && $redirect_url instanceof Url) {
        $redirect_url = $redirect_url->toString();
      }
      // Validate redirect_url from hook is a safe local path.
      $originalRedirectUrl = $redirect_url;
      if ($redirect_url && is_string($redirect_url)) {
        try {
          $redirect_url = Url::fromUserInput($redirect_url)->toString();
        }
        catch (\Throwable $e) {
          $redirect_url = NULL;
        }
      }
      else {
        $redirect_url = NULL;
      }

      if (!$allow_login) {
        // If login is not allowed, check for redirect URL.
        if ($redirect_url) {
          $this->getLogger('wa')->info('Passkey login prevented by external module for user @uid. Redirecting user.', [
            '@uid' => $uid,
          ]);
          return new LocalRedirectResponse($redirect_url);
        }
        // If the hook provided an invalid redirect URL, return a ticketed
        // error so the issue can be traced.
        if ($originalRedirectUrl !== NULL) {
          return $this->ticketService->logAndReturnError(
            'Login prevented. Invalid redirect configuration.',
            'Login blocked by hook for user @uid with invalid redirect URL: @url',
            ['@uid' => $uid, '@url' => (string) $originalRedirectUrl],
            403
          );
        }
        // No redirect URL provided at all.
        return $this->ticketService->logAndReturnError(
          'Login prevented by site policy.',
          'Login blocked by external module for user @uid, no redirect URL provided.',
          ['@uid' => $uid],
          403
        );
      }

      // Regenerate session ID before login to prevent session fixation.
      // This ensures any pre-authentication session ID is invalidated.
      $request->getSession()->migrate(TRUE);

      // Finalize login with redirect interception.
      // The service handles: stripping ?destination, ob_start() buffering,
      // Location header interception, and candidate validation.
      $finalRedirectUrl = $this->safeLoginFinalizer->finalizeLogin(
        $user,
        $request,
        [$redirect_url]
      );

      $maskedCredentialId = '****' . substr($credentialId, -6);
      $this->getLogger('wa')->info('User logged in with passkey. User: @uid, Credential ID: @credential_id.', [
        '@uid' => $uid,
        '@credential_id' => $maskedCredentialId,
      ]);

      $registerFloodOnFailure = FALSE;

      return $this->safeLoginFinalizer->createRedirectResponse($finalRedirectUrl, $uid);
    }
    catch (EnforcedResponseException $e) {
      // If a module forces a response (like a redirect),
      // Clean buffer if it was started.
      if (ob_get_level()) {
        ob_end_clean();
      }

      // Extract the redirect URL if possible.
      $response = $e->getResponse();

      // Only log success if the user successfully logged in.
      if ($this->currentUser()->isAuthenticated()) {
        $maskedCredentialId = '****' . substr($credentialId, -6);
        $this->getLogger('wa')->info('User logged in with passkey (EnforcedResponseException caught). User: @uid, Credential ID: @credential_id.', [
          '@uid' => $uid,
          '@credential_id' => $maskedCredentialId,
        ]);
      }
      else {
        $url = $response instanceof RedirectResponse ? $response->getTargetUrl() : 'unknown';
        $this->getLogger('wa')->warning('User login failed (EnforcedResponseException caught), redirect to @URL', [
          '@URL' => $url,
        ]);
      }

      // Validate the redirect URL if it's a RedirectResponse.
      // Modules like login_destination may force external redirects that
      // break AJAX (CORS errors). Validate through Url::fromUserInput().
      if ($response instanceof RedirectResponse) {
        $targetUrl = $response->getTargetUrl();
        try {
          $safeUrl = Url::fromUserInput($targetUrl)->toString();
          return new LocalRedirectResponse($safeUrl);
        }
        catch (\Throwable $e) {
          $this->getLogger('wa')->warning('EnforcedResponseException redirect rejected (external URL): @url for user @uid', [
            '@url' => $targetUrl,
            '@uid' => $uid,
          ]);
          // Fall through to safe default below.
        }
      }

      // If login was successful, redirect to user page.
      if ($this->currentUser()->isAuthenticated()) {
        return new LocalRedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser()->id()])->toString());
      }

      // Login failed or was incomplete, return error.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Login failed or incomplete.',
      ], 400);
    }
    catch (\Throwable $e) {
      // Clean buffer if it wasn't cleaned.
      if (ob_get_level()) {
        ob_end_clean();
      }

      if ($registerFloodOnFailure) {
        $this->flood->register($floodName, $window, $ipIdentifier);
        // We do NOT register flood events for the credential or user identifier
        // on generic failures (like signature mismatch) to prevent DoS attacks.
        // The cryptographic strength of the signature is sufficient protection
        // against brute-force attacks on the key itself.
      }

      return $this->ticketService->logAndReturnError('Passkey login failed.', 'Passkey login failed: @message', ['@message' => $e->getMessage()], 400);
    }
    finally {
      // Clear the challenge to enforce one-time use.
      $request->getSession()->remove('wa_challenge');
    }
  }

}
