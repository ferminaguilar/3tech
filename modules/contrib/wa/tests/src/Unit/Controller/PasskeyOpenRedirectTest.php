<?php

namespace Drupal\Tests\wa\Unit\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;

/**
 * Tests open redirect prevention for the passkey login flow.
 *
 * Validates that malicious destination values are correctly identified and
 * rejected. The controller uses Url::fromUserInput() which requires input to
 * start with /, ?, or # — blocking all external URL bypass vectors.
 *
 * Scope of unit tests:
 * - UrlHelper::isExternal() bypass documentation (historical reference).
 * - Url::fromUserInput() precondition check (pure PHP, no container needed).
 * - LocalRedirectResponse secondary safety net via UrlHelper::isExternal().
 * - Candidates priority loop logic (precondition only).
 *
 * Tests requiring a Drupal container (Url::toString(), route resolution,
 * LocalRedirectResponse instantiation) live in the functional test class.
 *
 * @group wa
 */
class PasskeyOpenRedirectTest extends UnitTestCase {

  /**
   * Documents UrlHelper::isExternal() known bypass vectors.
   *
   * This test exists as a regression reference showing why the old code that
   * relied solely on !UrlHelper::isExternal() was vulnerable. The current
   * controller uses Url::fromUserInput() instead, which is not affected by
   * these bypasses.
   */
  public function testIsExternalKnownBypasses(): void {
    // Plain external URLs are correctly detected.
    $this->assertTrue(UrlHelper::isExternal('https://evil.com'));
    $this->assertTrue(UrlHelper::isExternal('//evil.com'));

    // Control characters (tab, newline, CR) ARE caught by \p{C}.
    $this->assertTrue(UrlHelper::isExternal("\thttps://evil.com"));
    $this->assertTrue(UrlHelper::isExternal("\nhttps://evil.com"));
    $this->assertTrue(UrlHelper::isExternal("\r\nhttps://evil.com"));

    // Backslash is normalised to / so /\evil.com → //evil.com → external.
    $this->assertTrue(UrlHelper::isExternal('/\\evil.com'));

    // BYPASS: Space (0x20) is NOT a \p{C} character.
    // isExternal() returns FALSE even though this is an external URL.
    $this->assertFalse(
      UrlHelper::isExternal(' https://evil.com'),
      'Space-prefixed URL bypasses isExternal() — historical vulnerability.'
    );

    // BYPASS: Non-breaking space (U+00A0) is \p{Zs}, not \p{C}.
    $this->assertFalse(
      UrlHelper::isExternal("\xC2\xA0https://evil.com"),
      'NBSP-prefixed URL bypasses isExternal() — historical vulnerability.'
    );
  }

  /**
   * Tests that Url::fromUserInput() rejects all known bypass vectors.
   *
   * Url::fromUserInput() requires input to start with /, ?, or #. This
   * precondition check is pure PHP and does not need the Drupal container.
   * Inputs failing it throw \InvalidArgumentException before any URL
   * resolution occurs, blocking ALL known bypass vectors unconditionally.
   *
   * @dataProvider maliciousDestinationProvider
   */
  public function testFromUserInputRejectsExternalUrls(string $input, string $label): void {
    $threw = FALSE;
    try {
      Url::fromUserInput($input);
    }
    catch (\InvalidArgumentException $e) {
      $threw = TRUE;
    }

    $this->assertTrue($threw,
      "Malicious input '$label' must cause Url::fromUserInput() to throw.");
  }

