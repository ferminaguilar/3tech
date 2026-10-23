<?php

namespace Drupal\wa\Service;

use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Safely finalizes user login with redirect interception.
 *
 * Modules like login_destination and r4032login inject redirects during
 * hook_user_login in multiple ways:
 * 1. Setting the request's ?destination parameter (internal URLs).
 * 2. Calling $response->send() directly, flushing Location headers.
 * 3. Throwing EnforcedResponseException.
 *
 * This service wraps user_login_finalize() with protections to intercept
 * all of these, then validates redirect candidates through
 * Url::fromUserInput() which rejects external URLs.
 */
class SafeLoginFinalizer {

  /**
   * Whether a safe login finalization is in progress.
   *
   * Used by the ob_start callback to know whether to intercept Location
   * headers during buffer flush (triggered by fastcgi_finish_request on
   * PHP-FPM or closeOutputBuffers on other SAPIs).
   *
   * @var bool
   */
  public static bool $inSafeLogin = FALSE;

  /**
   * Safe fallback redirect URL for emergency interception.
   *
   * If a rogue module calls $response->send() during hook_user_login,
   * the ob_start callback replaces the external Location header with this
   * safe URL so the browser doesn't follow an open redirect.
   *
   * @var string|null
   */
  protected static ?string $fallbackRedirectUrl = NULL;

  /**
   * Location header URL intercepted by the ob_start callback.
   *
   * Captured during buffer flush so it can be fed into redirect candidate
   * validation after user_login_finalize() returns.
   *
   * @var string|null
   */
  protected static ?string $interceptedRedirectUrl = NULL;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a SafeLoginFinalizer.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('wa');
  }

  /**
   * Finalizes user login with full redirect interception.
   *
   * Calls user_login_finalize() while intercepting any redirect injections
   * from hook_user_login implementations. Returns a validated local redirect
   * URL or NULL if no redirect was requested.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to log in.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string[] $additionalCandidates
   *   Optional additional redirect URL candidates to consider after the
   *   intercepted ones (e.g., from hook_wa_login). These are validated
   *   through Url::fromUserInput() like all other candidates.
   *
   * @return string|null
   *   A validated local redirect URL string, or NULL if no valid redirect
   *   was found (caller should use a default).
   */
  public function finalizeLogin(UserInterface $user, Request $request, array $additionalCandidates = []): ?string {
    $uid = $user->id();

    // (a) Strip ?destination before login to prevent Drupal's redirect
    // system from honoring an unvalidated external URL.
    $preLoginDestination = $request->query->get('destination');
    $request->query->remove('destination');

    // (b) Compute a safe fallback redirect. If a rogue module commits a
    // response via $response->send() (fastcgi_finish_request on FPM,
    // closeOutputBuffers+flush on other SAPIs), the ob_start callback
    // replaces the external Location with this safe URL.
    $safeFallback = NULL;
    if ($preLoginDestination) {
      try {
        $safeFallback = Url::fromUserInput($preLoginDestination)->toString();
      }
      catch (\Throwable $e) {
        // External or invalid — use default.
      }
    }
    self::$fallbackRedirectUrl = $safeFallback
      ?? Url::fromRoute('entity.user.canonical', ['user' => $uid])->toString();
    self::$interceptedRedirectUrl = NULL;
    self::$inSafeLogin = TRUE;

    // (c) Output buffer with callback for universal $response->send()
    // defense. Rogue modules (login_destination, r4032login, etc.) may
    // call $response->send() during hook_user_login, which triggers:
    // - FPM: fastcgi_finish_request() → php_output_end_all() → callback
    // - Other SAPIs: Response::closeOutputBuffers() → ob_end_flush() → callback
    // In both cases the callback fires BEFORE sapi_send_headers() commits
    // headers to the client, so we can strip/replace the Location header.
    ob_start(function (string $buffer): string {
      if (SafeLoginFinalizer::$inSafeLogin && !headers_sent()) {
        foreach (headers_list() as $header) {
          if (stripos($header, 'Location:') === 0) {
            SafeLoginFinalizer::$interceptedRedirectUrl = trim(substr($header, 9));
            header_remove('Location');
            header_remove('Status');
            // Replace with safe fallback so the browser doesn't see a
            // blank page if fastcgi_finish_request() commits the response.
            header('Location: ' . SafeLoginFinalizer::$fallbackRedirectUrl, TRUE, 302);
            break;
          }
        }
      }
      // Discard any body output from rogue hooks.
      return '';
    });

    try {
      user_login_finalize($user);
    }
    finally {
      self::$inSafeLogin = FALSE;
      if (ob_get_level()) {
        ob_end_clean();
      }
    }

    // (d) Secondary defense for modules that set Location headers via
    // header() without calling $response->send(). The ob_start callback
    // would not have fired in this case.
    $interceptedRedirectUrl = self::$interceptedRedirectUrl;
    // @phpstan-ignore-next-line
    if (!$interceptedRedirectUrl && !headers_sent()) {
      foreach (headers_list() as $header) {
        if (stripos($header, 'Location:') === 0) {
          $interceptedRedirectUrl = trim(substr($header, 9));
          header_remove('Location');
          header_remove('Status');
          http_response_code(200);
          break;
        }
      }
    }

    // (e) Check if a module injected a destination during the hook.
    $hookInjectedDestination = $request->query->get('destination');
    $request->query->remove('destination');

    // (f) Build the final redirect URL.
    // Priority:
    // 1. Pre-existing ?destination from the original request.
    // 2. Destination injected by a module during hook_user_login.
    // 3. Location header intercepted from a rogue module.
    // 4. Additional candidates (e.g., from hook_wa_login).
    //
    // All candidates are validated through Url::fromUserInput() which
    // requires paths starting with /, ?, or # and rejects external URLs.
    $candidates = array_unique(array_merge(
      [$preLoginDestination, $hookInjectedDestination, $interceptedRedirectUrl],
      $additionalCandidates
    ));

    return $this->resolveRedirect($candidates, $uid);
  }

  /**
   * Validates redirect candidates and returns the first valid local URL.
   *
   * @param array $candidates
   *   An array of URL strings to validate.
   * @param string|int $uid
   *   The user ID for logging.
   *
   * @return string|null
   *   The first valid local redirect URL, or NULL if none are valid.
   */
  protected function resolveRedirect(array $candidates, string|int $uid): ?string {
    foreach ($candidates as $candidate) {
      if (!is_string($candidate) || $candidate === '') {
        continue;
      }
      try {
        return Url::fromUserInput($candidate)->toString();
      }
      catch (\Throwable $e) {
        $this->logger->warning('Redirect URL rejected for user @uid: @url', [
          '@uid' => $uid,
          '@url' => $candidate,
        ]);
      }
    }
    return NULL;
  }

  /**
   * Creates a LocalRedirectResponse for the given URL or a default.
   *
   * @param string|null $url
   *   The validated redirect URL, or NULL for default.
   * @param int|string $uid
   *   The user ID for the default redirect.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   The redirect response.
   */
  public function createRedirectResponse(?string $url, int|string $uid): LocalRedirectResponse {
    if ($url !== NULL) {
      return new LocalRedirectResponse($url);
    }
    return new LocalRedirectResponse(
      Url::fromRoute('entity.user.canonical', ['user' => $uid])->toString()
    );
  }

}
