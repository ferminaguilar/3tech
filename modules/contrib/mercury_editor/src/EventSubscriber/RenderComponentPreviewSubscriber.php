<?php

namespace Drupal\mercury_editor\EventSubscriber;

use Drupal\layout_paragraphs\Event\LayoutParagraphsUpdateLayoutEvent;
use Drupal\mercury_editor\Event\RenderComponentPreviewEvent;
use Drupal\mercury_editor\MercuryEditorPreviewService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Applies default render-target logic for Mercury preview updates.
 */
class RenderComponentPreviewSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\mercury_editor\MercuryEditorPreviewService $previewService
   *   The Mercury preview service.
   */
  public function __construct(
    protected EventDispatcherInterface $eventDispatcher,
    protected MercuryEditorPreviewService $previewService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      RenderComponentPreviewEvent::EVENT_NAME => 'onRenderComponentPreview',
    ];
  }

  /**
   * Applies the default layout-refresh behavior for preview updates.
   *
   * @param \Drupal\mercury_editor\Event\RenderComponentPreviewEvent $event
   *   The event.
   */
  public function onRenderComponentPreview(RenderComponentPreviewEvent $event): void {
    $refresh_event = new LayoutParagraphsUpdateLayoutEvent(
      $event->getOriginalLayout(),
      $event->getLayout(),
    );
    $this->eventDispatcher->dispatch($refresh_event, LayoutParagraphsUpdateLayoutEvent::EVENT_NAME);

    if ($refresh_event->needsRefresh) {
      $event->renderLayout($this->previewService->buildLayoutRenderArray($event->getLayout()));
    }
  }

}
