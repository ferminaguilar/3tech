<?php

namespace Drupal\wa\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Url;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Drupal\wa\Service\LoginPolicyEvaluator;
use Drupal\wa\Service\PasskeyLabeler;
use Drupal\wa\Service\PasskeyTicketService;
use Drupal\wa\Service\SafeLoginFinalizer;
use Drupal\wa\Service\WebAuthnService;
use Drupal\wa\WaPermissions;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Passkey registration, authentication, and management.
 *
 * This controller handles the routes for:
 * - Listing and managing user passkeys.
 * - Re-authenticating before registering new passkeys.
 * - Generating options for WebAuthn registration and authentication.
 * - Verifying WebAuthn responses for registration and login.
 */
class PasskeyController extends ControllerBase {

  /**
   * Duration in seconds for which a registration re-authentication stays valid.
   */
  public const REAUTH_TTL = 300;

  /**
   * Session key for storing recent registration re-authentication state.
   */
  public const REAUTH_SESSION_KEY = 'wa_reauth';

  /**
   * Session key tracking completed step-up modules in a multi-step login flow.
   */
  public const COMPLETED_STEP_UPS_SESSION_KEY = 'wa_completed_step_ups';

  /**
   * Session key for storing a passkey re-authentication WebAuthn challenge.
   */
  public const REAUTH_CHALLENGE_SESSION_KEY = 'wa_reauth_challenge';

  /**
   * Session key for storing an email verification code for SSO-only re-auth.
   */
  public const REAUTH_EMAIL_SESSION_KEY = 'wa_reauth_email_code';

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
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The login policy evaluator.
   *
   * @var \Drupal\wa\Service\LoginPolicyEvaluator
   */
  protected LoginPolicyEvaluator $loginPolicyEvaluator;

  /**
   * The safe login finalizer.
   *
   * @var \Drupal\wa\Service\SafeLoginFinalizer
   */
  protected $safeLoginFinalizer;

