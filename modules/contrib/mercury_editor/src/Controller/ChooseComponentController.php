<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenDialogCommand;
use Drupal\mercury_editor\DialogService;
use Drupal\layout_paragraphs\Utility\Dialog;
use Symfony\Component\HttpFoundation\Request;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\layout_paragraphs\Event\LayoutParagraphsComponentDefaultsEvent;
use Drupal\layout_paragraphs\Controller\ChooseComponentController as LayoutParagraphsChooseComponentController;
use Drupal\mercury_editor\Ajax\OpenMercuryDialogCommand;

/**
 * Specifies the correct form to use for the component selection.
 */
class ChooseComponentController extends LayoutParagraphsChooseComponentController {

  /**
   * The Mercury Editor Dialog service.
   *
   * @var \Drupal\mercury_editor\DialogService
   */
  protected $mercuryEditorDialogService;

  /**
   * Construct a Layout Paragraphs Editor controller.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\mercury_editor\DialogService $mercury_editor_dialog
   *   The Mercury Editor Dialog service.
   */
  public function __construct(EntityTypeBundleInfoInterface $entity_type_bundle_info, EventDispatcherInterface $event_dispatcher, DialogService $mercury_editor_dialog) {
    parent::__construct($entity_type_bundle_info, $event_dispatcher);
    $this->mercuryEditorDialogService = $mercury_editor_dialog;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('event_dispatcher'),
      $container->get('mercury_editor.dialog')
    );
  }

  /**
   * {@inheritDoc}
   *
   * Explicitly form the ajax response to open the dialog in Mercury Editor.
   */
  public function list(Request $request, LayoutParagraphsLayout $layout_paragraphs_layout) {
    $list = parent::list($request, $layout_paragraphs_layout);
    if (!$list instanceof AjaxResponse && $this->isAjax()) {
      $response = new AjaxResponse();
      $selector = Dialog::dialogSelector($layout_paragraphs_layout);
      $response->addCommand(new OpenMercuryDialogCommand($selector, $list['#title'], $list, $this->mercuryEditorDialogService->dialogSettings([
        'layout' => $layout_paragraphs_layout,
        'dialog' => 'component_menu',
      ])));
      return $response;
    }
    return $list;
  }

  /**
   * Returns a layout paragraphs component form using Ajax if appropriate.
   *
   * @param string $type_name
   *   The component (paragraph) type.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The layout paragraphs layout object.
   * @param array $context
   *   The context for the new component.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse|array
   *   An ajax response or form render array.
   */
  protected function componentForm(string $type_name, LayoutParagraphsLayout $layout_paragraphs_layout, array $context) {

    // Dispatch a LayoutParagraphsComponentDefaultsEvent to allow other modules
    // to alter the paragraph type and default values.
    $event = new LayoutParagraphsComponentDefaultsEvent($type_name, []);
    $this->eventDispatcher->dispatch($event, $event::EVENT_NAME);
    $type = $this
      ->entityTypeManager()
      ->getStorage('paragraphs_type')
      ->load($event->getParagraphTypeId());

    $form = $this->formBuilder()->getForm(
      $this->getInsertComponentFormClass(),
      $layout_paragraphs_layout,
      $type,
      $context['parent_uuid'],
      $context['region'],
      $context['sibling_uuid'],
      $context['placement'],
      $event->getDefaultValues(),
    );
    if ($this->isAjax()) {
      $response = new AjaxResponse();
      $selector = Dialog::dialogSelector($layout_paragraphs_layout);
      $response->addCommand(new OpenDialogCommand($selector, $form['#title'], $form, $this->mercuryEditorDialogService->dialogSettings([
        'layout' => $layout_paragraphs_layout,
        'dialog' => $type_name . '_form',
      ])));
      return $response;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * The Mercury Editor forms provide the correct Ajax response when dealing
   * with iFrames.
   */
  protected function getInsertComponentFormClass() {
    return 'Drupal\mercury_editor\Form\InsertComponentForm';
  }

}
