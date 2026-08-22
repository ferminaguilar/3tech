<?php

namespace Drupal\mercury_editor\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;

/**
 * Event fired before returning a Mercury Editor component preview render array.
 */
class RenderComponentPreviewEvent extends Event {

  const EVENT_NAME = 'mercury_editor.render_component_preview';

  const TARGET_COMPONENT = 'component';

  const TARGET_LAYOUT = 'layout';

  /**
   * Constructs the event.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $originalLayout
   *   The layout state before the operation.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout
   *   The layout state after the operation.
   * @param string $componentUuid
   *   The component uuid affected by the operation.
   * @param string $sourceUuid
   *   The source uuid associated with the operation.
   * @param string $operation
   *   The operation being performed.
   * @param array $renderArray
   *   The render array to return.
   * @param string $renderTarget
   *   The render target type.
   * @param string|null $renderTargetUuid
   *   The render target uuid when targeting a component.
   * @param string|null $selector
   *   The DOM selector to target in preview commands.
   */
  public function __construct(
    protected LayoutParagraphsLayout $originalLayout,
    protected LayoutParagraphsLayout $layout,
    protected string $componentUuid,
    protected string $sourceUuid,
    protected string $operation,
    protected array $renderArray,
    protected string $renderTarget = self::TARGET_COMPONENT,
    protected ?string $renderTargetUuid = NULL,
    protected ?string $selector = NULL,
  ) {
  }

  /**
   * Gets the original layout.
   */
  public function getOriginalLayout(): LayoutParagraphsLayout {
    return $this->originalLayout;
  }

  /**
   * Gets the updated layout.
   */
  public function getLayout(): LayoutParagraphsLayout {
    return $this->layout;
  }

  /**
   * Gets the changed component uuid.
   */
  public function getComponentUuid(): string {
    return $this->componentUuid;
  }

  /**
   * Gets the source uuid for the operation.
   */
  public function getSourceUuid(): string {
    return $this->sourceUuid;
  }

  /**
   * Gets the operation being performed.
   */
  public function getOperation(): string {
    return $this->operation;
  }

  /**
   * Gets the render array.
   */
  public function getRenderArray(): array {
    return $this->renderArray;
  }

  /**
   * Sets the render array.
   */
  public function setRenderArray(array $render_array): self {
    $this->renderArray = $render_array;
    return $this;
  }

  /**
   * Gets the render target type.
   */
  public function getRenderTarget(): string {
    return $this->renderTarget;
  }

  /**
   * Sets the render target type.
   */
  public function setRenderTarget(string $render_target): self {
    $this->renderTarget = $render_target;
    return $this;
  }

  /**
   * Gets the render target component uuid.
   */
  public function getRenderTargetUuid(): ?string {
    return $this->renderTargetUuid;
  }

  /**
   * Sets the render target component uuid.
   */
  public function setRenderTargetUuid(?string $render_target_uuid): self {
    $this->renderTargetUuid = $render_target_uuid;
    return $this;
  }

  /**
   * Gets the preview command selector.
   */
  public function getSelector(): ?string {
    return $this->selector;
  }

  /**
   * Sets the preview command selector.
   */
  public function setSelector(?string $selector): self {
    $this->selector = $selector;
    return $this;
  }

  /**
   * Sets the event to render the full layout.
   */
  public function renderLayout(array $render_array): self {
    $this->renderTarget = self::TARGET_LAYOUT;
    $this->renderTargetUuid = NULL;
    $this->selector = NULL;
    $this->renderArray = $render_array;
    return $this;
  }

  /**
   * Sets the event to render a specific component target.
   */
  public function renderComponent(string $render_target_uuid, array $render_array): self {
    $this->renderTarget = self::TARGET_COMPONENT;
    $this->renderTargetUuid = $render_target_uuid;
    $this->selector = '[data-uuid="' . $render_target_uuid . '"]';
    $this->renderArray = $render_array;
    return $this;
  }

}