  /**
   * The password hasher.
   *
   * @var \Drupal\Core\Password\PasswordInterface
   */
  protected PasswordInterface $passwordHasher;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

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
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\wa\Service\LoginPolicyEvaluator $login_policy_evaluator
   *   The login policy evaluator service.
   * @param \Drupal\wa\Service\SafeLoginFinalizer $safe_login_finalizer
   *   The safe login finalizer service.
   * @param \Drupal\Core\Password\PasswordInterface $password_hasher
   *   The password hashing service.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    Connection $database,
    TimeInterface $time,
    PasskeyLabeler $passkey_labeler,
    WebAuthnService $web_authn_service,
    PasskeyTicketService $ticket_service,
    FloodInterface $flood,
    MailManagerInterface $mail_manager,
    LoginPolicyEvaluator $login_policy_evaluator,
    SafeLoginFinalizer $safe_login_finalizer,
    PasswordInterface $password_hasher,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
  ) {
    $this->database = $database;
    $this->time = $time;
    $this->passkeyLabeler = $passkey_labeler;
    $this->webAuthnService = $web_authn_service;
    $this->ticketService = $ticket_service;
    $this->flood = $flood;
    $this->mailManager = $mail_manager;
    $this->loginPolicyEvaluator = $login_policy_evaluator;
    $this->safeLoginFinalizer = $safe_login_finalizer;
    $this->passwordHasher = $password_hasher;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
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
      $container->get('flood'),
      $container->get('plugin.manager.mail'),
      $container->get('wa.login_policy_evaluator'),
      $container->get('wa.safe_login_finalizer'),
      $container->get('password'),
      $container->get('cache_tags.invalidator')
    );
  }

  /**
   * Checks whether passkey login is enabled (fail-closed).
   *
   * @return bool
   *   TRUE only when enable_passkey_login is explicitly truthy.
   */
  protected function isPasskeyLoginEnabled(): bool {
    return (bool) $this->config('wa.settings')->get('enable_passkey_login');
  }

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
    $allowed_roles = array_filter(is_array($allowed_roles) ? $allowed_roles : []);

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
   * Checks whether the current session has a recent re-authentication marker.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\user\UserInterface $user
   *   The authenticated user attempting passkey registration.
   *
   * @return bool
   *   TRUE if the session has a valid, non-expired re-auth record for $user.
   */
  protected function hasValidReauth(Request $request, UserInterface $user): bool {
    if (!$request->hasSession()) {
      return FALSE;
    }
    $reauth = $request->getSession()->get(self::REAUTH_SESSION_KEY);
    if (!is_array($reauth)) {
      return FALSE;
    }
    $uid = $reauth['uid'] ?? NULL;
    $timestamp = $reauth['time'] ?? NULL;
    if (!is_int($uid) || !is_int($timestamp)) {
      return FALSE;
    }
    if ($uid !== (int) $user->id()) {
      return FALSE;
    }
    return ($this->time->getCurrentTime() - $timestamp) <= self::REAUTH_TTL;
  }

  /**
   * Checks whether Origin or Referer matches the current request host.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return bool
   *   TRUE when Origin (or fallback Referer) matches the request host.
   */
  protected function hasValidOriginOrReferer(Request $request): bool {
    $origin = $request->headers->get('Origin');
    $referer = $request->headers->get('Referer');
    $host = $request->getHost();

    if ($origin) {
      $parsed = parse_url($origin);
      return is_array($parsed) && isset($parsed['host']) && $parsed['host'] === $host;
    }
    if ($referer) {
      $parsed = parse_url($referer);
      return is_array($parsed) && isset($parsed['host']) && $parsed['host'] === $host;
    }
    return FALSE;
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
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['passkey-management']],
      '#attached' => [
        'library' => ['wa/passkey'],
        'drupalSettings' => [
          'wa' => [
            'routes' => [
              'csrfToken' => Url::fromRoute('system.csrftoken')->toString(),
              'passkeyDelete' => Url::fromRoute('wa.passkey_delete')->toString(),
              'registerReauth' => Url::fromRoute('wa.register_reauth')->toString(),
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

    // List existing passkeys (including transports so PasskeyLabeler can
    // classify internal/platform authenticators consistently with Views).
    $header = [
      'authenticator' => $this->t('Authenticator'),
      'created' => $this->t('Created'),
      'last_used' => $this->t('Last Used'),
      'actions' => $this->t('Actions'),
    ];

    $rows = [];
    $query = $this->database->select('wa', 'c')
      ->fields('c', ['credential_id', 'created', 'last_used', 'aaguid', 'transports'])
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

    // Only allow adding new passkeys when viewing own page, feature is enabled,
    // and the user's role is in allowed_roles.
    $canRegister = (int) $this->currentUser()->id() === (int) $user->id()
      && $this->isPasskeyLoginEnabled()
      && $this->userHasAllowedRole($user);

    $reauthSection = NULL;
    if ($canRegister) {
      $hasPassword = is_string($user->getPassword()) && $user->getPassword() !== '';
      $hasExistingPasskeys = !empty($rows);

      $reauthSection = [
        '#type' => 'container',
        '#attributes' => ['class' => ['wa-reauth-controls']],
      ];

      if ($hasPassword) {
        $reauthSection['current_password'] = [
          '#type' => 'password',
          '#title' => $this->t('Current password'),
          '#title_display' => 'before',
          '#description' => $this->t('Confirm your current password before adding a new passkey.'),
          '#description_display' => 'after',
          '#attributes' => [
            'id' => 'wa-reauth-password',
            'autocomplete' => 'current-password',
          ],
        ];
      }

      if ($hasExistingPasskeys) {
        $reauthSection['verify_passkey'] = [
          '#type' => 'button',
          '#value' => $this->t('Confirm with Existing Passkey'),
          '#attributes' => [
            'id' => 'wa-reauth-passkey',
            'class' => ['button'],
          ],
        ];
      }

      // SSO-only accounts with neither a local password nor an existing
      // passkey can confirm their identity via a one-time email code.
      $hasEmail = is_string($user->getEmail()) && $user->getEmail() !== '';
      if (!$hasPassword && !$hasExistingPasskeys && $hasEmail) {
        $reauthSection['send_email_code'] = [
          '#type' => 'button',
          '#value' => $this->t('Send Verification Code to Email'),
          '#attributes' => [
            'id' => 'wa-reauth-email-send',
            'class' => ['button'],
          ],
        ];
        $reauthSection['email_code'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Email verification code'),
          '#title_display' => 'before',
          '#description' => $this->t('Enter the 6-digit verification code sent to your email address before adding a passkey.'),
          '#description_display' => 'after',
          '#attributes' => [
            'id' => 'wa-reauth-email-code',
            'autocomplete' => 'one-time-code',
            'inputmode' => 'numeric',
          ],
        ];
      }

      $reauthSection['add_button'] = [
        '#type' => 'button',
        '#value' => $this->t('Add New Passkey'),
        '#attributes' => [
          'id' => 'register-passkey',
          'class' => ['button', 'button--primary'],
        ],
      ];
    }

    if (empty($rows)) {
      if ($reauthSection !== NULL) {
        $build['passkey_management']['add_passkey'] = $reauthSection;
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
      if ($reauthSection !== NULL) {
        $build['passkey_management']['add_passkey'] = $reauthSection;
      }
    }

    $build['passkey_management']['status'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'passkey-status'],
    ];

    return $build;
  }

  /**
   * Re-authenticates the current user before registering a new passkey.
   *
   * Accepts either the user's current password (`method: "password"`) or a
   * fresh WebAuthn assertion from an existing passkey with user verification
   * required (`method: "passkey_options"` followed by `method: "passkey"`).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request containing the re-authentication payload.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating re-auth status or WebAuthn options.
   */
  public function registerReauth(Request $request): JsonResponse {
    if (!$this->isPasskeyLoginEnabled()) {
      return $this->ticketService->logAndReturnError('Passkey login is disabled.', 'Passkey login disabled check failed (reauth)', [], 403, LogLevel::WARNING);
    }

    $floodName = 'wa_register_reauth';
    $window = 900;
    $maxAttempts = 5;
    $ipIdentifier = 'ip:' . ($request->getClientIp() ?? 'unknown');
    $userIdentifier = 'user:' . $this->currentUser()->id();

    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $ipIdentifier)
      || !$this->flood->isAllowed($floodName, $maxAttempts, $window, $userIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many verification attempts. Please try again later.', 'Flood control blocked registration re-authentication', [], 429, LogLevel::WARNING);
    }

    if (!$this->userHasAllowedRole()) {
      return $this->ticketService->logAndReturnError('Passkey registration not permitted for your role.', 'Role check failed for registration re-authentication', [], 403, LogLevel::WARNING);
    }

    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$user instanceof UserInterface) {
      return $this->ticketService->logAndReturnError('Unable to process request.', 'User load failed for registration re-authentication', [], 400);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      $this->flood->register($floodName, $window, $ipIdentifier);
      $this->flood->register($floodName, $window, $userIdentifier);
      return $this->ticketService->logAndReturnError('Invalid verification request.', 'Invalid JSON payload for registration re-authentication', [], 400, LogLevel::WARNING);
    }

    $method = $data['method'] ?? 'password';

    if ($method === 'password') {
      $password = $data['password'] ?? '';
      $storedHash = $user->getPassword();
      if (!is_string($password) || $password === '' || !is_string($storedHash) || $storedHash === ''
        || !$this->passwordHasher->check($password, $storedHash)) {
        $this->flood->register($floodName, $window, $ipIdentifier);
        $this->flood->register($floodName, $window, $userIdentifier);
        return $this->ticketService->logAndReturnError('Current password verification failed.', 'Password re-authentication failed for user @uid', ['@uid' => $user->id()], 403, LogLevel::WARNING);
      }

      $request->getSession()->set(self::REAUTH_SESSION_KEY, [
        'uid' => (int) $user->id(),
        'time' => $this->time->getCurrentTime(),
      ]);
      $this->flood->clear($floodName, $ipIdentifier);
      $this->flood->clear($floodName, $userIdentifier);

      return new JsonResponse(['status' => 'ok']);
    }

    if ($method === 'passkey_options') {
      $credentials = $this->loadUserCredentials((int) $user->id());

      if (empty($credentials)) {
        return $this->ticketService->logAndReturnError('No existing passkeys available for verification.', 'No existing passkeys for passkey re-authentication for user @uid', ['@uid' => $user->id()], 400, LogLevel::WARNING);
      }

      $allowCredentials = [];
      foreach ($credentials as $row) {
        $descriptor = [
          'type' => 'public-key',
          'id' => Base64UrlSafe::encodeUnpadded($row['credential_id']),
        ];
        $decodedTransports = !empty($row['transports']) ? json_decode($row['transports'], TRUE) : [];
        if (is_array($decodedTransports) && !empty($decodedTransports)) {
          $descriptor['transports'] = array_values(array_filter($decodedTransports, 'is_string'));
        }
        $allowCredentials[] = $descriptor;
      }

      $challengeEncoded = Base64UrlSafe::encodeUnpadded(random_bytes(32));
      $request->getSession()->set(self::REAUTH_CHALLENGE_SESSION_KEY, [
        'value' => $challengeEncoded,
        'created' => $this->time->getCurrentTime(),
        'uid' => (int) $user->id(),
      ]);

      return new JsonResponse([
        'challenge' => $challengeEncoded,
        'timeout' => 60000,
        'rpId' => $request->getHost(),
        'userVerification' => 'required',
        'allowCredentials' => $allowCredentials,
      ]);
    }

    if ($method === 'passkey') {
      try {
        $challengeData = $request->getSession()->get(self::REAUTH_CHALLENGE_SESSION_KEY);
        if (empty($challengeData) || !is_array($challengeData) || (int) ($challengeData['uid'] ?? 0) !== (int) $user->id()) {
          throw new \Exception('No re-authentication challenge found in session.');
        }
        if (!isset($challengeData['created']) || ($this->time->getCurrentTime() - $challengeData['created']) > 120) {
          throw new \Exception('Re-authentication challenge expired.');
        }

        $credential = $data['credential'] ?? NULL;
        if (!is_array($credential) || empty($credential['id']) || !is_string($credential['id'])) {
          throw new \Exception('Missing credential response.');
        }

        $decodedCredentialId = Base64UrlSafe::decodeNoPadding($credential['id']);
        if ($decodedCredentialId === '') {
          throw new \Exception('Invalid credential id format.');
        }
        $credentialHash = hash('sha256', $decodedCredentialId);

        $record = $this->database->select('wa', 'c')
          ->fields('c', ['credential_id', 'public_key', 'user_handle', 'sign_counter', 'transports', 'aaguid'])
          ->condition('credential_hash', $credentialHash)
          ->condition('uid', $user->id())
          ->execute()
          ->fetchAssoc();

        if (!$record || !hash_equals((string) $record['credential_id'], $decodedCredentialId)) {
          throw new \Exception('Existing passkey not found for current user.');
        }

        $validatedSource = NULL;
        $validated = $this->webAuthnService->validateLoginRequest(
          $credential,
          [
            'credential_id' => $record['credential_id'],
            'transports' => $record['transports'] ?? '[]',
            'aaguid' => $record['aaguid'],
            'public_key' => $record['public_key'] ?? '',
            'user_handle' => $record['user_handle'],
            'sign_counter' => $record['sign_counter'],
          ],
          (string) ($challengeData['value'] ?? ''),
          $request->getHost(),
          $request->getSchemeAndHttpHost(),
          'required',
          $validatedSource
        );

        if ($validated !== TRUE || !$validatedSource) {
          throw new \Exception('Passkey verification failed.');
        }

        $this->database->update('wa')
          ->fields([
            'last_used' => $this->time->getRequestTime(),
            'sign_counter' => $validatedSource->counter,
          ])
          ->condition('credential_hash', $credentialHash)
          ->execute();

        $request->getSession()->set(self::REAUTH_SESSION_KEY, [
          'uid' => (int) $user->id(),
          'time' => $this->time->getCurrentTime(),
        ]);
        $this->flood->clear($floodName, $ipIdentifier);
        $this->flood->clear($floodName, $userIdentifier);

        return new JsonResponse(['status' => 'ok']);
      }
      catch (\Throwable $e) {
        $this->flood->register($floodName, $window, $ipIdentifier);
        $this->flood->register($floodName, $window, $userIdentifier);
        return $this->ticketService->logAndReturnError('Passkey verification failed.', 'Passkey re-authentication failed: @message', ['@message' => $e->getMessage()], 403, LogLevel::WARNING);
      }
      finally {
        $request->getSession()->remove(self::REAUTH_CHALLENGE_SESSION_KEY);
      }
    }

    // Email verification fallback for SSO-only accounts with neither a local
    // password nor an existing passkey.
    if ($method === 'email_send') {
      $hasPassword = is_string($user->getPassword()) && $user->getPassword() !== '';
      $hasExistingPasskeys = !empty($this->loadUserCredentials((int) $user->id()));
      if ($hasPassword || $hasExistingPasskeys) {
        return $this->ticketService->logAndReturnError('Please confirm with your password or existing passkey.', 'Email re-auth requested by account with password or passkey (uid @uid)', ['@uid' => $user->id()], 400, LogLevel::WARNING);
      }

      $email = $user->getEmail();
      if (!is_string($email) || $email === '') {
        return $this->ticketService->logAndReturnError('No email address is configured on this account.', 'Email re-auth unavailable: missing email for user @uid', ['@uid' => $user->id()], 400, LogLevel::WARNING);
      }

      $sendFloodName = 'wa_reauth_email_send';
      if (!$this->flood->isAllowed($sendFloodName, 5, 3600, $ipIdentifier)
        || !$this->flood->isAllowed($sendFloodName, 5, 3600, $userIdentifier)) {
        return $this->ticketService->logAndReturnError('Too many verification emails requested. Please try again later.', 'Flood control blocked re-auth email send for user @uid', ['@uid' => $user->id()], 429, LogLevel::WARNING);
      }

      $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
      $request->getSession()->set(self::REAUTH_EMAIL_SESSION_KEY, [
        'hash' => password_hash($code, PASSWORD_DEFAULT),
        'created' => $this->time->getCurrentTime(),
        'uid' => (int) $user->id(),
      ]);

      $result = $this->mailManager->mail('wa', 'passkey_reauth_code', $email, $user->getPreferredLangcode(), [
        'code' => $code,
        'user' => $user,
      ], NULL, TRUE);

      if (($result['result'] ?? FALSE) !== TRUE) {
        $request->getSession()->remove(self::REAUTH_EMAIL_SESSION_KEY);
        return $this->ticketService->logAndReturnError('Unable to send verification code.', 'Failed to send passkey re-auth code email for user @uid', ['@uid' => $user->id()], 500);
      }

      $this->flood->register($sendFloodName, 3600, $ipIdentifier);
      $this->flood->register($sendFloodName, 3600, $userIdentifier);
      return new JsonResponse(['status' => 'ok']);
    }

    if ($method === 'email_verify') {
      $code = trim((string) ($data['code'] ?? ''));
      $stored = $request->getSession()->get(self::REAUTH_EMAIL_SESSION_KEY);

      $isValid = $code !== ''
        && is_array($stored)
        && (int) ($stored['uid'] ?? 0) === (int) $user->id()
        && isset($stored['created'], $stored['hash'])
        && is_string($stored['hash'])
        && ($this->time->getCurrentTime() - (int) $stored['created']) <= self::REAUTH_TTL
        && password_verify($code, $stored['hash']);

      if (!$isValid) {
        $this->flood->register($floodName, $window, $ipIdentifier);
        $this->flood->register($floodName, $window, $userIdentifier);
        return $this->ticketService->logAndReturnError('Invalid or expired verification code.', 'Email code re-authentication failed for user @uid', ['@uid' => $user->id()], 403, LogLevel::WARNING);
      }

      $request->getSession()->remove(self::REAUTH_EMAIL_SESSION_KEY);
      $request->getSession()->set(self::REAUTH_SESSION_KEY, [
        'uid' => (int) $user->id(),
        'time' => $this->time->getCurrentTime(),
      ]);
      $this->flood->clear($floodName, $ipIdentifier);
      $this->flood->clear($floodName, $userIdentifier);

      return new JsonResponse(['status' => 'ok']);
    }

    return $this->ticketService->logAndReturnError('Unsupported verification method.', 'Unsupported re-authentication method: @method', ['@method' => (string) $method], 400, LogLevel::WARNING);
  }

  /**
   * Provides registration options for passkey creation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The registration options response.
   */
  public function registerOptions(Request $request) {
    if (!$this->isPasskeyLoginEnabled()) {
      return $this->ticketService->logAndReturnError('Passkey login is disabled.', 'Passkey login disabled check failed', [], 403, LogLevel::WARNING);
    }

    // Flood control: limit registration option requests.
    $floodName = 'wa_register_options';
    $window = 300;
    $maxAttempts = 10;
    $ipIdentifier = 'ip:' . ($request->getClientIp() ?? 'unknown');
    $userIdentifier = 'user:' . $this->currentUser()->id();

    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $ipIdentifier)
      || !$this->flood->isAllowed($floodName, $maxAttempts, $window, $userIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many registration attempts. Please try again later.', 'Flood control blocked register options', [], 429, LogLevel::WARNING);
    }

    $this->flood->register($floodName, $window, $ipIdentifier);
    $this->flood->register($floodName, $window, $userIdentifier);

    if (!$this->userHasAllowedRole()) {
      return $this->ticketService->logAndReturnError('Passkey registration not permitted for your role.', 'Role check failed for registration', [], 403, LogLevel::WARNING);
    }

    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$user instanceof UserInterface) {
      return $this->ticketService->logAndReturnError('Unable to process request.', 'User load failed for registration', [], 400);
    }

    if (!$this->hasValidReauth($request, $user)) {
      return $this->ticketService->logAndReturnError(
        'Please confirm your identity before adding a passkey.',
        'Re-authentication required before registration options for user @uid',
        ['@uid' => $user->id()],
        403,
        LogLevel::NOTICE,
        ['reauth_required' => TRUE]
      );
    }

    // Include existing credentials in excludeCredentials so re-registering the
    // same authenticator does not silently overwrite an on-device credential.
    $existingCredentials = $this->loadUserCredentials((int) $user->id());

    $rpId = $request->getHost();
    $options = $this->webAuthnService->getRegistrationOptions($user, $rpId, $existingCredentials);

    return new JsonResponse($options);
  }

  /**
   * Loads stored credential IDs and transports for a user (D10 & D11 safe).
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array<int, array{credential_id: string, transports: string|null}>
   *   List of associative arrays with raw binary credential_id and transports.
   */
  protected function loadUserCredentials(int $uid): array {
    $statement = $this->database->select('wa', 'c')
      ->fields('c', ['credential_id', 'transports'])
      ->condition('uid', $uid)
      ->execute();

    $credentials = [];
    while ($row = $statement->fetchAssoc()) {
      $credentials[] = [
        'credential_id' => (string) $row['credential_id'],
        'transports' => is_string($row['transports']) ? $row['transports'] : NULL,
      ];
    }

    return $credentials;
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
    if (!$this->isPasskeyLoginEnabled()) {
      return $this->ticketService->logAndReturnError('Passkey login is disabled.', 'Passkey login disabled check failed during verify', [], 403, LogLevel::WARNING);
    }

    $floodName = 'wa_register';
    // 1 hour.
    $window = 3600;
    $maxAttempts = 5;
    $ipIdentifier = 'ip:' . ($request->getClientIp() ?? 'unknown');
    $userIdentifier = 'user:' . $this->currentUser()->id();

    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $ipIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many registration attempts. Please try again later.', 'Flood control blocked registration (IP)', [], 429, LogLevel::WARNING);
    }
    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $userIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many registration attempts. Please try again later.', 'Flood control blocked registration (User)', [], 429, LogLevel::WARNING);
    }

    try {
      if (!$this->userHasAllowedRole()) {
        return $this->ticketService->logAndReturnError('Passkey registration not permitted for your role.', 'Role check failed for registration verify', [], 403, LogLevel::WARNING);
      }

      $content = json_decode($request->getContent(), TRUE);
      // Current user entity.
      $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
      if (!$user instanceof UserInterface) {
        throw new \Exception('User not found.');
      }

      if (!$this->hasValidReauth($request, $user)) {
        return $this->ticketService->logAndReturnError(
          'Please confirm your identity before adding a passkey.',
          'Re-authentication required before registration verify for user @uid',
          ['@uid' => $user->id()],
          403,
          LogLevel::NOTICE,
          ['reauth_required' => TRUE]
        );
      }

      $rpId = $request->getHost();
      $expectedOrigin = $request->getSchemeAndHttpHost();
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

      // Reconstruct creation options for validation with strict origin check.
      $publicKeyCredentialSource = $this->webAuthnService->validateRegistrationRequest(
        is_array($content) ? $content : [],
        $rpId,
        $challenge,
        $expectedOrigin,
        $user->getAccountName(),
        (string) $user->id(),
        $user->getDisplayName()
      );

      $aaguid = strtolower($publicKeyCredentialSource->aaguid->toRfc4122());
      if (!$this->passkeyLabeler->isAaguidAllowed($aaguid)) {
        return $this->ticketService->logAndReturnError('This authenticator is not allowed for passkey login.', 'Passkey registration blocked for disallowed AAGUID: @aaguid', ['@aaguid' => $aaguid], 403, LogLevel::WARNING);
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

      $this->cacheTagsInvalidator->invalidateTags(['wa_user_passkeys:' . $user->id()]);

      // Consume the re-authentication marker so each registration requires a
      // fresh confirmation.
      $request->getSession()->remove(self::REAUTH_SESSION_KEY);

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
      // The passkey is already saved at this point, so a problem with sending
      // the notification must not be reported as a failed registration.
      try {
        $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, TRUE);
      }
      catch (\Throwable $e) {
        $this->getLogger('wa')->error('The notification email for the new passkey of user @uid could not be sent: @message', [
          '@uid' => $user->id(),
          '@message' => $e->getMessage(),
        ]);
      }

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
    $data = json_decode($request->getContent(), TRUE) ?? [];
    $credentialId = $data['id'] ?? $request->request->get('id');

    if (empty($credentialId) || !is_string($credentialId)) {
      return $this->ticketService->logAndReturnError('Missing credential id', 'Missing credential id for deletion', [], 400, LogLevel::WARNING);
    }

    $current_uid = (int) $this->currentUser()->id();

    // Decode base64-encoded credential ID from UI.
    // The database stores credential IDs as raw binary; lookups use the
    // portable SHA-256 credential_hash column so PostgreSQL bytea comparisons
    // are avoided.
    $decodedCredentialId = base64_decode($credentialId, TRUE);
    if ($decodedCredentialId === FALSE || $decodedCredentialId === '') {
      return $this->ticketService->logAndReturnError('Invalid credential id format', 'Invalid credential id format for deletion', [], 400, LogLevel::WARNING);
    }

    $credentialHash = hash('sha256', $decodedCredentialId);

    // Fetch record details before deletion so we can log the owner correctly.
    $result = $this->database->select('wa', 'c')
      ->fields('c', ['aaguid', 'uid', 'credential_id'])
      ->condition('credential_hash', $credentialHash)
      ->execute()
      ->fetchAssoc();

    if (!$result || !hash_equals((string) $result['credential_id'], $decodedCredentialId)) {
      return $this->ticketService->logAndReturnError('Deletion failed or unauthorized', 'Deletion failed or unauthorized', [], 403, LogLevel::WARNING);
    }

    $target_uid = (int) $result['uid'];
    $aaguid = $result['aaguid'] ?? 'Unknown';

    // Owners can always delete their own passkeys, even if their role was
    // removed from allowed_roles (prevents stranded credentials). Admins with
    // administer all user passkey can delete any user's passkeys.
    $deleted = $this->deleteByCredentialHash($credentialHash, $current_uid);

    if (!$deleted && $this->currentUser()->hasPermission(WaPermissions::ADMINISTER_ALL_USER_PASSKEY)) {
      $deleted = $this->deleteByCredentialHash($credentialHash);
    }

    if ($deleted) {
      $this->cacheTagsInvalidator->invalidateTags(['wa_user_passkeys:' . $target_uid]);
      $maskedCredentialId = '****' . substr($credentialId, -6);
      $this->getLogger('wa')->info('Passkey deleted for user @uid. Credential ID: @credential_id, AAGUID: @aaguid.', [
        '@credential_id' => $maskedCredentialId,
        '@uid' => $target_uid,
        '@aaguid' => $aaguid,
      ]);
      return new JsonResponse(['status' => 'ok']);
    }

    return $this->ticketService->logAndReturnError('Deletion failed or unauthorized', 'Deletion failed or unauthorized', [], 403, LogLevel::WARNING);
  }

  /**
   * Deletes a credential by its SHA-256 hash.
   *
   * @param string $credentialHash
   *   The SHA-256 hex digest of the raw binary credential ID.
   * @param int|null $uid
   *   Optional user ID to restrict deletion to a specific user.
   *
   * @return int
   *   Number of credentials deleted (0 or 1).
   */
  protected function deleteByCredentialHash(string $credentialHash, ?int $uid = NULL): int {
    if ($credentialHash === '') {
      return 0;
    }

    $query = $this->database->delete('wa')
      ->condition('credential_hash', $credentialHash);

    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }

    return (int) $query->execute();
  }

  /**
   * Provides login options for passkey authentication.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The login options response.
   */
  public function loginOptions(Request $request) {
    if (!$this->isPasskeyLoginEnabled()) {
      return $this->ticketService->logAndReturnError('Passkey login is disabled.', 'Passkey login disabled check failed (login options)', [], 403, LogLevel::WARNING);
    }

    $floodName = 'wa_login_options';
    $window = 300;
    $maxAttempts = 10;
    $ipIdentifier = 'ip:' . ($request->getClientIp() ?? 'unknown');

    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $ipIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many login attempts. Please try again later.', 'Flood control blocked login options (IP)', [], 429, LogLevel::WARNING);
    }

    // Register every request against the IP flood window so anonymous callers
    // cannot spawn unbounded session rows or flood dblog.
    $this->flood->register($floodName, $window, $ipIdentifier);

    // Enforce X-Requested-With header to prevent CSRF.
    if (!$request->isXmlHttpRequest()) {
      return $this->ticketService->logAndReturnError('Invalid request.', 'Missing X-Requested-With header (CSRF check)', [], 403, LogLevel::NOTICE);
    }

    if (!$this->hasValidOriginOrReferer($request)) {
      return $this->ticketService->logAndReturnError('Invalid Origin or Referer.', 'Invalid Origin or Referer', [], 403, LogLevel::NOTICE);
    }

    $rpId = $request->getHost();
    $challenge = random_bytes(32);
    $challengeEncoded = Base64UrlSafe::encodeUnpadded($challenge);
    $request->getSession()->set('wa_challenge', [
      'value' => $challengeEncoded,
      'created' => $this->time->getCurrentTime(),
    ]);

    $config = $this->config('wa.settings');
    $userVerification = $config->get('user_verification') ?? 'required';

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
   * Returns a JSON response with a validated `redirect_url` on success or
   * policy redirect so the client performs a single top-level navigation
   * without consuming one-shot status messages via a hidden fetch() redirect.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request containing the assertion response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The verification result response.
   */
  public function loginVerify(Request $request) {
    if (!$this->isPasskeyLoginEnabled()) {
      return $this->ticketService->logAndReturnError('Passkey login is disabled.', 'Passkey login disabled check failed (login verify)', [], 403, LogLevel::WARNING);
    }

    $floodName = 'wa_login';
    $window = 900;
    $maxAttempts = 5;
    $ipIdentifier = 'ip:' . ($request->getClientIp() ?? 'unknown');
    $registerFloodOnFailure = TRUE;
    $credentialId = '';
    $uid = 0;

    if (!$this->flood->isAllowed($floodName, $maxAttempts, $window, $ipIdentifier)) {
      return $this->ticketService->logAndReturnError('Too many login attempts. Please try again later.', 'Flood control blocked login verify (IP)', [], 429, LogLevel::WARNING);
    }

    // Enforce X-Requested-With header to prevent CSRF.
    if (!$request->isXmlHttpRequest()) {
      $this->flood->register($floodName, $window, $ipIdentifier);
      return $this->ticketService->logAndReturnError('Invalid request.', 'Missing X-Requested-With header on loginVerify (CSRF check)', [], 403, LogLevel::NOTICE);
    }

    // Validate Origin or Referer header.
    if (!$this->hasValidOriginOrReferer($request)) {
      $this->flood->register($floodName, $window, $ipIdentifier);
      return $this->ticketService->logAndReturnError('Invalid Origin or Referer.', 'Invalid Origin or Referer on loginVerify', [], 403, LogLevel::NOTICE);
    }

    try {
      $config = $this->config('wa.settings');

      $content = json_decode($request->getContent(), TRUE);
      if (!is_array($content)) {
        throw new \Exception('Invalid login payload.');
      }

      $credentialId = $content['id'] ?? '';
      if (!is_string($credentialId) || $credentialId === '') {
        throw new \Exception('Missing credential id.');
      }

      // Decode base64url-encoded credential ID from client.
      // The database stores credential IDs as raw binary; lookup uses the
      // portable SHA-256 credential_hash column for cross-DB compatibility.
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

      $credentialHash = hash('sha256', $decodedCredentialId);

      // Find user by portable SHA-256 credential hash.
      $query = $this->database->select('wa', 'c')
        ->fields('c', ['uid', 'credential_id', 'public_key', 'user_handle', 'sign_counter', 'transports', 'aaguid'])
        ->condition('credential_hash', $credentialHash);

      // If the client provided the user handle (user ID), use it
      // to narrow down the search.
      $userHandleEncoded = $content['response']['userHandle'] ?? '';
      $enforce_user_handle = (bool) $config->get('enforce_user_handle');

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
            throw new \Exception('User handle decode failed.');
          }
        }

        if ($userHandle !== '') {
          // Validate that the decoded user handle is a positive integer UID.
          if (!ctype_digit($userHandle) || (int) $userHandle <= 0) {
            throw new \Exception('Invalid user handle format.');
          }
          // Add uid condition with AND logic.
          $query->condition('uid', (int) $userHandle);
        }
        elseif ($enforce_user_handle) {
          throw new \Exception('User handle is required by policy.');
        }
      }

      $statement = $query->execute();
      $result = $statement->fetchAssoc();

      if (!$result || !hash_equals((string) $result['credential_id'], $decodedCredentialId)) {
        throw new \Exception('Credential not found.');
      }

      // Load and validate user BEFORE expensive WebAuthn validation.
      $uid = (int) $result['uid'];
      $user = $this->entityTypeManager()->getStorage('user')->load($uid);

      if (!$user instanceof UserInterface) {
        throw new \Exception('User not found or invalid.');
      }

      if ($user->isBlocked()) {
        throw new \Exception('User account is blocked.');
      }

      if (!$this->userHasAllowedRole($user)) {
        throw new \Exception('Passkey login not permitted for this account.');
      }

      if (!$this->passkeyLabeler->isAaguidAllowed(strtolower((string) $result['aaguid']))) {
        throw new \Exception('This authenticator is not allowed for passkey login. AAGUID: ' . $result['aaguid']);
      }

      // Validate assertion via WebAuthn service with strict origin matching.
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
        $request->getHost(),
        $request->getSchemeAndHttpHost(),
        $config->get('user_verification') ?? 'required',
        $publicKeyCredentialSource
      );
      if ($validated !== TRUE || !$publicKeyCredentialSource) {
        throw new \Exception('Passkey validation failed.');
      }

      // Update counter and last used using portable credential_hash.
      $this->database->update('wa')
        ->fields([
          'last_used' => $this->time->getRequestTime(),
          'sign_counter' => $publicKeyCredentialSource->counter,
        ])
        ->condition('credential_hash', $credentialHash)
        ->execute();

      if ($uid === 0) {
        throw new \Exception('Invalid login state: Missing user ID.');
      }

      // Reset any stale step-up completion state from an earlier login attempt
      // and evaluate hook_wa_login() with fail-closed veto composition.
      $request->getSession()->remove('wa_completed_step_ups');
      $policy = $this->loginPolicyEvaluator->evaluate($user, TRUE, []);
      $allow_login = $policy['allowed_login'];
      $originalRedirectUrl = $policy['redirect_url'];
      $validatedRedirectUrl = $this->safeLoginFinalizer->normalizeLocalRedirectUrl($originalRedirectUrl, $request);

      if (!$allow_login) {
        $registerFloodOnFailure = FALSE;
        if ($validatedRedirectUrl !== NULL) {
          $this->getLogger('wa')->info('Passkey login intercepted by module @module for user @uid. Redirecting user.', [
            '@module' => $policy['denied_by'] ?? 'unknown',
            '@uid' => $uid,
          ]);
          return new JsonResponse([
            'status' => 'redirect',
            'redirect_url' => $validatedRedirectUrl,
          ]);
        }
        if ($originalRedirectUrl !== NULL) {
          return $this->ticketService->logAndReturnError(
            'Login prevented. Invalid redirect configuration.',
            'Login blocked by hook for user @uid with invalid redirect URL: @url',
            [
              '@uid' => $uid,
              '@url' => $originalRedirectUrl instanceof Url ? $originalRedirectUrl->toUriString() : (string) $originalRedirectUrl,
            ],
            403,
            LogLevel::WARNING
          );
        }
        return $this->ticketService->logAndReturnError(
          'Login prevented by site policy.',
          'Login blocked by external module for user @uid, no redirect URL provided.',
          ['@uid' => $uid],
          403,
          LogLevel::WARNING
        );
      }

      // Regenerate session ID before login to prevent session fixation.
      $request->getSession()->migrate(TRUE);

      // Finalize login with redirect interception.
      $finalRedirectUrl = $this->safeLoginFinalizer->finalizeLogin(
        $user,
        $request,
        [$originalRedirectUrl]
      );

      $maskedCredentialId = '****' . substr($credentialId, -6);
      $this->getLogger('wa')->info('User logged in with passkey. User: @uid, Credential ID: @credential_id.', [
        '@uid' => $uid,
        '@credential_id' => $maskedCredentialId,
      ]);

      $registerFloodOnFailure = FALSE;

      return new JsonResponse([
        'status' => 'ok',
        'redirect_url' => $this->safeLoginFinalizer->getFinalRedirectUrl($finalRedirectUrl, $uid),
      ]);
    }
    catch (EnforcedResponseException $e) {
      // SafeLoginFinalizer::finalizeLogin() already unwinds only the buffers
      // it opened in its finally block; do not pop outer output buffers here.
      $response = $e->getResponse();

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

      if ($response instanceof RedirectResponse) {
        $targetUrl = $response->getTargetUrl();
        $safeUrl = $this->safeLoginFinalizer->normalizeLocalRedirectUrl($targetUrl, $request);
        if ($safeUrl !== NULL) {
          return new JsonResponse([
            'status' => $this->currentUser()->isAuthenticated() ? 'ok' : 'redirect',
            'redirect_url' => $safeUrl,
          ]);
        }
        $this->getLogger('wa')->warning('EnforcedResponseException redirect rejected (external URL): @url for user @uid', [
          '@url' => $targetUrl,
          '@uid' => $uid,
        ]);
      }

      if ($this->currentUser()->isAuthenticated()) {
        return new JsonResponse([
          'status' => 'ok',
          'redirect_url' => $this->safeLoginFinalizer->getFinalRedirectUrl(NULL, $this->currentUser()->id()),
        ]);
      }

      return new JsonResponse([
        'status' => 'error',
        'message' => 'Login failed or incomplete.',
      ], 400);
    }
    catch (\Throwable $e) {
      if ($registerFloodOnFailure) {
        $this->flood->register($floodName, $window, $ipIdentifier);
      }

      return $this->ticketService->logAndReturnError('Passkey login failed.', 'Passkey login failed: @message', ['@message' => $e->getMessage()], 400);
    }
    finally {
      // Clear the challenge to enforce one-time use.
      $request->getSession()->remove('wa_challenge');
    }
  }

}
