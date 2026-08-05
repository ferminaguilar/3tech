<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Render\Element;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormErrorHandlerInterface;
use Drupal\layout_paragraphs\Contracts\ComponentFormInterface;

/**
 * Decorates the FormErrorHandler core service for Mercury Editor.
 *
 * This decorator modifies error handling behavior specifically for Mercury
 * Editor entity and component forms, preventing error messages from being sent
 * to the messenger service since Mercury Editor handles form error rendering
 * differently.
 */
class MercuryEditorFormErrorHandlerDecorator implements FormErrorHandlerInterface {

  /**
   * The decorated form error handler.
   *
   * @var \Drupal\Core\Form\FormErrorHandlerInterface
   */
  protected FormErrorHandlerInterface $innerHandler;

  /**
   * Constructs a MercuryEditorFormErrorHandlerDecorator object.
   *
   * @param \Drupal\Core\Form\FormErrorHandlerInterface $inner_handler
   *   The decorated form error handler.
   */
  public function __construct(FormErrorHandlerInterface $inner_handler) {
    $this->innerHandler = $inner_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function handleFormErrors(array &$form, FormStateInterface $form_state) {
    if ($this->isMercuryEditorForm($form_state)) {
      // For Mercury Editor forms, we still need to set element errors,
      // but we don't want to display error messages through the messenger.
      if ($form_state->getErrors()) {
        // Only set element errors, skip displaying error messages.
        $this->setElementErrorsFromFormState($form, $form_state);
      }
      return $this;
    }

    // For non-Mercury Editor forms, use the original behavior.
    return $this->innerHandler->handleFormErrors($form, $form_state);
  }

  /**
   * Determines if the current form is a Mercury Editor form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return bool
   *   TRUE if this is a Mercury Editor form, FALSE otherwise.
   */
  protected function isMercuryEditorForm(FormStateInterface $form_state): bool {
    $form_object = $form_state->getFormObject();
    if (method_exists($form_object, 'getOperation')) {
      /** @var \Drupal\Core\Entity\ContentEntityFormInterface $form_object  */
      return $form_object->getOperation() === 'mercury_editor';
    }
    if ($form_object instanceof ComponentFormInterface) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Sets element errors from form state without displaying messages.
   *
   * This is a simplified version of the core FormErrorHandler's method
   * that only handles setting errors on form elements.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $elements
   *   An associative array containing the part of the form structure that will
   *   be processed. For recursion only; leave empty when calling this method.
   */
  protected function setElementErrorsFromFormState(array &$form, FormStateInterface $form_state, array &$elements = []): void {
    // Use the same logic as the core FormErrorHandler, but without messenger.
    if (empty($elements)) {
      // cspell:disable-next-line
      // phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.VariableRedeclaration
      $elements = &$form;
    }

    // Import Element class for traversing form structure.
    $element_children = Element::children($elements);

    // Recurse through all element children.
    foreach ($element_children as $key) {
      if (!empty($elements[$key])) {
        // Get the child by reference so that we can update the original form.
        $child = &$elements[$key];

        // Call self to traverse up the form tree.
        $this->setElementErrorsFromFormState($form, $form_state, $child);

        $children_errors = [];

        // Inherit all recorded "children errors" of the direct child.
        if (!empty($child['#children_errors'])) {
          $children_errors = $child['#children_errors'];
        }

        // Additionally store the errors of the direct child itself.
        if (!empty($child['#errors'])) {
          $child_parents = implode('][', $child['#array_parents']);
          $children_errors[$child_parents] = $child['#errors'];
        }

        if (!empty($elements['#children_errors'])) {
          $elements['#children_errors'] += $children_errors;
        }
        else {
          $elements['#children_errors'] = $children_errors;
        }

        // If this direct child belongs to a group populate the grouping
        // element.
        if (!empty($child['#group'])) {
          $parents = explode('][', $child['#group']);
          $group_element = NestedArray::getValue($form, $parents);
          if (isset($group_element['#children_errors'])) {
            $group_element['#children_errors'] = $group_element['#children_errors'] + $children_errors;
          }
          else {
            $group_element['#children_errors'] = $children_errors;
          }
          NestedArray::setValue($form, $parents, $group_element);
        }
      }
    }

    // Store the errors for this element on the element directly.
    $elements['#errors'] = $form_state->getError($elements);
  }

}
