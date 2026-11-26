<?php

namespace Drupal\wa\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Service for handling passkey tickets and error logging.
 */
class PasskeyTicketService {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a PasskeyTicketService object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Generates a unique service ticket ID.
   *
   * @return string
   *   The ticket ID.
   */
  public function generateTicketId(): string {
    return uniqid('WA-');
  }

  /**
   * Logs an error with a ticket ID and returns a JSON error response.
   *
   * @param string $message
   *   The error message to display to the user.
   * @param string $logMessage
   *   The error message to log.
   * @param array<string, mixed> $context
   *   Additional context for the log.
   * @param int $statusCode
   *   The HTTP status code.
   * @param string $logLevel
   *   PSR-3 log level (defaults to LogLevel::ERROR).
   * @param array<string, mixed> $extraData
   *   Optional additional JSON fields to include in the response payload.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function logAndReturnError(string $message, string $logMessage, array $context = [], int $statusCode = 400, string $logLevel = LogLevel::ERROR, array $extraData = []): JsonResponse {
    $ticketId = $this->generateTicketId();
    $context['@ticket_id'] = $ticketId;
    $this->loggerFactory->get('wa')->log($logLevel, "$logMessage (Ticket ID: @ticket_id)", $context);

    return new JsonResponse(array_merge([
      'status' => 'error',
      'message' => $message,
      'ticket_id' => $ticketId,
    ], $extraData), $statusCode);
  }

}
