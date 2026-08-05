<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Trait for adding ajax error messages to a response.
 */
trait AjaxFormErrorMessagesTrait {

  /**
   * Require a renderer() method for accessing the renderer service.
   *
   * @return \Drupal\Core\Render\RendererInterface
   *   The renderer service.
   */
  abstract protected function renderer(): RendererInterface;

  /**
   * Adds messages to an ajax response.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The ajax response.
   */
  protected function ajaxAddMessages(array $form, FormStateInterface $form_state, AjaxResponse $response) {
    $form_class = $form['#attributes']['class'][0]
      ? '.' . $form['#attributes']['class'][0] . ' '
      : '';
    $response->addCommand(new RemoveCommand($form_class . '.me-form-error-messages .messages'));
    if ($form_state->hasAnyErrors()) {
      $form_errors = $form_state->getErrors();
      $errorList = [
        '#theme' => 'item_list',
        '#items' => $form_errors,
      ];
      $errorList = $this->renderer->render($errorList);
      $response->addCommand(new MessageCommand($errorList, $form_class . '.me-form-error-messages', ['type' => 'error'], FALSE));
      // Format plural heading text with error count.
      $headingText = $this->formatPlural(
        count($form_errors),
        "Your changes couldn't be saved. Please fix the error below.",
        "Your changes couldn't be saved. Please fix the @count errors below."
      );
      $header = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $headingText,
        '#attributes' => [
          'class' => [
            'messages__title',
            'me-messages__title',
            'me-messages__title--error',
          ],
        ],
      ];
      $response->addCommand(new ReplaceCommand($form_class . '.me-form-error-messages .messages__title', $header));
    }
  }

}
