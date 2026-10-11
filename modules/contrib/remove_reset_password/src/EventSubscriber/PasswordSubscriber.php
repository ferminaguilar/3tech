<?php

namespace Drupal\remove_reset_password\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PasswordSubscriber implements EventSubscriberInterface {

  protected $configFactory;

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['checkAccess', 30],
    ];
  }

  public function checkAccess(RequestEvent $event) {
    $request = $event->getRequest();
    $route_name = $request->attributes->get('_route');

    // Only target the password reset route.
    if ($route_name === 'user.pass') {
      $config = $this->configFactory->get('remove_reset_password.settings');
      $hide_button = $config->get('remove_reset_password_button');

      // If configured to hide, check user role.
      $current_user = \Drupal::currentUser();
      if ($hide_button && $current_user->isAnonymous()) {
        // Deny access.
        throw new AccessDeniedHttpException();
      }
    }
  }

}
