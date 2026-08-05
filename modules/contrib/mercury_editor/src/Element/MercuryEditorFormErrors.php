<?php

namespace Drupal\mercury_editor\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Element\ComponentElement;

/**
 * Provides a Mercury Editor form errors render element.
 *
 * @RenderElement("mercury_editor_form_errors")
 */
class MercuryEditorFormErrors extends ComponentElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    $info = parent::getInfo();
    $info['#component'] = 'mercury_editor:form-error-messages';
    $info['#form_errors'] = [];
    return $info;
  }

  /**
   * Pre-render callback for the Mercury Editor form errors element.
   *
   * @param array $element
   *   The render element array.
   *
   * @return array
   *   The processed render element array.
   */
  public function preRenderComponent(array $element): array {
    $element['#props'] = [
      'form_errors' => $element['#form_errors'] ?? [],
      'id' => Html::getUniqueId('me-form-error-messages'),
    ];
    $element = parent::preRenderComponent($element);
    return $element;
  }

}
