<?php

namespace Drupal\wa\Service;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
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
 * all of these, then validates redirect candidates so external URLs are
 * rejected without double-prepending subdirectory base paths.
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
   * @param array<int, \Drupal\Core\Url|string|null> $additionalCandidates
   *   Optional additional redirect URL candidates to consider after the
   *   intercepted ones (e.g., from hook_wa_login).
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
    $safeFallback = $this->normalizeLocalRedirectUrl($preLoginDestination, $request);
    self::$fallbackRedirectUrl = $safeFallback
      ?? Url::fromRoute('entity.user.canonical', ['user' => $uid])->toString();
    self::$interceptedRedirectUrl = NULL;
    self::$inSafeLogin = TRUE;

    // Record the output buffer level before opening our own buffer so we only
    // unwind buffers opened during user_login_finalize().
    $initialObLevel = ob_get_level();

    // (c) Output buffer with callback for universal $response->send()
    // defense. Rogue modules (login_destination, r4032login, etc.) may
    // call $response->send() during hook_user_login, which triggers:
    // - FPM: fastcgi_finish_request() -> php_output_end_all() -> callback
    // - Other SAPIs: Response::closeOutputBuffers() -> ob_end_flush()
    //   -> callback
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
      while (ob_get_level() > $initialObLevel) {
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
    $candidates = array_merge(
      [$preLoginDestination, $hookInjectedDestination, $interceptedRedirectUrl],
      $additionalCandidates
    );

    return $this->resolveRedirect($candidates, $uid, $request);
  }

  /**
   * Normalizes and validates a candidate redirect URL as a safe local path.
   *
   * Prevents open redirects (including whitespace, backslash, and
   * protocol-relative tricks) while preserving subdirectory base paths so
   * generated URLs (such as `/sub/wa_otp/5/abc`) are not prefixed twice.
   *
   * @param \Drupal\Core\Url|string|null $candidate
   *   The redirect candidate.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request (used to detect subdirectory base paths).
   *
   * @return string|null
   *   A safe local URL string ready for navigation/redirect, or NULL if the
   *   candidate is empty, malformed, or external.
   */
  public function normalizeLocalRedirectUrl(Url|string|null $candidate, Request $request): ?string {
    if ($candidate instanceof Url) {
      if ($candidate->isExternal()) {
        return NULL;
      }
      $resolved = $candidate->toString();
      return UrlHelper::isExternal($resolved) ? NULL : $resolved;
    }

    if (!is_string($candidate) || $candidate === '') {
      return NULL;
    }

    // Reject anything that does not begin with '/', '?', or '#', or that
    // Drupal's UrlHelper flags as external (e.g. //evil.com, /\evil.com).
    if (!str_starts_with($candidate, '/') && !str_starts_with($candidate, '?') && !str_starts_with($candidate, '#')) {
      return NULL;
    }
    if (UrlHelper::isExternal($candidate)) {
      return NULL;
    }

    // If Drupal is installed in a subdirectory (basePath e.g. '/sub') and the
    // string already carries that prefix (e.g. from Url::toString() or a
    // Location header), strip it before passing to Url::fromUserInput() so the
    // base path is not prepended a second time.
    $input = $candidate;
    $basePath = $request->getBasePath();
    if ($basePath !== '' && ($input === $basePath || str_starts_with($input, $basePath . '/'))) {
      $input = substr($input, strlen($basePath));
      if ($input === '') {
        $input = '/';
      }
    }

    try {
      $resolved = Url::fromUserInput($input)->toString();
    }
    catch (\Throwable $e) {
      return NULL;
    }

    if (UrlHelper::isExternal($resolved)) {
      return NULL;
    }

    return $resolved;
  }

  /**
   * Validates redirect candidates and returns the first valid local URL.
   *
   * @param array<int, \Drupal\Core\Url|string|null> $candidates
   *   An array of URL objects or strings to validate.
   * @param string|int $uid
   *   The user ID for logging.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string|null
   *   The first valid local redirect URL, or NULL if none are valid.
   */
  protected function resolveRedirect(array $candidates, string|int $uid, Request $request): ?string {
    foreach ($candidates as $candidate) {
      if ($candidate === NULL || $candidate === '') {
        continue;
      }
      $resolved = $this->normalizeLocalRedirectUrl($candidate, $request);
      if ($resolved !== NULL) {
        return $resolved;
      }
      $this->logger->warning('Redirect URL rejected for user @uid: @url', [
        '@uid' => $uid,
        '@url' => $candidate instanceof Url ? $candidate->toUriString() : (string) $candidate,
      ]);
    }
    return NULL;
  }

  /**
   * Returns the final validated redirect URL or the user canonical default.
   *
   * @param string|null $url
   *   The validated redirect URL, or NULL for default.
   * @param int|string $uid
   *   The user ID for the default redirect.
   *
   * @return string
   *   A safe local URL string.
   */
  public function getFinalRedirectUrl(?string $url, int|string $uid): string {
    if ($url !== NULL && $url !== '' && !UrlHelper::isExternal($url)) {
      return $url;
    }
    return Url::fromRoute('entity.user.canonical', ['user' => $uid])->toString();
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
    return new LocalRedirectResponse($this->getFinalRedirectUrl($url, $uid));
  }

}
