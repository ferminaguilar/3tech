<?php

namespace Drupal\wa_email_otp\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\user\UserInterface;

/**
 * Service for generating and verifying Email OTPs.
 */
class OtpService {

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
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

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
   * Constructs an OtpService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(Connection $database, TimeInterface $time, MailManagerInterface $mail_manager, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->database = $database;
    $this->time = $time;
    $this->mailManager = $mail_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Generates a new OTP and stores it.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   An array containing 'otp' and 'hash'.
   */
  public function generate(int $uid): array {
    $otp = $this->randomCharacters(6, '0123456789');
    $hash = bin2hex(random_bytes(32));
    $timestamp = $this->time->getRequestTime();

    $this->database->insert('wa_email_otp')
      ->fields([
        'uid' => $uid,
        'otp' => password_hash($otp, PASSWORD_DEFAULT),
        'hash' => $hash,
        'created' => $timestamp,
      ])
      ->execute();

    return [
      'otp' => $otp,
      'hash' => $hash,
    ];
  }

  /**
   * Validates if a hash exists and is not expired.
   *
   * @param int $uid
   *   The user ID.
   * @param string $hash
   *   The hash to validate.
   *
   * @return object|null
   *   The OTP record object if valid, NULL otherwise.
   */
  public function validate(int $uid, string $hash): ?object {
    $valid_window = 60;
    $timestamp = $this->time->getRequestTime();

    $query = $this->database->select('wa_email_otp', 't')
      ->fields('t', ['otp', 'created'])
      ->condition('uid', $uid)
      ->condition('hash', $hash)
      ->range(0, 1)
      ->execute();

    $record = $query->fetchObject();

    if (!$record) {
      return NULL;
    }

    if (($timestamp - $record->created) > $valid_window) {
      return NULL;
    }

    return $record;
  }

  /**
   * Verifies the OTP and cleans up if successful.
   *
   * @param int $uid
   *   The user ID.
   * @param string $hash
   *   The hash.
   * @param string $otp_input
   *   The OTP input to verify.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  public function verify(int $uid, string $hash, string $otp_input): bool {
    $record = $this->validate($uid, $hash);

    if (!$record) {
      return FALSE;
    }

    if (password_verify($otp_input, $record->otp)) {
      $this->cleanup($hash);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Deletes the OTP record.
   *
   * @param string $hash
   *   The hash to delete.
   */
  public function cleanup(string $hash): void {
    $this->database->delete('wa_email_otp')
      ->condition('hash', $hash)
      ->execute();
  }

  /**
   * Deletes all OTP records for a user.
   *
   * @param int $uid
   *   The user ID.
   */
  public function cleanupAll(int $uid): void {
    $this->database->delete('wa_email_otp')
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Generate random characters of the given length and allowable characters.
   *
   * @param int $length
   *   The desired length of the returned string.
   * @param string $allowable_characters
   *   Characters that are allowed to be return in the generated string.
   *
   * @return string
   *   Random string of given length and allowed characters.
   */
  protected function randomCharacters(int $length, string $allowable_characters): string {
    $len = strlen($allowable_characters);

    // Start with a blank string.
    $characters = '';

    // Loop the number of times specified by $length.
    for ($i = 0; $i < $length; $i++) {
      // Use rejection sampling to avoid modulo bias.
      // Only reject values that would create bias (256 % $len).
      $threshold = 256 - (256 % $len);
      do {
        $byte = ord(random_bytes(1));
      } while ($byte >= $threshold);

      // Use modulo to get index (now safe from bias).
      $index = $byte % $len;

      // Each iteration, pick a random character from the
      // allowable string and append it to the string we're building.
      $characters .= $allowable_characters[$index];
    }

    return $characters;
  }

  /**
   * Sends the OTP email to the user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to send the email to.
   * @param string $otp
   *   The OTP code.
   *
   * @return bool
   *   TRUE if email sent successfully, FALSE otherwise.
   */
  public function sendOtp(UserInterface $user, string $otp): bool {
    $module = 'wa_email_otp';
    $key = 'otp_login';
    $to = $user->getEmail();
    $params['otp'] = $otp;
    $params['user'] = $user;
    $langcode = $user->getPreferredLangcode();
    $send = TRUE;

    $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);

    if ($result['result'] !== TRUE) {
      $this->loggerFactory->get('wa_email_otp')->error('There was a problem sending the OTP email to @email.', ['@email' => $to]);
      return FALSE;
    }

    return TRUE;
  }

}
