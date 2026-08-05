<?php

declare(strict_types=1);

namespace Drupal\style_options\Contracts;

use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Define the style option interface.
 */
interface StyleOptionPluginInterface extends PluginFormInterface, ConfigurableInterface {

  /**
   * Sets a single value.
   *
   * @param string $key
   *   The key.
   * @param mixed $value
   *   The value to set.
   *
   * @return $this
   */
  public function setValue(string $key, $value);

  /**
   * Sets the values.
   *
   * @param array $values
   *   The values.
   *
   * @return $this
   */
  public function setValues(array $values);

  /**
   * Gets a single value.
   *
   * @param string $key
   *   The key.
   *
   * @return mixed
   *   The value or null.
   */
  public function getValue(string $key);

  /**
   * Gets the values.
   *
   * @return array
   *   The values array.
   */
  public function getValues();

  /**
   * Returns the option id.
   *
   * @return string
   *   The option id.
   */
  public function getOptionId();

  /**
   * Formats a single value.
   *
   * @param string $value
   *   The value to format.
   *
   * @return mixed
   *   The formatted value.
   */
  public function formatValue($value);

  /**
   * Decorates a build array with the option values.
   *
   * @param array $build
   *   The build array to decorate.
   *
   * @return array
   *   The decorated build array.
   */
  public function build(array $build);

  /**
   * Builds the option configuration form.
   *
   * @param array $form
   *   The complete parent form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The option form.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state);

}
