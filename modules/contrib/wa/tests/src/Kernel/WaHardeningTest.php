<?php

namespace Drupal\Tests\wa\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\RoleInterface;
use Drupal\user\UserAuthentication;
use Drupal\wa\Controller\PasskeyController;
use Drupal\wa\Service\LoginPolicyEvaluator;
use Drupal\wa_email_otp\Form\OtpEntryForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Verifies security and logic hardening fixes across wa and wa_email_otp.
 *
 * @group wa
 */
#[Group('wa')]
#[RunTestsInSeparateProcesses]
class WaHardeningTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'views', 'wa', 'wa_email_otp'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('wa', ['wa']);
    $this->installSchema('wa_email_otp', ['wa_email_otp']);
    $this->installConfig(['wa', 'wa_email_otp']);

    // Create superuser (uid 1) first so test users are regular accounts.
    $this->createUser();
  }

  /**
   * Tests S4: LoginPolicyEvaluator fails closed and hard vetoes beat step-up.
   */
  public function testLoginPolicyEvaluatorFailClosedAndVetoComposition(): void {
    $user = $this->createUser([], 'policy_user');
    $loggerFactory = $this->container->get('logger.factory');

    // Case 1: Flat ['allowed_login' => FALSE] fails closed.
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('invokeAllWith')
      ->willReturnCallback(function (string $hook, callable $callback): void {
        $callback(static fn() => ['allowed_login' => FALSE, 'redirect_url' => '/denied'], 'flat_mod');
      });
    $evaluator = new LoginPolicyEvaluator($moduleHandler, $loggerFactory);
    $decision = $evaluator->evaluate($user, TRUE);
    $this->assertFalse($decision['allowed_login']);
    $this->assertSame('/denied', $decision['redirect_url']);
    $this->assertFalse($decision['is_step_up']);

    // Case 2: Non-array scalar return fails closed.
    $moduleHandlerScalar = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandlerScalar->method('invokeAllWith')
      ->willReturnCallback(function (string $hook, callable $callback): void {
        $callback(static fn() => FALSE, 'broken_mod');
      });
    $evaluatorScalar = new LoginPolicyEvaluator($moduleHandlerScalar, $loggerFactory);
    $decisionScalar = $evaluatorScalar->evaluate($user, TRUE);
    $this->assertFalse($decisionScalar['allowed_login']);

    // Case 3: Step-up module runs FIRST, hard veto runs SECOND; hard veto wins
    // and step_up_callback MUST NOT execute.
    $callbackTracker = new \stdClass();
    $callbackTracker->called = FALSE;
    $moduleHandlerCompose = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandlerCompose->method('invokeAllWith')
      ->willReturnCallback(function (string $hook, callable $callback) use ($callbackTracker): void {
        $callback(static fn() => [[
          'allowed_login' => FALSE,
          'step_up' => TRUE,
          'step_up_callback' => static function () use ($callbackTracker): string {
            $callbackTracker->called = TRUE;
            return '/wa_otp/1/abc';
          },
        ],
        ], 'wa_email_otp');
        $callback(static fn() => [
          'allowed_login' => FALSE,
          'redirect_url' => '/ip-denied',
        ], 'z_ip_policy');
      });
    $evaluatorCompose = new LoginPolicyEvaluator($moduleHandlerCompose, $loggerFactory);
    $decisionCompose = $evaluatorCompose->evaluate($user, TRUE);
    $this->assertFalse($decisionCompose['allowed_login']);
    $this->assertFalse($decisionCompose['is_step_up']);
    $this->assertSame('/ip-denied', $decisionCompose['redirect_url']);
    $this->assertSame('z_ip_policy', $decisionCompose['denied_by']);
    $this->assertFalse($callbackTracker->called, 'Step-up callback must not run when another module vetoes login.');

    // Case 4: Two step-up modules compose sequentially when completed modules
    // are tracked in $completedStepUpModules.
    $moduleHandlerTwoStepUps = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandlerTwoStepUps->method('invokeAllWith')
      ->willReturnCallback(function (string $hook, callable $callback): void {
        $callback(static fn() => [[
          'allowed_login' => FALSE,
          'step_up' => TRUE,
          'redirect_url' => '/step-up-one',
        ],
        ], 'step_up_one');
        $callback(static fn() => [[
          'allowed_login' => FALSE,
          'step_up' => TRUE,
          'redirect_url' => '/step-up-two',
        ],
        ], 'step_up_two');
      });
    $evaluatorTwo = new LoginPolicyEvaluator($moduleHandlerTwoStepUps, $loggerFactory);
    $first = $evaluatorTwo->evaluate($user, TRUE, []);
    $this->assertFalse($first['allowed_login']);
    $this->assertTrue($first['is_step_up']);
    $this->assertSame('/step-up-one', $first['redirect_url']);

    $second = $evaluatorTwo->evaluate($user, TRUE, ['step_up_one']);
    $this->assertFalse($second['allowed_login']);
    $this->assertTrue($second['is_step_up']);
    $this->assertSame('/step-up-two', $second['redirect_url']);

    $final = $evaluatorTwo->evaluate($user, TRUE, ['step_up_one', 'step_up_two']);
    $this->assertTrue($final['allowed_login']);
  }

  /**
   * Tests S7 and S10: kill switch fails closed and access carries cacheability.
   */
  public function testKillSwitchFailClosedAndAccessCacheability(): void {
    $user = $this->createUser([], 'cache_user');
    $route = new Route('/user/{user}/passkeys');
    $routeMatch = new RouteMatch('wa.settings', $route, ['user' => $user], ['user' => $user->id()]);

    /** @var \Drupal\wa\Access\PasskeyManageAccessCheck $checker */
    $checker = $this->container->get('wa.passkey_manage_access_check');

    // Kill switch overridden to integer 0 (falsy non-boolean) must deny AND
    // carry user.permissions cache context + config:wa.settings cache tag.
    $this->config('wa.settings')->set('enable_passkey_login', 0)->save();
    $resultDisabled = $checker->access($routeMatch, $user);
    $this->assertTrue($resultDisabled->isForbidden());
    $this->assertContains('user.permissions', $resultDisabled->getCacheContexts());
    $this->assertContains('config:wa.settings', $resultDisabled->getCacheTags());

    // Enabled with authenticated role allowed: owner result must carry target
    // user cache tag so role changes invalidate tab visibility.
    $this->config('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [RoleInterface::AUTHENTICATED_ID])
      ->save();
    $resultOwner = $checker->access($routeMatch, $user);
    $this->assertTrue($resultOwner->isAllowed());
    $this->assertContains('user:' . $user->id(), $resultOwner->getCacheTags());
    $this->assertContains('config:wa.settings', $resultOwner->getCacheTags());
  }

  /**
   * Tests L2: subdirectory installs do not get base path prepended twice.
   */
  public function testSubdirectoryRedirectDoesNotDoubleBasePath(): void {
    /** @var \Drupal\wa\Service\SafeLoginFinalizer $finalizer */
    $finalizer = $this->container->get('wa.safe_login_finalizer');

    // Simulate a Drupal site installed under /sub.
    $request = Request::create('/sub/wa/login/verify', 'POST', [], [], [], [
      'SCRIPT_FILENAME' => '/var/www/html/sub/index.php',
      'SCRIPT_NAME' => '/sub/index.php',
      'PHP_SELF' => '/sub/index.php',
    ]);
    $this->assertSame('/sub', $request->getBasePath());
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $this->container->get('router.request_context')->fromRequest($request);

    $otpUrl = Url::fromRoute('wa_email_otp.login', ['uid' => 5, 'hash' => 'abc123']);
    $normalizedFromUrl = $finalizer->normalizeLocalRedirectUrl($otpUrl, $request);
    $this->assertSame('/sub/wa_otp/5/abc123', $normalizedFromUrl);

    // Passing the already-stringified subdirectory path must NOT double /sub.
    $normalizedFromString = $finalizer->normalizeLocalRedirectUrl('/sub/wa_otp/5/abc123', $request);
    $this->assertSame('/sub/wa_otp/5/abc123', $normalizedFromString);

    // External and protocol-relative URLs remain rejected.
    $this->assertNull($finalizer->normalizeLocalRedirectUrl('https://evil.com/phish', $request));
    $this->assertNull($finalizer->normalizeLocalRedirectUrl('//evil.com/phish', $request));
    $this->assertNull($finalizer->normalizeLocalRedirectUrl('/\\evil.com/phish', $request));
    $this->assertNull($finalizer->normalizeLocalRedirectUrl(' https://evil.com', $request));
  }

  /**
   * Tests L5 and S3: cleanup on user delete, password change, and OTP cron.
   */
  public function testCleanupOnUserDeletePasswordChangeAndOtpCron(): void {
    $user = $this->createUser([], 'cleanup_user');
    $db = $this->container->get('database');
    $now = $this->container->get('datetime.time')->getRequestTime();

    $seedCredential = static function (int $uid, string $credId) use ($db, $now): void {
      $db->insert('wa')->fields([
        'uid' => $uid,
        'credential_id' => $credId,
        'credential_hash' => hash('sha256', $credId),
        'public_key' => base64_encode('key'),
        'user_handle' => (string) $uid,
        'sign_counter' => 0,
        'transports' => json_encode(['internal']),
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'created' => $now,
        'last_used' => $now,
      ])->execute();
    };

    $countPasskeys = static function (int $uid) use ($db): int {
      return (int) $db->select('wa', 'w')
        ->condition('uid', $uid)
        ->countQuery()
        ->execute()
        ->fetchField();
    };

    // 1. Routine password login that triggers needsRehash() + $user->save()
    // MUST NOT revoke passkeys (B3).
    $seedCredential((int) $user->id(), 'cred-survives-login-rehash');
    $rehashChecker = $this->createMock(PasswordInterface::class);
    $rehashChecker->method('check')->willReturn(TRUE);
    $rehashChecker->method('needsRehash')->willReturn(TRUE);
    $rehashChecker->method('hash')->willReturn('$2y$10$rehashed.password.value.12345678901234567890123456789');
    $this->container->set('password', $rehashChecker);
    // Re-instantiate user.auth with the mocked password checker that forces a
    // rehash on login.
    $userAuth = new UserAuthentication(
      $this->container->get('entity_type.manager'),
      $rehashChecker
    );
    $this->assertTrue($userAuth->authenticateAccount($user, 'existing-password'));
    $this->assertSame(1, $countPasskeys((int) $user->id()), 'Transparent password rehash during login must not revoke passkeys.');

    // 2. Submitting user_form without a new password keeps passkeys; submitting
    // user_form with a new password revokes passkeys (even from a legacy hash).
    $formObject = $this->container->get('entity_type.manager')->getFormObject('user', 'default');
    $formObject->setEntity($user);

    $emptyPassState = new FormState();
    $emptyPassState->setFormObject($formObject);
    $emptyPassState->setValue('pass', '');
    $dummyForm = [];
    wa_user_form_password_submit($dummyForm, $emptyPassState);
    $this->assertSame(1, $countPasskeys((int) $user->id()));

    $newPassState = new FormState();
    $newPassState->setFormObject($formObject);
    $newPassState->setValue('pass', 'NewStrongPassword123!');
    wa_user_form_password_submit($dummyForm, $newPassState);
    $this->assertSame(0, $countPasskeys((int) $user->id()), 'Passkeys are revoked when a new password is submitted on user_form.');

    // 3. Deleting a user cleans up both {wa} and {wa_email_otp}.
    $seedCredential((int) $user->id(), 'cred-before-delete');
    $this->container->get('wa_email_otp.otp_service')->generate((int) $user->id());
    $user->delete();

    $this->assertSame(0, (int) $db->select('wa', 'w')->condition('uid', $user->id())->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $db->select('wa_email_otp', 'o')->condition('uid', $user->id())->countQuery()->execute()->fetchField());

    // 3. Cron purges expired OTP rows older than RESEND_WINDOW.
    $db->insert('wa_email_otp')->fields([
      'uid' => 999,
      'otp' => password_hash('123456', PASSWORD_DEFAULT),
      'hash' => bin2hex(random_bytes(32)),
      'created' => $now - 3600,
    ])->execute();
    wa_email_otp_cron();
    $this->assertSame(0, (int) $db->select('wa_email_otp', 'o')->condition('uid', 999)->countQuery()->execute()->fetchField());
  }

  /**
   * Tests L6: custom AAGUID labels are not double-escaped and transports load.
   */
  public function testCustomLabelNotDoubleEscapedAndTransportsLoaded(): void {
    $customAaguid = '11111111-2222-3333-4444-555555555555';
    $unknownInternalAaguid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    $this->config('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [RoleInterface::AUTHENTICATED_ID])
      ->set('custom_aaguid_labels', [$customAaguid => "YubiKey & Co's Key"])
      ->save();

    // Reset labeler service so it picks up the new custom label.
    $this->container->set('wa.passkey_labeler', NULL);
    /** @var \Drupal\wa\Service\PasskeyLabeler $labeler */
    $labeler = $this->container->get('wa.passkey_labeler');
    $this->assertSame("YubiKey & Co's Key", $labeler->getLabel($customAaguid));

    // Plain-text email token replacement must not contain HTML entities.
    $message = ['subject' => '', 'body' => []];
    $user = $this->createUser([], 'label_user');
    wa_mail('passkey_add_notification', $message, [
      'context' => [
        'user' => $user,
        'wa_passkey' => ['aaguid' => $customAaguid],
      ],
    ]);
    $this->assertStringContainsString("YubiKey & Co's Key", $message['body'][0]);
    $this->assertStringNotContainsString('&amp;', $message['body'][0]);
    $this->assertStringNotContainsString('&#039;', $message['body'][0]);

    // Manage passkeys query loads transports so unknown non-zero AAGUID with
    // internal transport displays "Password Manager".
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->container->get('database')->insert('wa')->fields([
      'uid' => $user->id(),
      'credential_id' => 'cred-internal-transport',
      'credential_hash' => hash('sha256', 'cred-internal-transport'),
      'public_key' => base64_encode('key'),
      'user_handle' => (string) $user->id(),
      'sign_counter' => 0,
      'transports' => json_encode(['internal']),
      'aaguid' => $unknownInternalAaguid,
      'created' => $now,
      'last_used' => $now,
    ])->execute();

    $this->setCurrentUser($user);
    $controller = PasskeyController::create($this->container);
    $build = $controller->managePasskeys($user);
    $this->assertSame('Password Manager', $build['passkey_management']['table']['#rows'][0][0]);
  }

  /**
   * Tests L7: outer output buffers are never closed by loginVerify errors.
   */
  public function testOuterOutputBufferPreservedOnLoginError(): void {
    $this->config('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [RoleInterface::AUTHENTICATED_ID])
      ->save();

    $request = Request::create('https://example.com/wa/login/verify', 'POST', [], [], [], [
      'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
      'HTTP_ORIGIN' => 'https://example.com',
    ], json_encode(['id' => 'nonexistent']));
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $controller = PasskeyController::create($this->container);

    ob_start();
    echo 'outer-buffer-marker';
    $levelBefore = ob_get_level();

    $response = $controller->loginVerify($request);
    $levelAfter = ob_get_level();
    $captured = ob_get_clean();

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame($levelBefore, $levelAfter, 'Controller must not pop output buffers it did not open.');
    $this->assertSame('outer-buffer-marker', $captured);
  }

  /**
   * Tests L1: OtpEntryForm allows Resend after 60s expiry and skips flood burn.
   */
  public function testOtpResendWorksAfterExpiryWithoutBurningVerifyFlood(): void {
    $user = $this->createUser([], 'otp_user', FALSE, ['mail' => 'otp@example.com']);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $hash = bin2hex(random_bytes(32));

    // Insert an OTP created 120 seconds ago (expired for verification >60s,
    // still inside the 900s resend window).
    $this->container->get('database')->insert('wa_email_otp')->fields([
      'uid' => $user->id(),
      'otp' => password_hash('123456', PASSWORD_DEFAULT),
      'hash' => $hash,
      'created' => $now - 120,
    ])->execute();

    $request = Request::create('/wa_otp/' . $user->id() . '/' . $hash, 'GET');
    $session = new Session(new MockArraySessionStorage());
    $session->set('wa_email_otp_uid', (int) $user->id());
    $session->set('wa_email_otp_started', $now - 120);
    $request->setSession($session);
    $this->container->get('request_stack')->push($request);

    $formObject = OtpEntryForm::create($this->container);
    $formBuilder = $this->container->get('form_builder');
    $formValidator = $this->container->get('form_validator');

    // Build via form_builder so #validate and button handlers mirror real HTTP
    // form processing.
    $formState = new FormState();
    $formState->addBuildInfo('args', [$user->id(), $hash]);
    $built = $formBuilder->buildForm($formObject, $formState);

    // Must render the form (not a RedirectResponse) with Resend button wired
    // to a non-empty validate handler so FormValidator never falls back to
    // ::validateForm().
    $this->assertArrayHasKey('expired_notice', $built);
    $this->assertSame(['::validateResend'], $built['actions']['resend']['#validate']);
    $this->assertSame(['::resendOtp'], $built['actions']['resend']['#submit']);

    // Exercise FormValidator directly 5 times with the Resend button as the
    // triggering element (the exact path that previously fell back to
    // validateForm() and burned 5 verification attempts).
    for ($i = 0; $i < 5; $i++) {
      $resendState = new FormState();
      $resendState->setValues([
        'uid' => (int) $user->id(),
        'hash' => $hash,
        'otp_entry' => '',
      ]);
      $resendState->setTriggeringElement($built['actions']['resend']);
      $resendState->setValidateHandlers($built['actions']['resend']['#validate']);
      $resendState->setLimitValidationErrors($built['actions']['resend']['#limit_validation_errors']);
      $resendState->setFormObject($formObject);

      $formCopy = $built;
      $formValidator->validateForm($formObject->getFormId(), $formCopy, $resendState);
      $this->assertEmpty($resendState->getErrors(), 'Resend validation must not produce form errors.');
    }

    // After 5 Resend validations, neither IP nor user verification flood
    // counter may have been incremented.
    $flood = $this->container->get('flood');
    $this->assertTrue($flood->isAllowed('wa_email_otp_entry_user', 1, 3600, (string) $user->id()));
    $this->assertTrue($flood->isAllowed('wa_email_otp_entry_ip', 1, 3600, $request->getClientIp() ?? '0.0.0.0'));

    // Resend handler generates a new valid OTP and still does not touch
    // verification flood counters.
    $submitState = new FormState();
    $submitState->setValue('uid', (int) $user->id());
    $formObject->resendOtp($built, $submitState);
    $this->assertTrue($flood->isAllowed('wa_email_otp_entry_user', 1, 3600, (string) $user->id()));
    $this->assertTrue($flood->isAllowed('wa_email_otp_entry_ip', 1, 3600, $request->getClientIp() ?? '0.0.0.0'));

    // An expired pending OTP session (> RESEND_WINDOW) must redirect to login
    // and clear session markers.
    $session->set('wa_email_otp_started', $now - 1200);
    $expiredResponse = $formObject->buildForm([], new FormState(), $user->id(), $hash);
    $this->assertInstanceOf(RedirectResponse::class, $expiredResponse);
    $this->assertNull($session->get('wa_email_otp_uid'));
    $this->assertNull($session->get('wa_email_otp_started'));
  }

  /**
   * Tests L9: binary credential IDs with NUL and non-UTF8 bytes delete cleanly.
   */
  public function testBinaryCredentialLookupUsesCredentialHash(): void {
    $user = $this->createUser([], 'binary_cred_user');
    $this->setCurrentUser($user);

    $rawBinaryId = "\x00\xff\xfe\\binary-cred-\x80\x81";
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->container->get('database')->insert('wa')->fields([
      'uid' => $user->id(),
      'credential_id' => $rawBinaryId,
      'credential_hash' => hash('sha256', $rawBinaryId),
      'public_key' => base64_encode('key'),
      'user_handle' => (string) $user->id(),
      'sign_counter' => 0,
      'transports' => json_encode([]),
      'aaguid' => '00000000-0000-0000-0000-000000000000',
      'created' => $now,
      'last_used' => $now,
    ])->execute();

    $request = Request::create('/wa/passkey/delete', 'POST', [], [], [], [], json_encode([
      'id' => base64_encode($rawBinaryId),
    ]));
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $controller = PasskeyController::create($this->container);
    $response = $controller->deletePasskey($request);

    $this->assertSame(200, $response->getStatusCode());
    $remaining = (int) $this->container->get('database')->select('wa', 'w')
      ->condition('uid', $user->id())
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(0, $remaining);
  }

}
