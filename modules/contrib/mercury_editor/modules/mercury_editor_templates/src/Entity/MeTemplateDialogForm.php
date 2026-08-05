<?php

namespace Drupal\mercury_editor_templates\Entity;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\layout_paragraphs\Utility\Dialog;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;

/**
 * Form controller for the Mercury Editor template entity edit forms.
 */
class MeTemplateDialogForm extends MeTemplateForm {

  use AjaxHelperTrait;
  use AjaxFormHelperTrait;

  /**
   * The layout paragraphs layout.
   *
   * @var \Drupal\layout_paragraphs\LayoutParagraphsLayout
   */
  protected $layoutParagraphsLayout;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?LayoutParagraphsLayout $layout_paragraphs_layout = NULL) {
    $this->layoutParagraphsLayout = $layout_paragraphs_layout;
    $form = parent::buildForm($form, $form_state);
    $form['status_messages'] = [
      '#type' => 'status_messages',
      '#include_fallback' => TRUE,
      '#weight' => -1000,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $form['#title'] = $this->t('Create a Template');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#ajax'] = [
      'progress' => [
        'type' => 'fullscreen',
      ],
      'callback' => '::ajaxSubmit',
    ];
    $actions['submit']['#attributes']['class'][] = 'lpb-btn--save';
    $actions['#attributes']['class'][] = 'me-form-actions';
    return $actions;
  }

  /**
   * Ajax callback for the submit button.
   */
  public function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $selector = Dialog::dialogSelector($this->layoutParagraphsLayout);
    $response->addCommand(new CloseDialogCommand($selector));
    $this->messenger()->deleteAll();
    return $response;
  }

}
