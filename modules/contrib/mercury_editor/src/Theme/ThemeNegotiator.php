<?php

namespace Drupal\mercury_editor\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Determines the theme for Mercury Editor iFramed URLs.
 */
class ThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new AjaxBasePageNegotiator.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack used to retrieve the current request.
   */
  public function __construct(ConfigFactoryInterface $config_factory, RequestStack $request_stack) {
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritDoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    if ($route_match->getRouteObject() && $route_match->getRouteObject()->getOption('_mercury_editor_route')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    $theme = $this->configFactory->get('mercury_editor.settings')->get('edit_screen_theme');

    if (empty($theme)) {
      $theme = $this->configFactory->get('system.theme')->get('admin');
    }
    if (empty($theme)) {
      $theme = $this->configFactory->get('system.theme')->get('default');
    }
    return $theme;
  }

}
