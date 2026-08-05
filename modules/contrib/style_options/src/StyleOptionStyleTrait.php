<?php

namespace Drupal\style_options;

use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxHelperTrait;

/**
 * Defines a trait for attaching <style> tags to Option Plugins.
 */
trait StyleOptionStyleTrait {

  use AjaxHelperTrait;

  /**
   * Generates the <style> tag and attaches it to build array.
   *
   * @param array $build
   *   The build array.
   * @param array $variables
   *   An array variables.
   */
  protected function generateStyle(array &$build, array $variables) {
    $variables = [
      '#css_class' => $this->getCssClassName(),
    ] + $variables;

    $provider = $this->getPluginDefinition()['provider'] ?? 'style_options';
    $style = [
      '#theme' => $provider . '_' . $this->pluginId,
    ] + $variables;

    $build['#attributes']['class'][] = $variables['#css_class'];

    if ($this->isAjax()) {
      $rendered_style = $this->renderer->render($style);
      if (!empty($build['#suffix'])) {
        $combined_suffix = (string) $build['#suffix'] . (string) $rendered_style;
        $rendered_style = Markup::create($combined_suffix);
      }
      $build['#suffix'] = $rendered_style;
    }
    else {
      $build['#attached']['html_head'][] = [
        [
          '#type' => 'markup',
          '#markup' => $this->renderer->render($style),
        ],
        $variables['#css_class'],
      ];
    }
  }

  /**
   * Returns a css class name to use for the option.
   */
  protected function getCssClassName() {
    return Html::getUniqueId('option-plugin-' . $this->pluginId);
  }

}
