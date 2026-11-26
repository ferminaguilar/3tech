<?php

namespace Drupal\Tests\wa\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\RoleInterface;
use Drupal\wa\Controller\PasskeyController;
use Drupal\wa\Service\WebAuthnService;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Tests that the passkey notification email does not break registration.
 *
 * The passkey is stored before the notification is sent, so a problem with the
 * mail system must not be reported to the user as a failed registration.
 *
 * @group wa
 */
#[Group('wa')]
#[RunTestsInSeparateProcesses]
class PasskeyRegistrationNotificationTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The AAGUID of the stubbed authenticator.
   */
  const AAGUID = '00000000-0000-0000-0000-000000000000';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'views', 'wa'];

  /**
   * The user that registers a passkey.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * Captures the log messages of the test.
   *
   * @var object
   */
  protected $logs;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('wa', ['wa']);
    $this->installConfig(['wa']);

    $this->config('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [RoleInterface::AUTHENTICATED_ID => RoleInterface::AUTHENTICATED_ID])
      ->set('allowed_aaguids', [self::AAGUID])
      ->save();

    // The first user created is the super user: use the second one.
    $this->createUser();
    $this->user = $this->createUser([], 'passkey_user', FALSE, ['mail' => 'passkey_user@example.com']);
    $this->setCurrentUser($this->user);

    $this->logs = new class () implements LoggerInterface {

      use RfcLoggerTrait;

      /**
       * The captured messages, with their placeholders replaced.
       *
       * @var string[]
       */
      public array $errors = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if ($level <= 3) {
          $this->errors[] = strtr((string) $message, array_filter($context, 'is_scalar'));
        }
      }

    };
    $this->container->get('logger.factory')->addLogger($this->logs);

    // Replace the validation of the authenticator response by a valid result.
    $webauthn = $this->createMock(WebAuthnService::class);
    $webauthn->method('validateRegistrationRequest')->willReturn(new CredentialRecord(
      'credential-id-' . $this->randomMachineName(),
      'public-key',
      ['internal'],
      'none',
      new EmptyTrustPath(),
      Uuid::fromString(self::AAGUID),
      'public-key-bytes',
      'user-handle',
      0
    ));
    $this->container->set('wa.webauthn', $webauthn);
  }

  /**
   * Tests that a mail failure is logged and the registration still succeeds.
   */
  public function testMailFailureDoesNotFailRegistration(): void {
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->willThrowException(new \Exception('The "missing" plugin does not exist.'));
    $this->container->set('plugin.manager.mail', $mail_manager);

    $response = $this->registerPasskey();

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('ok', json_decode($response->getContent(), TRUE)['status']);
    $this->assertSame(1, $this->countStoredPasskeys(), 'The passkey is stored.');

    $this->assertCount(1, $this->logs->errors, 'Only the mail problem is logged as an error.');
    $this->assertStringContainsString('could not be sent', $this->logs->errors[0]);
    $this->assertStringContainsString('The "missing" plugin does not exist.', $this->logs->errors[0]);
    $this->assertStringNotContainsString('Passkey registration failed', $this->logs->errors[0]);

    // A mail problem is not a failed attempt: it does not count against the
    // flood limit.
    $this->assertTrue(
      $this->container->get('flood')->isAllowed('wa_register', 1, 3600, 'user:' . $this->user->id()),
      'The mail failure is not registered as a failed attempt.'
    );
  }

  /**
   * Tests that the notification is sent when the mail system works.
   */
  public function testNotificationIsSent(): void {
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with('wa', 'passkey_add_notification', 'passkey_user@example.com');
    $this->container->set('plugin.manager.mail', $mail_manager);

    $response = $this->registerPasskey();

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(1, $this->countStoredPasskeys());
    $this->assertSame([], $this->logs->errors);
  }

  /**
   * Tests that a stored passkey is never reported as a failed registration.
   *
   * Once the passkey is stored, whatever goes wrong with the notification, the
   * user must not see the "registration failed" error and the failure must not
   * be logged as one.
   *
   * @param string $kind
   *   The kind of failure the mail system throws. The failure is built in the
   *   test, because exceptions cannot be passed through a data provider when
   *   the tests run in separate processes.
   *
   * @dataProvider mailFailureProvider
   */
  #[DataProvider('mailFailureProvider')]
  public function testStoredPasskeyIsNeverReportedAsFailed(string $kind): void {
    $failure = match ($kind) {
      'exception' => new \Exception('The mail could not be sent.'),
      'runtime exception' => new \RuntimeException('The mail server is not reachable.'),
      'missing mail plugin' => new PluginNotFoundException('missing', 'The "missing" plugin does not exist.'),
      'type error' => new \TypeError('Argument #1 must be of type string, null given.'),
      'error' => new \Error('Call to a member function send() on null.'),
      default => new \LogicException('Unknown kind of failure: ' . $kind),
    };
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->method('mail')->willThrowException($failure);
    $this->container->set('plugin.manager.mail', $mail_manager);

    $response = $this->registerPasskey();
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(1, $this->countStoredPasskeys(), 'The passkey is stored.');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['status' => 'ok'], $body, 'The response has no error message or ticket.');
    $this->assertStringNotContainsStringIgnoringCase('registration failed', $response->getContent());
    foreach ($this->logs->errors as $error) {
      $this->assertStringNotContainsStringIgnoringCase('registration failed', $error);
    }
    $this->assertTrue(
      $this->container->get('flood')->isAllowed('wa_register', 1, 3600, 'user:' . $this->user->id()),
      'The user is not penalized for a problem after the passkey was stored.'
    );
  }

  /**
   * Provides the kinds of failure the mail system can throw.
   *
   * @return array<string, array<string>>
   *   The kinds, keyed by a description.
   */
  public static function mailFailureProvider(): array {
    return [
      'exception' => ['exception'],
      'runtime exception' => ['runtime exception'],
      'missing mail plugin' => ['missing mail plugin'],
      'type error' => ['type error'],
      'error' => ['error'],
    ];
  }

  /**
   * Tests that a failure to validate the registration is still reported.
   */
  public function testValidationFailureStillFailsRegistration(): void {
    $webauthn = $this->createMock(WebAuthnService::class);
    $webauthn->method('validateRegistrationRequest')
      ->willThrowException(new \Exception('Invalid attestation.'));
    $this->container->set('wa.webauthn', $webauthn);

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');
    $this->container->set('plugin.manager.mail', $mail_manager);

    $response = $this->registerPasskey();

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('error', json_decode($response->getContent(), TRUE)['status']);
    $this->assertSame(0, $this->countStoredPasskeys());
    $this->assertFalse(
      $this->container->get('flood')->isAllowed('wa_register', 1, 3600, 'user:' . $this->user->id()),
      'A failed registration counts against the flood limit.'
    );
  }

  /**
   * Tests that registration is rejected when the user has not re-authenticated.
   */
  public function testRegistrationRejectedWithoutReauth(): void {
    $response = $this->registerPasskey(FALSE);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertTrue($body['reauth_required'] ?? FALSE);
    $this->assertSame(0, $this->countStoredPasskeys());
  }

  /**
   * Calls the registration endpoint with a valid challenge in the session.
   *
   * @param bool $includeReauth
   *   Whether to populate a valid recent re-authentication marker in session.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response of the controller.
   */
  protected function registerPasskey(bool $includeReauth = TRUE): JsonResponse {
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $request = Request::create('/wa/register/verify', 'POST', [], [], [], [], json_encode(['id' => 'credential']));
    $session = new Session(new MockArraySessionStorage());
    $session->set('wa_challenge', [
      'value' => 'challenge',
      'created' => $now,
    ]);
    if ($includeReauth) {
      $session->set(PasskeyController::REAUTH_SESSION_KEY, [
        'uid' => (int) $this->user->id(),
        'time' => $now,
      ]);
    }
    $request->setSession($session);
    $this->container->get('request_stack')->push($request);

    $controller = PasskeyController::create($this->container);
    return $controller->registerVerify($request);
  }

  /**
   * Counts the passkeys stored for the test user.
   *
   * @return int
   *   The number of passkeys.
   */
  protected function countStoredPasskeys(): int {
    return (int) $this->container->get('database')->select('wa', 'w')
      ->condition('uid', $this->user->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
