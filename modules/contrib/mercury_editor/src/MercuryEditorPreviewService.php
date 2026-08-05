<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Drupal\mercury_editor\Ajax\MercuryEditorUpdateStateCommand;

/**
 * Service for handling Mercury Editor preview functionality.
 */
class MercuryEditorPreviewService {

  /**
   * The content entity being edited.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface
   */
  protected $entity;

  /**
   * Constructs a new MercuryEditorPreviewService.
   *
   * @param \Drupal\mercury_editor\MercuryEditorContextService $mercuryEditorContext
   *   The mercury editor context service.
   * @param \Drupal\mercury_editor\MercuryEditorTempstore $tempstore
   *   The mercury editor tempstore service.
   * @param \Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper
   *   The iframe ajax response wrapper service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    protected MercuryEditorContextService $mercuryEditorContext,
    protected MercuryEditorTempstore $tempstore,
    protected IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper,
    protected $entityTypeManager,
  ) {
    $this->entity = $this->mercuryEditorContext->getEntity();
  }

  /**
   * Build the AJAX response to update the preview.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   (optional) The content entity to preview. If not provided, the current
   *   entity in context will be used.
   * @param \Drupal\Core\Ajax\AjaxResponse|null $response
   *   (optional) An existing AjaxResponse to add commands to. If not provided,
   *   a new AjaxResponse will be created.
   */
  public function ajaxUpdatePreview(?ContentEntityInterface $entity = NULL, ?AjaxResponse $response = NULL): AjaxResponse {
    if ($entity) {
      $this->entity = $entity;
    }
    if (!$response) {
      $response = new AjaxResponse();
    }
    $selector = '[data-me-edit-screen-key="' . $this->entity->uuid() . '"]';
    $view_builder = $this->entityTypeManager->getViewBuilder($this->entity->getEntityTypeId());
    $langcode = $this->entity->language()->getId();
    $view = $view_builder->view($this->entity, 'full', $langcode);
    $this->iFrameAjaxResponseWrapper->addCommand(new ReplaceCommand($selector, $view));

    $response->addCommand(new MercuryEditorUpdateStateCommand(
      $this->tempstore,
      $this->entity,
    ));

    // Update the entity's title if it exists.
    $this->updateEntityTitle();
    $response->addCommand($this->iFrameAjaxResponseWrapper->getWrapperCommand());
    return $response;
  }

  /**
   * Updates the entity's title on the page via AJAX.
   */
  protected function updateEntityTitle() {
    // Check if the entity has a title field.
    $title_field = $this->getEntityTitleField();
    if (!$title_field) {
      return;
    }

    $title = $this->getEntityTitle();
    if (!$title) {
      return;
    }

    // Use the specific selector for Mercury Editor title fields.
    $selector = '[data-me-entity-title="' . $this->entity->uuid() . '"]';

    // Build the rendered field to replace the existing title field.
    $view_builder = $this->entityTypeManager->getViewBuilder($this->entity->getEntityTypeId());
    $field_view = $view_builder->viewField($this->entity->get($title_field), [
      'label' => 'hidden',
      'type' => 'string',
    ]);

    // Add the replace command for the title field.
    $this->iFrameAjaxResponseWrapper->addCommand(new ReplaceCommand($selector, $field_view));
  }

  /**
   * Gets the entity's title field name.
   *
   * @return string|null
   *   The title field name, or NULL if not found.
   */
  protected function getEntityTitleField() {
    $entity_type = $this->entity->getEntityType();

    // Check for common title fields.
    $title_fields = ['title', 'name', 'label'];

    foreach ($title_fields as $field_name) {
      if ($this->entity->hasField($field_name)) {
        return $field_name;
      }
    }

    // Check if the entity type has a defined title field.
    if ($entity_type->hasKey('label')) {
      $label_field = $entity_type->getKey('label');
      if ($this->entity->hasField($label_field)) {
        return $label_field;
      }
    }

    return NULL;
  }

  /**
   * Gets the entity's title value.
   *
   * @return string|null
   *   The title value, or NULL if not found.
   */
  protected function getEntityTitle() {
    $title_field = $this->getEntityTitleField();
    if (!$title_field) {
      return NULL;
    }

    if (!$this->entity->get($title_field)->isEmpty()) {
      $title_value = $this->entity->get($title_field)->value;
      return $title_value ? strip_tags($title_value) : NULL;
    }

    return NULL;
  }

}
