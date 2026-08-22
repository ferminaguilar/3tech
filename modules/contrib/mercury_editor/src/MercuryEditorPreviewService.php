<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\mercury_editor\Ajax\MercuryAfterCommand;
use Drupal\mercury_editor\Ajax\MercuryAppendCommand;
use Drupal\mercury_editor\Ajax\MercuryBeforeCommand;
use Drupal\mercury_editor\Ajax\MercuryPrependCommand;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Drupal\mercury_editor\Ajax\MercuryEditorUpdateStateCommand;
use Drupal\mercury_editor\Ajax\MercuryMorphCommand;
use Drupal\mercury_editor\Event\RenderComponentPreviewEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service.
   */
  public function __construct(
    protected MercuryEditorContextService $mercuryEditorContext,
    protected MercuryEditorTempstore $tempstore,
    protected IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper,
    protected $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
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
   * Builds the preview ajax command for a Mercury Editor component.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $original_layout_paragraphs_layout
   *   The original layout paragraphs layout object before the operation.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The layout paragraphs layout object after the operation.
   * @param string $component_uuid
   *   The UUID of the component being rendered.
   * @param string $source_uuid
   *   The UUID of the source component, if applicable.
   * @param string $operation
   *   The operation being performed (e.g., 'insert', 'move').
   * @param string|null $source_selector
   *   The DOM selector used when an insert-style operation needs a source.
   *
   * @return \Drupal\Core\Ajax\CommandInterface
   *   The ajax command to apply in the preview iframe.
   */
  public function ajaxRenderComponent(
    LayoutParagraphsLayout $original_layout_paragraphs_layout,
    LayoutParagraphsLayout $layout_paragraphs_layout,
    string $component_uuid,
    string $source_uuid,
    string $operation,
    ?string $source_selector = NULL,
  ): CommandInterface {
    $render_array = $operation != 'delete'
      ? $this->buildComponentRenderArray($layout_paragraphs_layout, $component_uuid)
      : [];
    $event = new RenderComponentPreviewEvent(
      $original_layout_paragraphs_layout,
      $layout_paragraphs_layout,
      $component_uuid,
      $source_uuid,
      $operation,
      $render_array,
      RenderComponentPreviewEvent::TARGET_COMPONENT,
      $component_uuid,
      $source_selector ?: '[data-uuid="' . $source_uuid . '"]',
    );

    $this->eventDispatcher->dispatch($event, RenderComponentPreviewEvent::EVENT_NAME);
    return $this->buildPreviewCommand($layout_paragraphs_layout, $component_uuid, $operation, $event, $source_selector);
  }

  /**
   * Builds the layout render array used for a preview update.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The updated layout.
   *
   * @return array
   *   The render array.
   */
  public function buildLayoutRenderArray(LayoutParagraphsLayout $layout_paragraphs_layout): array {
    return [
      '#type' => 'layout_paragraphs_builder',
      '#layout_paragraphs_layout' => $layout_paragraphs_layout,
    ];
  }

  /**
   * Builds the component render array used for a preview update.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The updated layout.
   * @param string $component_uuid
   *   The component uuid to render.
   *
   * @return array
   *   The render array.
   */
  public function buildComponentRenderArray(LayoutParagraphsLayout $layout_paragraphs_layout, string $component_uuid): array {
    return [
      '#type' => 'layout_paragraphs_builder',
      '#layout_paragraphs_layout' => $layout_paragraphs_layout,
      '#uuid' => $component_uuid,
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Converts preview event state into the preview DOM command.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The updated layout.
   * @param string $component_uuid
   *   The component uuid that changed.
   * @param string $operation
   *   The operation being performed.
   * @param \Drupal\mercury_editor\Event\RenderComponentPreviewEvent $event
   *   The preview event.
   * @param string|null $source_selector
   *   The source selector for insert-style operations.
   *
   * @return \Drupal\Core\Ajax\CommandInterface
   *   The DOM command.
   */
  protected function buildPreviewCommand(
    LayoutParagraphsLayout $layout_paragraphs_layout,
    string $component_uuid,
    string $operation,
    RenderComponentPreviewEvent $event,
    ?string $source_selector = NULL,
  ): CommandInterface {
    if ($event->getRenderTarget() === RenderComponentPreviewEvent::TARGET_LAYOUT) {
      $selector = $event->getSelector() ?: '[data-lpb-id="' . $layout_paragraphs_layout->id() . '"]';
      return new MercuryMorphCommand($selector, $event->getRenderArray());
    }

    $selector = $event->getSelector();
    $active_uuid = $event->getRenderTargetUuid() ?: $component_uuid;
    if (!$selector) {
      $selector = '[data-uuid="' . $active_uuid . '"]';
    }

    if ($active_uuid !== $component_uuid) {
      return new MercuryMorphCommand($selector, $event->getRenderArray());
    }

    switch ($operation) {
      case 'before':
        return new MercuryBeforeCommand($event->getSelector() ?: $source_selector, $event->getRenderArray());

      case 'after':
        return new MercuryAfterCommand($event->getSelector() ?: $source_selector, $event->getRenderArray());

      case 'append':
        return new MercuryAppendCommand($event->getSelector() ?: $source_selector, $event->getRenderArray());

      case 'prepend':
        return new MercuryPrependCommand($event->getSelector() ?: $source_selector, $event->getRenderArray());

      case 'delete':
        if (empty($event->getRenderArray())) {
          return new RemoveCommand($selector);
        }
        return new MercuryMorphCommand($selector, $event->getRenderArray());

      case 'update':
      default:
        return new MercuryMorphCommand($selector, $event->getRenderArray());
    }
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
