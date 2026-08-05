<?php

namespace Drupal\mercury_editor\Controller;

use Symfony\Component\HttpFoundation\Request;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\mercury_editor\Ajax\MercuryEditorUpdateStateCommand;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;
use Drupal\layout_paragraphs\Controller\ReorderController as LayoutParagraphsReorderController;

/**
 * Provides a controller for reordering Layout Paragraphs components.
 */
class ReorderController extends LayoutParagraphsReorderController {

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository $tempstore
   *   The Layout Paragraphs Layout tempstore repository.
   * @param \Drupal\mercury_editor\MercuryEditorTempstore $mercuryEditorTempstore
   *   The Mercury Editor tempstore repository.
   */
  public function __construct(
    LayoutParagraphsLayoutTempstoreRepository $tempstore,
    protected MercuryEditorTempstore $mercuryEditorTempstore,
  ) {
    $this->tempstore = $tempstore;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_paragraphs.tempstore_repository'),
      $container->get('mercury_editor.tempstore_repository'),
    );
  }

  /**
   * {@inheritDoc}
   *
   * Saves a state to the Mercury Editor tempstore.
   */
  public function build(Request $request, LayoutParagraphsLayout $layout_paragraphs_layout) {
    $response = parent::build($request, $layout_paragraphs_layout);
    if ($layout_paragraphs_layout->getSetting('mercury_editor_context')) {
      $mercury_editor_entity = $this->mercuryEditorTempstore
        ->get($layout_paragraphs_layout->getEntity()->uuid());
      $this->mercuryEditorTempstore->saveState($mercury_editor_entity);
      $response->addCommand(new MercuryEditorUpdateStateCommand(
        $this->mercuryEditorTempstore,
        $mercury_editor_entity
      ));
    }
    return $response;
  }

}
