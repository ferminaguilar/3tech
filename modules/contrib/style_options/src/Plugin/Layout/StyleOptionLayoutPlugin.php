<?php

namespace Drupal\style_options\Plugin\Layout;

use Drupal\Core\Form\SubformState;
use Drupal\Core\Layout\LayoutDefault;
use Drupal\Core\Form\FormStateInterface;
use Drupal\style_options\StyleOptionContextTrait;

/**
 * Provides an integration between Option Plugins and the Layout API.
 */
class StyleOptionLayoutPlugin extends LayoutDefault {

  use StyleOptionContextTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form['#process'][] = [$this, 'processForm'];
    return $form;

  }

  /**
   * Processes the form.
   *
   * @param array $element
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The processed form.
   */
  public function processForm(array $element, FormStateInterface $form_state) {
    $layout_definition_ids = array_keys($this->getLayoutContextDefinition());
    $region_definitions = $this->getRegionContextDefinitions();

    // Only add region tabs if there is a combination of 2 or more layouts
    // and regions.
    if (!empty($layout_definition_ids) + count($region_definitions) >= 2) {
      $element['region_picker'] = [
        '#type' => 'radios',
        '#options' => [],
        '#value' => 'layout',
        '#attributes' => [
          'class' => ['so-horizontal-tabs__tab'],
        ],
        '#wrapper_attributes' => [
          'class' => ['so-horizontal-tabs__wrapper'],
        ],
      ];
      $element['#attached']['library'][] = 'style_options/horizontal_tabs';
    }
    if (count($layout_definition_ids)) {
      if (isset($element['region_picker'])) {
        $element['region_picker']['#options']['layout'] = $this->t('Entire Layout');
        $element['region_picker']['#default_value'] = 'layout';
      }
      $element['layout'] = [
        '#type' => 'container',
        '#title' => $this->t('Layout Settings'),
        '#states' => [
          'visible' => [
            ':input[name="layout_paragraphs[config][region_picker]"]' => ['value' => 'layout'],
          ],
        ],
      ];
      foreach ($layout_definition_ids as $option_id) {
        $values = $this->configuration['layout'][$option_id] ?? [];
        $element['layout'][$option_id] = $this->getStyleOptionPluginForm($option_id, $element, $form_state, $values);
      }
    }
    if (count($region_definitions)) {
      $regions = $this->getPluginDefinition()->getRegions();
      foreach ($region_definitions as $region_id => $definitions) {
        if (isset($element['region_picker'])) {
          $element['region_picker']['#options'][$region_id] = $this->t('@region Region', ['@region' => $regions[$region_id]['label']]);
        }
        $element['regions'][$region_id] = [
          '#type' => 'container',
          '#title' => $regions[$region_id]['label'],
          '#states' => [
            'visible' => [
              ':input[name="layout_paragraphs[config][region_picker]"]' => ['value' => $region_id],
            ],
          ],
        ];
        foreach (array_keys($definitions) as $option_id) {
          $values = $this->configuration['regions'][$region_id][$option_id] ?? [];
          $element['regions'][$region_id][$option_id] = $this->getStyleOptionPluginForm($option_id, $element, $form_state, $values);
        }
      }
    }
    return $element;
  }

  /**
   * {@inheritDoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    foreach (array_keys($this->getLayoutContextDefinition()) as $option_id) {
      $subform_state = SubformState::createForSubform($form['layout'], $form, $form_state);
      $this->configuration['layout'][$option_id] = $this->submitStyleOptionPluginForm($option_id, $form['layout'], $subform_state);
    }
    foreach ($this->getPluginDefinition()->getRegions() as $region => $region_info) {
      foreach (array_keys($this->getRegionContextDefinition($region)) as $option_id) {
        $subform_state = SubformState::createForSubform($form['regions'][$region], $form, $form_state);
        $this->configuration['regions'][$region][$option_id] = $this->submitStyleOptionPluginForm($option_id, $form['regions'][$region], $subform_state);
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $regions) {
    $build = parent::build($regions);

    $layout_definition_ids = array_keys($this->getLayoutContextDefinition());
    $region_definitions = $this->getRegionContextDefinitions();

    foreach ($layout_definition_ids as $option_id) {
      $values = $this->configuration['layout'][$option_id] ?? [];
      $build = $this->buildStyleOptions($option_id, $values, $build);
    }

    foreach ($region_definitions as $region_id => $definitions) {
      foreach (array_keys($definitions) as $option_id) {
        $values = $this->configuration['regions'][$region_id][$option_id] ?? [];
        $build[$region_id] = $this->buildStyleOptions($option_id, $values, $build[$region_id] ?? []);
      }
    }

    return $build;
  }

  /**
   * Returns an array of options that apply to the overall layout.
   *
   * @return array
   *   An array of options.
   */
  protected function getLayoutContextDefinition() {
    return array_filter(
      $this->getStyleOptionContextOptions(),
      function ($definition) {
        return $definition['layout'] !== FALSE;
      }
    );
  }

  /**
   * Returns a nested array of option ids for each region.
   *
   * @return array
   *   The option ids.
   */
  protected function getRegionContextDefinitions() {
    $region_definitions = [];
    foreach (array_keys($this->getPluginDefinition()->getRegions()) as $region) {
      foreach ($this->getRegionContextDefinition($region) as $option_id => $definition) {
        $region_definitions[$region][$option_id] = $definition;
      }
    }
    return $region_definitions;
  }

  /**
   * Returns an array of options that apply to a specific region.
   *
   * @param string $region
   *   The region.
   *
   * @return array
   *   An array of options for the provided region.
   */
  protected function getRegionContextDefinition(string $region) {
    return array_filter(
      $this->getStyleOptionContextOptions(),
      function ($definition) use ($region) {
        if (!empty($definition['regions'])) {
          if (is_array($definition['regions'])) {
            return in_array($region, $definition['regions']);
          }
          if ($definition['regions'] === TRUE) {
            return TRUE;
          }
        }
      }
    );
  }

  /**
   * Returns the context id.
   *
   * @return string
   *   The context id.
   */
  public static function getStyleOptionContextId() {
    return 'layout';
  }

}
