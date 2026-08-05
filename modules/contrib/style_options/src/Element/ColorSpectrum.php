<?php

namespace Drupal\style_options\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Element\Textfield;

/**
 * Provides a color specturm field.
 *
 * Extends the textfield element and attaches the spectrum js library.
 * See https://bgrins.github.io/spectrum/.
 *
 * Usage example:
 * @code
 * $form['color'] = [
 *   '#type' => 'color_spectrum',
 *   '#title' => t('Choose a color'),
 *   '#pallette' => [
 *     ['#CC0000', '#E04800', '#F29300'],
 *     ['#FDC400', '#44284D', '#6C3E6C'],
 *   ],
 *   '#default_value' => '#fff',
 * ];
 * @endcode
 *
 * @RenderElement("color_spectrum")
 */
class ColorSpectrum extends Textfield {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $info = parent::getInfo();
    return $info;
  }

  /**
   * Prepares a #type 'color_spectrum' render element for input.html.twig.
   *
   * @param mixed $element
   *   An associative array containing the properties of the element.
   *   Properties used: #title, #value, #description, #size, #maxlength,
   *   #placeholder, #required, #attributes.
   *
   * @return array
   *   The $element with prepared variables ready for input.html.twig.
   */
  public static function preRenderTextfield($element) {
    $element = parent::preRenderTextfield($element);
    $color_spectrum_id = Html::getUniqueId('color-spectrum');
    $element['#attributes']['data-color-spectrum-id'] = $color_spectrum_id;
    $element['#attached']['library'][] = 'style_options/color_spectrum';
    if ($element['#settings']) {
      $element['#attached']['drupalSettings'][$color_spectrum_id] = $element['#settings'];
    }

    return $element;
  }

}
