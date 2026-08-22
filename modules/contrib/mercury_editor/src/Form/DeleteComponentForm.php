<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\layout_paragraphs\Utility\Dialog;
use Drupal\mercury_editor\MercuryEditorPreviewService;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_paragraphs\Ajax\LayoutParagraphsEventCommand;
use Drupal\mercury_editor\Ajax\MercuryEditorUpdateStateCommand;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;
use Drupal\layout_paragraphs\Form\DeleteComponentForm as LayoutParagraphsDeleteComponentForm;

/**
 * Class for deleting a component in Mercury Editor.
 */
class DeleteComponentForm extends LayoutParagraphsDeleteComponentForm {

  /**
   * {@inheritDoc}
   */
  protected function __construct(
    LayoutParagraphsLayoutTempstoreRepository $tempstore,
    protected MercuryEditorPreviewService $previewRendererService,
    protected IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper,
    protected MercuryEditorTempstore $mercuryEditorTempstore,
  ) {
    parent::__construct($tempstore);
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_paragraphs.tempstore_repository'),
      $container->get('mercury_editor.preview'),
      $container->get('mercury_editor.iframe_ajax_response_wrapper'),
      $container->get('mercury_editor.tempstore_repository'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Deletes the component and saves the layout.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $mercury_editor_entity = $this->mercuryEditorTempstore
      ->get($this->layoutParagraphsLayout->getEntity()->uuid());
    $this->mercuryEditorTempstore->saveState($mercury_editor_entity);
  }

  /**
   * Ajax callback - deletes component and closes the form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   */
  public function deleteComponent(array $form, FormStateInterface $form_state) {

    $mercury_editor_entity = $this->mercuryEditorTempstore
      ->get($this->layoutParagraphsLayout->getEntity()->uuid());
    $this->mercuryEditorTempstore->saveState($mercury_editor_entity);

    $response = new AjaxResponse();
    $response->addCommand(new MercuryEditorUpdateStateCommand(
      $this->mercuryEditorTempstore,
      $mercury_editor_entity
    ));
    $response->addCommand(new CloseDialogCommand(Dialog::dialogSelector($this->layoutParagraphsLayout)));

    $preview_command = $this->previewRendererService->ajaxRenderComponent(
      $this->originalLayoutParagraphsLayout,
      $this->layoutParagraphsLayout,
      $this->componentUuid,
      $this->componentUuid,
      'delete',
    );
    $this->iFrameAjaxResponseWrapper->addCommand($preview_command);
    $this->iFrameAjaxResponseWrapper->addCommand(new LayoutParagraphsEventCommand($this->layoutParagraphsLayout, $this->componentUuid, 'component:delete'));
    $response->addCommand($this->iFrameAjaxResponseWrapper->getWrapperCommand());
    return $response;
  }

}
