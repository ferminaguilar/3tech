<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Core\Ajax\AfterCommand;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_paragraphs\Ajax\LayoutParagraphsEventCommand;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;
use Drupal\mercury_editor\Ajax\MercuryEditorSelectComponentCommand;
use Drupal\mercury_editor\Ajax\MercuryEditorUpdateStateCommand;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Drupal\layout_paragraphs\Controller\DuplicateController as LayoutParagraphsDuplicateController;
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
   * {@inheritDoc}
   */
  public function __construct(
    LayoutParagraphsLayoutTempstoreRepository $tempstore,
    EventDispatcherInterface $event_dispatcher,
    IFrameAjaxResponseWrapper $iframe_ajax_response_wrapper,
    MercuryEditorTempstore $mercury_editor_tempstore,
  ) {
    parent::__construct($tempstore, $event_dispatcher);
    $this->iFrameAjaxResponseWrapper = $iframe_ajax_response_wrapper;
    $this->mercuryEditorTempstore = $mercury_editor_tempstore;
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

    if ($this->needsRefresh()) {
      $layout = $this->renderLayout();
      $dom_selector = '[data-lpb-id="' . $this->layoutParagraphsLayout->id() . '"]';
      $this->iFrameAjaxResponseWrapper->addCommand(new ReplaceCommand($dom_selector, $layout));
      $response->addCommand($this->iFrameAjaxResponseWrapper->getWrapperCommand());
      return $response;
    }

    $uuid = $duplicate_component->getEntity()->uuid();
    $rendered_item = [
      '#type' => 'layout_paragraphs_builder',
      '#layout_paragraphs_layout' => $this->layoutParagraphsLayout,
      '#uuid' => $uuid,
    ];
    $this->iFrameAjaxResponseWrapper->addCommand(new AfterCommand('[data-uuid="' . $source_uuid . '"]', $rendered_item));
    $this->iFrameAjaxResponseWrapper->addCommand(new LayoutParagraphsEventCommand($this->layoutParagraphsLayout, $uuid, 'component:update'));
    $response->addCommand($this->iFrameAjaxResponseWrapper->getWrapperCommand());
    $response->addCommand(new MercuryEditorSelectComponentCommand($uuid));

    return $response;
  }

}