  /**
   * Data provider for malicious destination values.
   *
   * None of these start with /, ?, or # so Url::fromUserInput() throws
   * \InvalidArgumentException before any redirect can be issued.
   *
   * @return array<string, array{string, string}>
   *   Keyed test cases with input and human-readable label.
   */
  public static function maliciousDestinationProvider(): array {
    return [
      'plain https' => ['https://evil.com', 'https://evil.com'],
      'plain http' => ['http://evil.com', 'http://evil.com'],
      'space-prefixed (bypass)' => [' https://evil.com', 'space-prefixed https://evil.com'],
      'tab-prefixed' => ["\thttps://evil.com", 'tab-prefixed https://evil.com'],
      'newline-prefixed' => ["\nhttps://evil.com", 'newline-prefixed https://evil.com'],
      'CRLF-prefixed' => ["\r\nhttps://evil.com", 'CRLF-prefixed https://evil.com'],
      'NBSP-prefixed (bypass)' => ["\xC2\xA0https://evil.com", 'NBSP-prefixed https://evil.com'],
      'javascript protocol' => ['javascript:alert(1)', 'javascript: protocol'],
      'data protocol' => ['data:text/html,test', 'data: protocol'],
      'no scheme' => ['evil.com/path', 'no-scheme URL'],
    ];
  }

  /**
   * Tests valid local destinations pass the Url::fromUserInput() precondition.
   *
   * Url::fromUserInput() requires input to start with /, ?, or #. This
   * replicates the exact precondition check without calling the Drupal API,
   * which would require a container even for valid paths.
   *
   * @dataProvider validLocalDestinationProvider
   */
  public function testFromUserInputAcceptsLocalPaths(string $input): void {
    $passedPrecondition = str_starts_with($input, '/')
      || str_starts_with($input, '?')
      || str_starts_with($input, '#');

    $this->assertTrue($passedPrecondition,
      "Valid local destination '$input' must pass Url::fromUserInput() precondition.");
  }

  /**
   * Data provider for valid local destinations.
   *
   * @return array<string, array{string}>
   *   Keyed test cases with valid local destination values.
   */
  public static function validLocalDestinationProvider(): array {
    return [
      'root path' => ['/'],
      'user page' => ['/user/1'],
      'node page' => ['/node/1'],
      'admin page' => ['/admin'],
      'query string' => ['?foo=bar'],
      'fragment' => ['#section'],
      'path with query' => ['/user/1?tab=edit'],
    ];
  }

  /**
   * Tests that slash-prefixed external URLs are caught by UrlHelper.
   *
   * Both //evil.com and /\evil.com pass Url::fromUserInput()'s precondition
   * (they start with /). The secondary safety net is LocalRedirectResponse,
   * which calls isLocal() → !isExternal() before issuing any redirect. This
   * test confirms that isExternal() correctly identifies both patterns.
   *
   * LocalRedirectResponse instantiation requires the Drupal container and is
   * therefore tested in the functional test class instead.
   */
  public function testSlashPrefixedExternalUrlsIdentifiedByUrlHelper(): void {
    // Both pass the fromUserInput() precondition (they start with /).
    $this->assertTrue(str_starts_with('//evil.com', '/'));
    $this->assertTrue(str_starts_with('/\\evil.com', '/'));

    // But isExternal() correctly flags both as external, which means
    // LocalRedirectResponse::isLocal() returns FALSE and rejects them.
    $this->assertTrue(
      UrlHelper::isExternal('//evil.com'),
      '//evil.com must be flagged external by UrlHelper.'
    );
    $this->assertTrue(
      UrlHelper::isExternal('/\\evil.com'),
      '/\\evil.com must be flagged external by UrlHelper (backslash normalised to /).'
    );
  }

  /**
   * Tests the EnforcedResponseException catch block redirect validation.
   *
   * Simulates PasskeyController::loginVerify() lines 1020–1032: when a module
   * throws EnforcedResponseException with a RedirectResponse, the target URL
   * is validated through Url::fromUserInput(). External URLs (e.g. those
   * injected by login_destination pointing to google.com) must throw, causing
   * the controller to fall back to the user canonical page.
   *
   * Only the precondition check is tested here (no container required).
   * Route resolution and LocalRedirectResponse behaviour are tested in the
   * functional test class.
   *
   * @dataProvider enforcedResponseRedirectProvider
   */
  public function testEnforcedResponseRedirectValidation(
    string $targetUrl,
    bool $expectRejected,
    string $label,
  ): void {
    // Use the precondition check directly — Url::fromUserInput() itself
    // requires the Drupal container for valid paths (route resolution).
    $passedPrecondition = str_starts_with($targetUrl, '/')
      || str_starts_with($targetUrl, '?')
      || str_starts_with($targetUrl, '#');

    if ($expectRejected) {
      $this->assertFalse($passedPrecondition,
        "Redirect '$label' must fail the Url::fromUserInput() precondition.");
    }
    else {
      $this->assertTrue($passedPrecondition,
        "Redirect '$label' must pass the Url::fromUserInput() precondition.");
    }
  }

