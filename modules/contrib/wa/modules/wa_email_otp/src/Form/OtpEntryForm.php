<?php

namespace Drupal\wa_email_otp\Form;

use Drupal\wa_email_otp\Service\OtpService;
use Drupal\wa\Service\SafeLoginFinalizer;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\user\UserStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
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
   * The user storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

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
   * Constructs an OtpEntryForm object.
   *
   * @param \Drupal\wa_email_otp\Service\OtpService $otp_service
   *   The OTP service.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\user\UserStorageInterface $user_storage
   *   The user storage.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\wa\Service\SafeLoginFinalizer $safe_login_finalizer
   *   The safe login finalizer service.
   */
  public function __construct(OtpService $otp_service, FloodInterface $flood, UserStorageInterface $user_storage, RequestStack $request_stack, LoggerChannelFactoryInterface $logger_factory, SafeLoginFinalizer $safe_login_finalizer) {
    $this->otpService = $otp_service;
    $this->flood = $flood;
    $this->userStorage = $user_storage;
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
    $this->safeLoginFinalizer = $safe_login_finalizer;
  }

  /**
   * {@inheritdoc}
   */

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wa_email_otp.otp_service'),
      $container->get('flood'),
      $container->get('entity_type.manager')->getStorage('user'),
      $container->get('request_stack'),
      $container->get('logger.factory'),
      $container->get('wa.safe_login_finalizer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wa_email_otp_entry';
  }

  /**
   * {@inheritdoc}
   *
   * @return array|\Symfony\Component\HttpFoundation\Response
   *   The render array for the form or a redirect response.
   */
  public function buildForm(array $form, FormStateInterface $form_state, mixed $uid = NULL, ?string $hash = NULL) {
    // Casting collapses every such variant to the canonical
    // integer before $uid is stored in form state and later used as a flood
    // identifier, preventing an over-length INSERT into flood.identifier.
    $uid = (int) $uid;

    // Validate hash validity (integrity and expiry).
    // Expiry: 60 seconds.
    if (!$uid || !$hash) {
      throw new AccessDeniedHttpException();
    }

    // Verify session binding to prevent direct access loopholes.
    $request = $this->requestStack->getCurrentRequest();
    $session = $request ? $request->getSession() : NULL;
    $session_uid = $session ? $session->get('wa_email_otp_uid') : NULL;

    if (!$session_uid || $session_uid != $uid) {
      throw new AccessDeniedHttpException();
    }

    $valid = $this->otpService->validate($uid, $hash);

    if (!$valid) {
      $this->messenger()->addError($this->t('Invalid or expired login link.'));
      return $this->redirect('user.login');
    }

    $form['uid'] = [
      '#type' => 'value',
      '#value' => $uid,
    ];

    $form['hash'] = [
      '#type' => 'value',
      '#value' => $hash,
    ];

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
      '#value' => $this->t('Resend OTP'),
      '#submit' => ['::resendOtp'],
      '#limit_validation_errors' => [['uid']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $otp_input = $form_state->getValue('otp_entry');
    $uid = $form_state->getValue('uid');
    $hash = $form_state->getValue('hash');
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request ? $request->getClientIp() : '0.0.0.0';

    $flood_name = 'wa_email_otp_entry_ip';
    if (!$this->flood->isAllowed($flood_name, 5, 3600, $ip)) {
      $form_state->setErrorByName('otp_entry', $this->t('Too many attempts. Please try again later.'));
      return;
    }

    $flood_name_user = 'wa_email_otp_entry_user';
    if (!$this->flood->isAllowed($flood_name_user, 5, 3600, $uid)) {
      $form_state->setErrorByName('otp_entry', $this->t('Too many attempts for this user. Please try again later.'));
      return;
    }

    // Verify OTP using service.
    if (!$this->otpService->verify($uid, $hash, $otp_input)) {
      $this->flood->register($flood_name, 3600, $ip);
      $this->flood->register($flood_name_user, 3600, $uid);
      $form_state->setErrorByName('otp_entry', $this->t('Invalid One-Time Password.'));
    }
  }

  /**
   * {@inheritdoc}
   */

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = $form_state->getValue('uid');

    // Load user.
    $user = $this->userStorage->load($uid);

    if ($user) {
      $request = $this->requestStack->getCurrentRequest();

      // Cleanup all OTPs for this user to ensure single-use.
      $this->otpService->cleanupAll($uid);

      // Cleanup session marker.
      if ($request && $request->hasSession()) {
        $request->getSession()->remove('wa_email_otp_uid');
      }

      // Finalize login with redirect interception.
      // The service handles: stripping ?destination, ob_start() buffering,
      // Location header interception, and candidate validation.
      $finalRedirectUrl = $this->safeLoginFinalizer->finalizeLogin(
        $user,
        $request
      );

      if ($request) {
        $this->flood->clear('wa_email_otp_entry_ip', $request->getClientIp());
        $this->flood->clear('wa_email_otp_entry_user', $uid);
      }

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
    $uid = $form_state->getValue('uid');

    // Flood control: Check limit BEFORE any OTP operations.
    $request = $this->requestStack->getCurrentRequest();

    // Strip ?destination to prevent RedirectResponseSubscriber from
    // overriding our setRedirect() with an unvalidated external URL.
    if ($request) {
      $request->query->remove('destination');
    }
    $client_ip = $request ? $request->getClientIp() : '0.0.0.0';
    $flood_name = 'wa_email_otp_resend_ip';

    if (!$this->flood->isAllowed($flood_name, 5, 3600, $client_ip)) {
      $this->otpService->cleanupAll($uid);
      $this->messenger()->addError($this->t('Too many resend attempts. Please try again later.'));
      return;
    }

    // Clear old OTPs.
    $this->otpService->cleanupAll($uid);

    // Generate new.
    $data = $this->otpService->generate($uid);
    $user = $this->userStorage->load($uid);

    if ($user && $this->otpService->sendOtp($user, $data['otp'])) {
      // Register flood event.
      $this->flood->register($flood_name, 3600, $client_ip);

      $this->messenger()->addStatus($this->t('A new OTP has been sent to your email.'));
      // Redirect to new hash.
      $form_state->setRedirect('wa_email_otp.login', ['uid' => $uid, 'hash' => $data['hash']]);
    }
    else {
      $this->messenger()->addError($this->t('Failed to send OTP. Please try again.'));
    }
  }

}
