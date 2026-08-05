<?php

namespace Drupal\mercury_editor\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\mercury_editor\MercuryEditorComponentIcons;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element\FormElementBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mercury Editor icon select form element.
 *
 * @FormElement("mercury_editor_icon_select")
 */
class MercuryEditorIconSelect extends FormElementBase implements ContainerFactoryPluginInterface {

  /**
   * The Mercury Editor component icons service.
   *
   * @var \Drupal\mercury_editor\MercuryEditorComponentIcons
   */
  protected $componentIcons;

  /**
   * Constructs a MercuryEditorIconSelect element.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\mercury_editor\MercuryEditorComponentIcons $component_icons
   *   The Mercury Editor component icons service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MercuryEditorComponentIcons $component_icons,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->componentIcons = $component_icons;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('mercury_editor.component_icons')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#tree' => TRUE,
      '#process' => [
        [$class, 'processElement'],
      ],
      '#element_validate' => [
        [$class, 'validateElement'],
      ],
      '#theme_wrappers' => ['form_element'],
      '#options' => [],
      '#default_value' => '',
      '#required' => FALSE,
      '#disabled' => FALSE,
    ];
  }

  /**
   * Processes the icon select form element.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   *
   * @return array
   *   The processed form element.
   */
  public static function processElement(array &$element, FormStateInterface $form_state, array &$complete_form) {

    // Get the component icons service from the container
    $component_icons = \Drupal::service('mercury_editor.component_icons');

    // If no options are provided, use the default icon options
    if (empty($element['#options'])) {
      $element['#options'] = $component_icons->getIconOptions();
    }

    // Prepare options for the component
    $options = [];
    foreach ($element['#options'] as $value => $label) {
      $options[] = [
        'value' => (string) $value,
        'label' => (string) $label,
      ];
    }

    // Generate unique field ID
    $field_id = $element['#id'] ?? $element['#name'];

    // Construct field name from parents if available
    $field_name = $element['#name'];
    if (!empty($element['#parents'])) {
      $parents = $element['#parents'];
      $field_name = array_shift($parents);
      foreach ($parents as $parent) {
        $field_name .= '[' . $parent . ']';
      }
    }

    $default_value = $element['#default_value'] ?? '';
    $selected_value = is_array($default_value) ? (string) reset($default_value) : (string) $default_value;

    // If the default value is empty but options exist, use the first option
    if (empty($selected_value) && !empty($options)) {
      $selected_value = (string) $options[0]['value'];
    }

    // Render the icon select component
    $element['icon_select_component'] = [
      '#type' => 'component',
      '#component' => 'mercury_editor:component-icon-select',
      '#props' => [
        'component_id' => isset($element['#component_id']) ? (string) $element['#component_id'] : '',
        'options' => $options,
        'selected_value' => $selected_value,
        'field_id' => $field_id,
        'field_name' => $field_name,
        'required' => !empty($element['#required']),
        'disabled' => !empty($element['#disabled']),
      ],
    ];

    return $element;
  }

  /**
   * Validates the icon select form element.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   */
  public static function validateElement(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $value = $form_state->getValue($element['#parents']);

    // Extract value from the nested structure
    if (is_array($value) && isset($value['value'])) {
      $value = (string) $value['value'];
    }
    elseif (is_array($value)) {
      $value = (string) reset($value);
    }
    else {
      $value = (string) $value;
    }

    // Check if required and empty
    if (!empty($element['#required']) && empty($value)) {
      $form_state->setError($element, t('@name field is required.', ['@name' => $element['#title']]));
      return;
    }

    // Check if the selected value is valid
    if (!empty($value) && !empty($element['#options']) && !isset($element['#options'][$value])) {
      $form_state->setError($element, t('An invalid choice has been selected. Please choose a valid option.'));
      return;
    }

    // Set the final value for form state as a string
    $form_state->setValueForElement($element, $value);
  }

}
