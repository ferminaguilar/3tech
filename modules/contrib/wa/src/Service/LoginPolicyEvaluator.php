<?php

namespace Drupal\wa\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * Evaluates hook_wa_login() implementations with fail-closed veto composition.
 *
 * Ensures that:
 * - Both nested (`[[...]]`) and flat (`['allowed_login' => FALSE]`) hook
 *   return structures are recognized, and malformed/non-array returns fail
 *   closed rather than silently allowing login.
 * - Hard policy vetoes (`allowed_login !== TRUE` without `step_up => TRUE`)
 *   always compose and override any step-up challenge (such as Email OTP),
 *   regardless of module execution order.
 * - Step-up callbacks (`step_up_callback`) only run if no module issued a hard
 *   veto, preventing side effects like sending OTP emails for denied logins.
 */
class LoginPolicyEvaluator {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a LoginPolicyEvaluator object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ModuleHandlerInterface $module_handler, LoggerChannelFactoryInterface $logger_factory) {
    $this->moduleHandler = $module_handler;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Evaluates all hook_wa_login() implementations for a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user attempting to complete passkey login.
   * @param bool $allowStepUp
   *   Whether step-up challenges (`step_up => TRUE`) should be considered.
   *   Pass FALSE to evaluate only hard policy vetoes.
   * @param string[] $completedStepUpModules
   *   Machine names of step-up modules already completed during this login
   *   ceremony. Any remaining uncompleted step-up modules will still run in
   *   sequence rather than being skipped.
   *
   * @return array{allowed_login: bool, redirect_url: \Drupal\Core\Url|string|null, is_step_up: bool, denied_by: string|null}
   *   The composed login decision.
   */
  public function evaluate(UserInterface $user, bool $allowStepUp = TRUE, array $completedStepUpModules = []): array {
    $hardVeto = FALSE;
    $hardVetoRedirect = NULL;
    $hardVetoModule = NULL;

    $stepUpEntry = NULL;
    $stepUpModule = NULL;

    $allowRedirect = NULL;

    $this->moduleHandler->invokeAllWith('wa_login', function (callable $hook, string $module) use ($user, $completedStepUpModules, &$hardVeto, &$hardVetoRedirect, &$hardVetoModule, &$stepUpEntry, &$stepUpModule, &$allowRedirect): void {
      $raw = $hook($user);

      // NULL or empty array means "no opinion / allow".
      if ($raw === NULL || $raw === []) {
        return;
      }

      if (!is_array($raw)) {
        $this->loggerFactory->get('wa')->error('Module @module returned a non-array value from hook_wa_login(); denying login (fail-closed).', [
          '@module' => $module,
        ]);
        if (!$hardVeto) {
          $hardVeto = TRUE;
          $hardVetoModule = $module;
        }
        return;
      }

      // Normalize flat associative returns (e.g. ['allowed_login' => FALSE])
      // into a list of entries so they never silently fail open.
      if (
        array_key_exists('allowed_login', $raw)
        || array_key_exists('redirect_url', $raw)
        || array_key_exists('step_up', $raw)
        || array_key_exists('step_up_callback', $raw)
      ) {
        $entries = [$raw];
      }
      else {
        $entries = $raw;
      }

      foreach ($entries as $entry) {
        if (!is_array($entry)) {
          $this->loggerFactory->get('wa')->error('Module @module returned a malformed entry from hook_wa_login(); denying login (fail-closed).', [
            '@module' => $module,
          ]);
          if (!$hardVeto) {
            $hardVeto = TRUE;
            $hardVetoModule = $module;
          }
          continue;
        }

        $isDenied = array_key_exists('allowed_login', $entry) && $entry['allowed_login'] !== TRUE;
        $isStepUp = !empty($entry['step_up']);

        if ($isDenied && $isStepUp) {
          if (!in_array($module, $completedStepUpModules, TRUE) && $stepUpEntry === NULL) {
            $stepUpEntry = $entry;
            $stepUpModule = $module;
          }
          continue;
        }

        if ($isDenied) {
          if (!$hardVeto) {
            $hardVeto = TRUE;
            $hardVetoModule = $module;
            if (!empty($entry['redirect_url'])) {
              $hardVetoRedirect = $entry['redirect_url'];
            }
          }
          continue;
        }

        if (!empty($entry['redirect_url'])) {
          $allowRedirect = $entry['redirect_url'];
        }
      }
    });

    // Hard vetoes always take precedence over step-up challenges and allow
    // redirects. Step-up callbacks are never executed when login is vetoed.
    if ($hardVeto) {
      return [
        'allowed_login' => FALSE,
        'redirect_url' => $hardVetoRedirect,
        'is_step_up' => FALSE,
        'denied_by' => $hardVetoModule,
      ];
    }

    if ($allowStepUp && $stepUpEntry !== NULL) {
      $stepUpRedirect = $stepUpEntry['redirect_url'] ?? NULL;
      if (isset($stepUpEntry['step_up_callback']) && is_callable($stepUpEntry['step_up_callback'])) {
        try {
          $callbackResult = $stepUpEntry['step_up_callback']($user);
          if ($callbackResult instanceof Url || is_string($callbackResult) || $callbackResult === NULL) {
            $stepUpRedirect = $callbackResult;
          }
          else {
            $this->loggerFactory->get('wa')->error('Module @module step_up_callback returned an unexpected type; denying login.', [
              '@module' => (string) $stepUpModule,
            ]);
            $stepUpRedirect = NULL;
          }
        }
        catch (\Throwable $e) {
          $this->loggerFactory->get('wa')->error('Module @module step_up_callback threw an exception: @message', [
            '@module' => (string) $stepUpModule,
            '@message' => $e->getMessage(),
          ]);
          $stepUpRedirect = NULL;
        }
      }

      return [
        'allowed_login' => FALSE,
        'redirect_url' => $stepUpRedirect,
        'is_step_up' => TRUE,
        'denied_by' => $stepUpModule,
      ];
    }

    return [
      'allowed_login' => TRUE,
      'redirect_url' => $allowRedirect,
      'is_step_up' => FALSE,
      'denied_by' => NULL,
    ];
  }

}
