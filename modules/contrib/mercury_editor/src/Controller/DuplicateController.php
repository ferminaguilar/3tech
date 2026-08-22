<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\layout_paragraphs\Ajax\LayoutParagraphsEventCommand;
use Drupal\layout_paragraphs\Controller\DuplicateController as LayoutParagraphsDuplicateController;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Drupal\mercury_editor\Ajax\MercuryEditorSelectComponentCommand;
use Drupal\mercury_editor\Ajax\MercuryEditorUpdateStateCommand;
use Drupal\mercury_editor\MercuryEditorPreviewService;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Class DuplicateController.
 *
 * Duplicates a component of a Layout Paragraphs Layout.
 * This extends the DuplicateController class from the Layout Paragraphs
 * module to add Mercury Editor specific functionality.
 */
class DuplicateController extends LayoutParagraphsDuplicateController {

  /**
   * The iframe ajax response wrapper service.
   *
   * @var \Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper
   */
  protected IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper;

  /**
   * The Mercury Editor tempstore service.
   *
   * @var \Drupal\mercury_editor\MercuryEditorTempstore
   */
  protected MercuryEditorTempstore $mercuryEditorTempstore;

  /**
   * The Mercury Editor preview service.
   *
   * @var \Drupal\mercury_editor\MercuryEditorPreviewService
   */
  protected MercuryEditorPreviewService $previewRendererService;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    LayoutParagraphsLayoutTempstoreRepository $tempstore,
    EventDispatcherInterface $event_dispatcher,
    IFrameAjaxResponseWrapper $iframe_ajax_response_wrapper,
    MercuryEditorTempstore $mercury_editor_tempstore,
    MercuryEditorPreviewService $preview_renderer_service,
  ) {
    parent::__construct($tempstore, $event_dispatcher);
    $this->iFrameAjaxResponseWrapper = $iframe_ajax_response_wrapper;
    $this->mercuryEditorTempstore = $mercury_editor_tempstore;
    $this->previewRendererService = $preview_renderer_service;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_paragraphs.tempstore_repository'),
      $container->get('event_dispatcher'),
      $container->get('mercury_editor.iframe_ajax_response_wrapper'),
      $container->get('mercury_editor.tempstore_repository'),
      $container->get('mercury_editor.preview'),
    );
  }

  /**
   * Duplicates a component and returns appropriate response.
   *
   * Overrides the parent method to add Mercury Editor specific functionality.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The layout paragraphs layout object.
   * @param string $source_uuid
   *   The source component to be cloned.
   *
   * @return array|\Drupal\Core\Ajax\AjaxResponse
   *   A build array or Ajax response.
   */
  public function duplicate(LayoutParagraphsLayout $layout_paragraphs_layout, string $source_uuid) {
    // Call parent method to handle all core duplication logic.
    $response = parent::duplicate($layout_paragraphs_layout, $source_uuid);

    // For successful operations, update Mercury Editor state.
    // We can determine this was successful if we're here and not blocked.
    if (!($response instanceof AjaxResponse && $this->isDialogResponse($response))) {
      $mercury_editor_entity = $this->mercuryEditorTempstore
        ->get($this->layoutParagraphsLayout->getEntity()->uuid());
      $this->mercuryEditorTempstore->saveState($mercury_editor_entity);
    }

    return $response;
  }

  /**
   * Checks if an Ajax response contains a dialog command.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The Ajax response to check.
   *
   * @return bool
   *   TRUE if the response contains a dialog command, FALSE otherwise.
   */
  private function isDialogResponse(AjaxResponse $response): bool {
    $commands = $response->getCommands();
    foreach ($commands as $command) {
      if ($command['command'] === 'openDialog') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds Ajax response for successful duplication.
   *
   * Overrides parent to provide Mercury Editor specific Ajax handling.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsComponent $duplicate_component
   *   The duplicated component.
   * @param string $source_uuid
   *   The source component UUID.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The Ajax response.
   */
  protected function successfulAjaxResponse($duplicate_component, string $source_uuid): AjaxResponse {
    $mercury_editor_entity = $this->mercuryEditorTempstore
      ->get($this->layoutParagraphsLayout->getEntity()->uuid());

    $response = new AjaxResponse();
    $response->addCommand(new MercuryEditorUpdateStateCommand(
      $this->mercuryEditorTempstore,
      $mercury_editor_entity
    ));

    $preview_command = $this->previewRendererService->ajaxRenderComponent(
      $this->originalLayoutParagraphsLayout,
      $this->layoutParagraphsLayout,
      $duplicate_component->getEntity()->uuid(),
      $source_uuid,
      'after',
      '[data-uuid="' . $source_uuid . '"]',
    );

    $uuid = $duplicate_component->getEntity()->uuid();
    $this->iFrameAjaxResponseWrapper->addCommand($preview_command);
    $this->iFrameAjaxResponseWrapper->addCommand(new LayoutParagraphsEventCommand($this->layoutParagraphsLayout, $uuid, 'component:insert'));
    $response->addCommand($this->iFrameAjaxResponseWrapper->getWrapperCommand());
    $response->addCommand(new MercuryEditorSelectComponentCommand($uuid));

    return $response;
  }

}
