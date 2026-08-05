<?php

declare(strict_types=1);

namespace Drupal\style_options\Plugin\StyleOption;

use Drupal\Core\Form\FormStateInterface;
use Drupal\style_options\StyleOptionStyleTrait;
use Drupal\style_options\Plugin\StyleOptionPluginBase;

/**
 * Define the image attribute option plugin.
 *
 * @StyleOption(
 *   id = "background_color",
 *   label = @Translation("Background Color"),
 * )
 */
class BackgroundColor extends StyleOptionPluginBase {

  use StyleOptionStyleTrait;

  /**
   * {@inheritDoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['color'] = [
      '#type' => 'color_spectrum',
      '#title' => $this->getLabel(),
      '#default_value' => $this->getValue('color') ?? $this->getDefaultValue(),
      '#settings' => $this->getConfiguration('settings'),
      '#wrapper_attributes' => [
        'class' => [$this->getConfiguration('css_class')],
      ],
      '#description' => $this->getConfiguration('description'),
    ];
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function build(array $build) {
    $value = $this->getValue('color') ?? NULL;
    if (!empty($value)) {
      if ($this->getConfiguration('method') == 'css') {
        $this->generateStyle($build, ['#color' => $value]);
      }
      else {
        $build['#attributes']['style'][] = "background-color: $value;";
      }
    }
    return $build;
  }

}
