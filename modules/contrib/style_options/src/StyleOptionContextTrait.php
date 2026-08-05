<?php

namespace Drupal\style_options;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;

/**
 * Defines a trait for option plugin contexts (i.e. layouts, paragraphs).
 */
trait StyleOptionContextTrait {

  /**
   * Returns an array of option defitions for the handler.
   *
   * @return array
   *   An array of definitions.
   */
  protected function getStyleOptionContextDefinition() {
    return $this->styleOptionPluginConfigurationDiscovery()->getProcessedStyleOptionContextDefinition($this->getStyleOptionContextId(), $this->getPluginId());
  }

  /**
   * Get the options settings for this context.
   *
   * @return array
   *   The options settings.
   */
  protected function getStyleOptionContextOptions() {
    return $this->styleOptionPluginConfigurationDiscovery()->getContextOptions($this->getStyleOptionContextId(), $this->getPluginId());
  }

  /**
   * Gets the form item for a single option.
   *
   * @param string $option_id
   *   The option id.
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   * @param mixed $values
   *   The default value.
   *
   * @return array
   *   The form item.
   */
  protected function getStyleOptionPluginForm(string $option_id, array $form, FormStateInterface $form_state, $values = []) {
    if ($option_definition = $this
      ->styleOptionPluginConfigurationDiscovery()
      ->getOptionDefinition($option_id)) {

      $instance = $this->styleOptionPluginManager()->createInstance(
        $option_definition['plugin'],
        $option_definition,
      );
      $instance->setValues($values);
      $subform = [];
      return $instance->buildConfigurationForm(
        $subform,
        SubformState::createForSubform($subform, $form, $form_state)
      );
    }
  }

  /**
   * Decorates a build array with the option value.
   *
   * @param string $option_id
   *   The option id.
   * @param mixed $values
   *   The option value.
   * @param array $build
   *   The build array to decorate.
   *
   * @return array
   *   The decorated build array.
   */
  protected function buildStyleOptions(string $option_id, $values = [], array $build = []) {
    if ($instance = $this->getStyleOptionPlugin($option_id)) {
      $instance->setValues($values);
      return $instance->build($build);
    }
  }

  /**
   * Submits an option plugin form and returns the processed values.
   *
   * @param string $option_id
   *   The option id.
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return mixed
   *   The values.
   */
  protected function submitStyleOptionPluginForm(string $option_id, array $form, FormStateInterface $form_state) {
    $instance = $this->getStyleOptionPlugin($option_id);
    $subform_state = SubformState::createForSubform($form[$option_id], $form, $form_state);
    $instance->submitConfigurationForm($form[$option_id], $subform_state);
    return $instance->getValues();
  }

  /**
   * Returns an option plugin instance.
   *
   * @param string $option_id
   *   The option plugin id.
   *
   * @return \Drupal\style_options\Contracts\OptionPluginInterface
   *   The option plugin instance.
   */
  protected function getStyleOptionPlugin(string $option_id) {
    $option_definition = $this
      ->styleOptionPluginConfigurationDiscovery()
      ->getOptionDefinition($option_id);
    $instance = $this->styleOptionPluginManager()->createInstance(
      $option_definition['plugin'],
      $option_definition
    );
    return $instance;
  }

  /**
   * Returns the option plugin configuration discovery service.
   *
   * @return \Drupal\style_options\OptionPluginConfigurationDiscovery
   *   The option plugin configuration discovery service.
   */
  protected function styleOptionPluginConfigurationDiscovery() {
    return \Drupal::service('style_options.discovery');
  }

  /**
   * Returns the option attribute plugin manager service.
   *
   * @return \Drupal\style_options\Contracts\OptionPluginManagerInterface
   *   The option plugin configuration discovery service.
   */
  protected function styleOptionPluginManager() {
    return \Drupal::service('plugin.manager.style_options');
  }

}
