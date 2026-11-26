<?php

namespace Drupal\wa_email_otp\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Drupal\wa\Service\LoginPolicyEvaluator;
use Drupal\wa\Service\SafeLoginFinalizer;
use Drupal\wa_email_otp\Service\OtpService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Form for entering the email OTP.
 */
class OtpEntryForm extends FormBase {

  /**
   * The OTP service.
   *
   * @var \Drupal\wa_email_otp\Service\OtpService
   */
  protected $otpService;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The safe login finalizer.
   *
   * @var \Drupal\wa\Service\SafeLoginFinalizer
   */
  protected $safeLoginFinalizer;

  /**
   * The login policy evaluator.
   *
   * @var \Drupal\wa\Service\LoginPolicyEvaluator
   */
  protected LoginPolicyEvaluator $loginPolicyEvaluator;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Constructs an OtpEntryForm object.
   *
   * @param \Drupal\wa_email_otp\Service\OtpService $otp_service
   *   The OTP service.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\wa\Service\SafeLoginFinalizer $safe_login_finalizer
   *   The safe login finalizer service.
   * @param \Drupal\wa\Service\LoginPolicyEvaluator $login_policy_evaluator
   *   The login policy evaluator service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    OtpService $otp_service,
    FloodInterface $flood,
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack,
    LoggerChannelFactoryInterface $logger_factory,
    SafeLoginFinalizer $safe_login_finalizer,
    LoginPolicyEvaluator $login_policy_evaluator,
    TimeInterface $time,
  ) {
    $this->otpService = $otp_service;
    $this->flood = $flood;
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
    $this->safeLoginFinalizer = $safe_login_finalizer;
    $this->loginPolicyEvaluator = $login_policy_evaluator;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wa_email_otp.otp_service'),
      $container->get('flood'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('logger.factory'),
      $container->get('wa.safe_login_finalizer'),
      $container->get('wa.login_policy_evaluator'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wa_email_otp_entry';
  }

  /**
   * Clears all pending Email OTP session markers.
   */
  protected function clearPendingOtpSession(?SessionInterface $session): void {
    if (!$session) {
      return;
    }
    $session->remove('wa_email_otp_uid');
    $session->remove('wa_email_otp_started');
    $session->remove('wa_email_otp_destination');
  }

  /**
   * Checks whether the pending OTP session has expired.
   */
  protected function isPendingOtpSessionExpired(?SessionInterface $session): bool {
    if (!$session) {
      return TRUE;
    }
    $started = $session->get('wa_email_otp_started');
    if (!is_int($started) && !is_numeric($started)) {
      return TRUE;
    }
    return ($this->time->getRequestTime() - (int) $started) > OtpService::RESEND_WINDOW;
  }

  /**
   * Checks whether the user is still permitted to complete passkey login.
   */
  protected function isPasskeyLoginStillPermitted(UserInterface $user): bool {
    $waConfig = $this->config('wa.settings');
    if (!(bool) $waConfig->get('enable_passkey_login')) {
      return FALSE;
    }
    if ($user->isBlocked()) {
      return FALSE;
    }

    $allowedRoles = $waConfig->get('allowed_roles') ?? [];
    $allowedRoles = array_filter(is_array($allowedRoles) ? $allowedRoles : []);
    if (empty($allowedRoles)) {
      return FALSE;
    }
    if (in_array(RoleInterface::AUTHENTICATED_ID, $allowedRoles, TRUE)) {
      return $user->isAuthenticated();
    }
    return !empty(array_intersect($user->getRoles(), $allowedRoles));
  }

  /**
   * {@inheritdoc}
   *
   * @return array|\Symfony\Component\HttpFoundation\Response
   *   The render array for the form or a redirect response.
   */
  public function buildForm(array $form, FormStateInterface $form_state, mixed $uid = NULL, ?string $hash = NULL) {
    // Casting collapses every variant to the canonical integer before $uid is
    // stored in form state and later used as a flood identifier.
    $uid = (int) $uid;

    if (!$uid || !$hash) {
      throw new AccessDeniedHttpException();
    }

    // Verify session binding to prevent direct access loopholes.
    $request = $this->requestStack->getCurrentRequest();
    $session = $request ? $request->getSession() : NULL;
    $session_uid = $session ? $session->get('wa_email_otp_uid') : NULL;

    if (!$session_uid || (int) $session_uid !== $uid) {
      throw new AccessDeniedHttpException();
    }

    // Enforce bounded lifetime on pending OTP sessions (RESEND_WINDOW = 15m).
    if ($this->isPendingOtpSessionExpired($session)) {
      $this->otpService->cleanupAll($uid);
      $this->clearPendingOtpSession($session);
      $session->remove('wa_completed_step_ups');
      $this->messenger()->addError($this->t('Your verification session has expired. Please sign in again.'));
      return $this->redirect('user.login');
    }

    $valid = $this->otpService->validate($uid, $hash);
    $isExpiredAllowResend = FALSE;

    if (!$valid) {
      // Allow rendering the form (and handling Resend POSTs) when the hash
      // exists for this session-bound user and has only passed the 60-second
      // verification window. Reject unknown or fully expired links.
      if (!$this->otpService->hasHash($uid, $hash)) {
        $this->messenger()->addError($this->t('Invalid or expired login link.'));
        return $this->redirect('user.login');
      }
      $isExpiredAllowResend = TRUE;
    }

    $form['uid'] = [
      '#type' => 'value',
      '#value' => $uid,
    ];

    $form['hash'] = [
      '#type' => 'value',
      '#value' => $hash,
    ];

    if ($isExpiredAllowResend) {
      $form['expired_notice'] = [
        '#markup' => '<p class="messages messages--warning">' . $this->t('Your verification code has expired. Click "Resend OTP" to receive a new code.') . '</p>',
      ];
    }

    $form['otp_entry'] = [
      '#type' => 'textfield',
      '#title' => $this->t('One-Time Password'),
      '#description' => $this->t('Enter the 6-digit code sent to your email. The code expires in one minute.'),
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify & Login'),
    ];
    $form['actions']['resend'] = [
      '#type' => 'submit',
      '#name' => 'resend',
      '#value' => $this->t('Resend OTP'),
      // Use a non-empty #validate callback so FormValidator does not fall back
      // to form-level validateForm() and burn verification flood attempts.
      '#validate' => ['::validateResend'],
      '#submit' => ['::resendOtp'],
      '#limit_validation_errors' => [['uid']],
    ];

    return $form;
  }

  /**
   * Validation handler for the Resend OTP button.
   *
   * Intentionally skips OTP code verification; resend rate limiting is
   * enforced in ::resendOtp().
   */
  public function validateResend(array &$form, FormStateInterface $form_state): void {
    // No-op: prevents FormValidator from falling back to ::validateForm().
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();
    if (is_array($triggering_element) && ($triggering_element['#name'] ?? NULL) === 'resend') {
      return;
    }

    $otp_input = (string) ($form_state->getValue('otp_entry') ?? '');
    $uid = (int) $form_state->getValue('uid');
    $hash = (string) ($form_state->getValue('hash') ?? '');
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request ? ($request->getClientIp() ?? '0.0.0.0') : '0.0.0.0';

    $flood_name = 'wa_email_otp_entry_ip';
    if (!$this->flood->isAllowed($flood_name, 5, 3600, $ip)) {
      $form_state->setErrorByName('otp_entry', $this->t('Too many attempts. Please try again later.'));
      return;
    }

    $flood_name_user = 'wa_email_otp_entry_user';
    if (!$this->flood->isAllowed($flood_name_user, 5, 3600, (string) $uid)) {
      $form_state->setErrorByName('otp_entry', $this->t('Too many attempts for this user. Please try again later.'));
      return;
    }

    // Verify OTP using service.
    if (!$this->otpService->verify($uid, $hash, $otp_input)) {
      $this->flood->register($flood_name, 3600, $ip);
      $this->flood->register($flood_name_user, 3600, (string) $uid);
      $form_state->setErrorByName('otp_entry', $this->t('Invalid or expired One-Time Password.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->getValue('uid');
    $user = $this->entityTypeManager->getStorage('user')->load($uid);

    if (!$user instanceof UserInterface) {
      $form_state->setRedirect('user.login');
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $session = ($request && $request->hasSession()) ? $request->getSession() : NULL;
    $savedDestination = $session ? $session->get('wa_email_otp_destination') : NULL;
    $sessionExpired = $this->isPendingOtpSessionExpired($session);

    // Cleanup all OTPs and pending OTP markers to ensure single-use.
    $this->otpService->cleanupAll($uid);
    $this->clearPendingOtpSession($session);

    if ($sessionExpired) {
      if ($session) {
        $session->remove('wa_completed_step_ups');
      }
      $this->messenger()->addError($this->t('Your verification session has expired. Please sign in again.'));
      $form_state->setRedirect('user.login');
      return;
    }

    // Re-check global kill switch, account status, and allowed roles right
    // before finalizing login in case configuration or user roles changed
    // while the OTP challenge was pending.
    if (!$this->isPasskeyLoginStillPermitted($user)) {
      if ($session) {
        $session->remove('wa_completed_step_ups');
      }
      $this->loggerFactory->get('wa_email_otp')->warning('Email OTP login blocked because passkey login is disabled or user @uid is no longer permitted.', [
        '@uid' => $uid,
      ]);
      $this->messenger()->addError($this->t('Passkey login is not permitted for this account.'));
      $form_state->setRedirect('user.login');
      return;
    }

    // Record completion of wa_email_otp and re-evaluate hook_wa_login() so
    // both hard policy vetoes AND any additional uncompleted step-up modules
    // are honored before finalizing login.
    $completedStepUps = $session ? $session->get('wa_completed_step_ups', []) : [];
    if (!is_array($completedStepUps)) {
      $completedStepUps = [];
    }
    $completedStepUps[] = 'wa_email_otp';
    $completedStepUps = array_values(array_unique($completedStepUps));
    if ($session) {
      $session->set('wa_completed_step_ups', $completedStepUps);
    }

    $policy = $this->loginPolicyEvaluator->evaluate($user, TRUE, $completedStepUps);
    if (!$policy['allowed_login']) {
      if (!$policy['is_step_up'] && $session) {
        $session->remove('wa_completed_step_ups');
      }
      $this->loggerFactory->get('wa_email_otp')->info('Email OTP login handed off or denied by module @module for user @uid.', [
        '@module' => $policy['denied_by'] ?? 'unknown',
        '@uid' => $uid,
      ]);
      $policyRedirect = $request
        ? $this->safeLoginFinalizer->normalizeLocalRedirectUrl($policy['redirect_url'], $request)
        : NULL;
      if ($policyRedirect !== NULL) {
        $form_state->setRedirect('<none>');
        $form_state->setResponse(new LocalRedirectResponse($policyRedirect));
      }
      else {
        $this->messenger()->addError($this->t('Login prevented by site policy.'));
        $form_state->setRedirect('user.login');
      }
      return;
    }

    if ($session) {
      $session->remove('wa_completed_step_ups');
    }

    if ($request) {
      // Regenerate session ID before login to prevent session fixation.
      $request->getSession()->migrate(TRUE);

      // Finalize login with redirect interception.
      $finalRedirectUrl = $this->safeLoginFinalizer->finalizeLogin(
        $user,
        $request,
        [$savedDestination, $policy['redirect_url']]
      );

      $this->flood->clear('wa_email_otp_entry_ip', $request->getClientIp() ?? '0.0.0.0');
      $this->flood->clear('wa_email_otp_entry_user', (string) $uid);

      // Always use setResponse() to bypass Drupal's RedirectResponseSubscriber
      // which could re-inject a ?destination parameter for open redirect.
      $form_state->setRedirect('<none>');
      $form_state->setResponse(
        $this->safeLoginFinalizer->createRedirectResponse($finalRedirectUrl, $uid)
      );
      $this->loggerFactory->get('wa_email_otp')->info('User @uid logged in via Email OTP.', ['@uid' => $uid]);
    }
  }

  /**
   * Resend OTP handler.
   */
  public function resendOtp(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->getValue('uid');

    // Flood control: Check limits BEFORE any OTP operations.
    $request = $this->requestStack->getCurrentRequest();
    $session = ($request && $request->hasSession()) ? $request->getSession() : NULL;

    // Strip ?destination to prevent RedirectResponseSubscriber from
    // overriding our setRedirect() with an unvalidated external URL.
    if ($request) {
      $request->query->remove('destination');
    }

    if ($this->isPendingOtpSessionExpired($session)) {
      $this->otpService->cleanupAll($uid);
      $this->clearPendingOtpSession($session);
      $this->messenger()->addError($this->t('Your verification session has expired. Please sign in again.'));
      $form_state->setRedirect('user.login');
      return;
    }

    $client_ip = $request ? ($request->getClientIp() ?? '0.0.0.0') : '0.0.0.0';
    $flood_name_ip = 'wa_email_otp_resend_ip';
    $flood_name_user = 'wa_email_otp_resend_user';

    if (!$this->flood->isAllowed($flood_name_ip, 5, 3600, $client_ip)
      || !$this->flood->isAllowed($flood_name_user, 5, 3600, (string) $uid)) {
      $this->otpService->cleanupAll($uid);
      $this->clearPendingOtpSession($session);
      $this->messenger()->addError($this->t('Too many resend attempts. Please try again later.'));
      $form_state->setRedirect('user.login');
      return;
    }

    // Generate new (cleanupAll is also invoked inside generate()).
    $data = $this->otpService->generate($uid);
    $user = $this->entityTypeManager->getStorage('user')->load($uid);

    if ($user instanceof UserInterface && $this->otpService->sendOtp($user, $data['otp'])) {
      $this->flood->register($flood_name_ip, 3600, $client_ip);
      $this->flood->register($flood_name_user, 3600, (string) $uid);

      $this->messenger()->addStatus($this->t('A new OTP has been sent to your email.'));
      // Redirect to new hash.
      $form_state->setRedirect('wa_email_otp.login', ['uid' => $uid, 'hash' => $data['hash']]);
    }
    else {
      $this->messenger()->addError($this->t('Failed to send OTP. Please try again.'));
    }
  }

}