  /**
   * Data provider for EnforcedResponseException redirect validation.
   *
   * @return array<string, array{string, bool, string}>
   *   Keyed test cases with target URL, expected rejection flag, and label.
   */
  public static function enforcedResponseRedirectProvider(): array {
    return [
      // External URLs — rejected at precondition (do not start with / ? #).
      'google.com (login_destination)' => ['https://www.google.com', TRUE, 'external google.com'],
      'plain external URL' => ['https://evil.com', TRUE, 'external evil.com'],
      // Starts with / but isExternal catches it.
      'protocol-relative' => [
        '//evil.com',
        FALSE,
        '//evil.com passes precondition; LocalRedirectResponse rejects it',
      ],
      // Internal paths — pass precondition; LocalRedirectResponse accepts them.
      'internal admin path' => ['/admin/dashboard', FALSE, 'internal /admin/dashboard'],
      'internal user path' => ['/user/1', FALSE, 'internal /user/1'],
    ];
  }

  /**
   * Tests the candidates priority loop logic (precondition level).
   *
   * The controller iterates candidates in priority order and uses the first
   * one that passes Url::fromUserInput() without throwing. Verifies that
   * external URLs are skipped and the first valid internal candidate wins.
   */
  public function testCandidatesPriorityLoop(): void {
    $candidates = [
      // $preLoginDestination (absent).
      NULL,
      // Empty string (absent).
      '',
      // $hookInjectedDestination (external — must be skipped).
      'https://evil.com',
      // $interceptedRedirectUrl (local — must be selected).
      '/dashboard',
      // $redirect_url (local — lower priority).
      '/user/1',
    ];

    $finalRedirectUrl = NULL;
    $rejectedCount = 0;

    foreach ($candidates as $candidate) {
      if ($finalRedirectUrl !== NULL) {
        break;
      }
      if (!is_string($candidate) || $candidate === '') {
        continue;
      }
      // Replicate the Url::fromUserInput() precondition (pure PHP, no
      // container needed). External URLs fail this check.
      $passed = str_starts_with($candidate, '/')
        || str_starts_with($candidate, '?')
        || str_starts_with($candidate, '#');

      if ($passed) {
        $finalRedirectUrl = $candidate;
      }
      else {
        $rejectedCount++;
      }
    }

    $this->assertEquals(1, $rejectedCount,
      'Exactly one candidate (the external URL) must be rejected.');
    $this->assertSame('/dashboard', $finalRedirectUrl,
      '/dashboard must win over the lower-priority /user/1.');
  }

  /**
   * Tests that NULL and empty string candidates are skipped in the loop.
   */
  public function testCandidatesLoopSkipsNullAndEmpty(): void {
    $candidates = [NULL, '', NULL, '/node/1'];
    $finalRedirectUrl = NULL;

    foreach ($candidates as $candidate) {
      if (!is_string($candidate) || $candidate === '') {
        continue;
      }
      $passed = str_starts_with($candidate, '/')
        || str_starts_with($candidate, '?')
        || str_starts_with($candidate, '#');

      if ($passed) {
        $finalRedirectUrl = $candidate;
        break;
      }
    }

    $this->assertSame('/node/1', $finalRedirectUrl,
      'The loop must skip NULLs/empty strings and reach /node/1.');
  }

}
