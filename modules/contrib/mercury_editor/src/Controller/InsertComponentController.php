<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Core\Form\FormState;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenDialogCommand;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\mercury_editor\DialogService;
use Drupal\layout_paragraphs\Utility\Dialog;
use Symfony\Component\HttpFoundation\Request;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\layout_paragraphs\Event\LayoutParagraphsComponentDefaultsEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_paragraphs\Ajax\LayoutParagraphsEventCommand;
use Drupal\layout_paragraphs\Controller\ComponentFormController;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutRefreshTrait;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Drupal\mercury_editor\MercuryEditorPreviewService;

/**
 * InsertComponentController class definition.
 */
class InsertComponentController extends ComponentFormController {

  use LayoutParagraphsLayoutRefreshTrait;

  /**
   * {@inheritDoc}
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository $tempstore
   *   The Layout Paragraphs Layout tempstore repository.
   * @param \Drupal\mercury_editor\DialogService $mercuryEditorDialog
   *   The Mercury Editor dialog service.
   * @param \Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper $iframeAjaxResponseWrapper
   *   The IFrame Ajax response wrapper service.
   * @param \Drupal\mercury_editor\MercuryEditorPreviewService $previewRendererService
   *   The Mercury Editor preview service.
   */
  public function __construct(
    protected LayoutParagraphsLayoutTempstoreRepository $tempstore,
    protected DialogService $mercuryEditorDialog,
    protected IFrameAjaxResponseWrapper $iframeAjaxResponseWrapper,
    protected MercuryEditorPreviewService $previewRendererService,
  ) {

  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_paragraphs.tempstore_repository'),
      $container->get('mercury_editor.dialog'),
      $container->get('mercury_editor.iframe_ajax_response_wrapper'),
      $container->get('mercury_editor.preview')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function skipInsertForm(Request $request, LayoutParagraphsLayout $layout_paragraphs_layout, string $paragraph_type_id) {
    $skip_for_types = $this->config('mercury_editor.settings')->get('skip_create_form');
    $event = new LayoutParagraphsComponentDefaultsEvent($paragraph_type_id, []);
    $this->eventDispatcher()->dispatch($event, $event::EVENT_NAME);
    $paragraph_type = $this
      ->entityTypeManager()
      ->getStorage('paragraphs_type')
      ->load($event->getParagraphTypeId());

    // $skip_for_types[$paragraph_type->id()] = TRUE;
    if (isset($skip_for_types[$paragraph_type->id()])) {

      $response = new AjaxResponse();

      $this->setLayoutParagraphsLayout($layout_paragraphs_layout);

      $parent_uuid = $request->query->get('parent_uuid');
      $region = $request->query->get('region');
      $sibling_uuid = $request->query->get('sibling_uuid');
      $placement = $request->query->get('placement');

      $entity_type = $this->entityTypeManager()->getDefinition('paragraph');
      $bundle_key = $entity_type->getKey('bundle');
      /** @var \Drupal\paragraphs\Entity\Paragraph $paragraph */
      $paragraph = $this->entityTypeManager->getStorage('paragraph')
        ->create([$bundle_key => $paragraph_type->id()]);

      $form_state = new FormState();
      $args = [
        $layout_paragraphs_layout,
        $paragraph_type,
        $parent_uuid,
        $region,
        $sibling_uuid,
        $placement,
      ];

      $form_state
        ->addBuildInfo('args', $args);
      $form = $this->formBuilder()
        ->buildForm('\Drupal\mercury_editor\Form\InsertComponentForm', $form_state);

      /** @var \Drupal\mercury_editor\Form\InsertComponentForm */
      $form_object = $form_state->getFormObject();
      $form_state->setUserInput([]);
      $form_object->validateForm($form, $form_state);
      // @todo Do we need call $form_object->submitForm($form, $form_state)?
      $paragraph = $form_object->buildParagraphComponent($form, $form_state);

      if ($sibling_uuid && $placement) {
        switch ($placement) {
          case 'before':
            $this->layoutParagraphsLayout->insertBeforeComponent($sibling_uuid, $paragraph);
            break;

          case 'after':
            $this->layoutParagraphsLayout->insertAfterComponent($sibling_uuid, $paragraph);
            break;
        }
      }
      elseif ($parent_uuid && $region) {
        $this->layoutParagraphsLayout->insertIntoRegion($parent_uuid, $region, $paragraph);
      }
      else {
        $this->layoutParagraphsLayout->appendComponent($paragraph);
      }

      $this->tempstore->set($this->layoutParagraphsLayout);
      $source_selector = $sibling_uuid
        ? '[data-uuid="' . $sibling_uuid . '"]'
        : ($parent_uuid && $region
          ? '[data-region-uuid="' . $parent_uuid . '-' . $region . '"]'
          : '[data-lpb-id="' . $this->layoutParagraphsLayout->id() . '"]');
      $preview_command = $this->previewRendererService->ajaxRenderComponent(
        $this->originalLayoutParagraphsLayout,
        $this->layoutParagraphsLayout,
        $paragraph->uuid(),
        $sibling_uuid ?: ($parent_uuid ?: $paragraph->uuid()),
        $placement ?: ($parent_uuid && $region ? 'append' : 'prepend'),
        $source_selector,
      );

      $response->addCommand(new CloseDialogCommand(Dialog::dialogSelector($this->layoutParagraphsLayout)));
      $this->iframeAjaxResponseWrapper->addCommand($preview_command);
      $this->iframeAjaxResponseWrapper->addCommand(new LayoutParagraphsEventCommand($this->layoutParagraphsLayout, $paragraph->uuid(), 'component:insert'));
      $response->addCommand($this->iframeAjaxResponseWrapper->getWrapperCommand());
      return $response;
    }
    return $this->insertForm($request, $layout_paragraphs_layout, $paragraph_type->id());
  }

  /**
   * Returns the form, with ajax if appropriate.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The layout paragraphs layout object.
   *
   * @return array|AjaxResponse
   *   The form or ajax response.
   */
  protected function openForm(array $form, LayoutParagraphsLayout $layout_paragraphs_layout) {
    if ($this->isAjax()) {
      $context = [
        'layout' => $layout_paragraphs_layout,
        'form' => $form,
      ];
      if ($form['#paragraph']) {
        $context['paragraph'] = $form['#paragraph'];
        $context['paragraph_type'] = $form['#paragraph']->bundle();
        $context['dialog'] = $context['paragraph_type'] . '_form';
      }
      $response = new AjaxResponse();
      $selector = Dialog::dialogSelector($layout_paragraphs_layout);
      $response->addCommand(new OpenDialogCommand($selector, $form['#title'], $form, $this->mercuryEditorDialog->dialogSettings($context)));
      return $response;
    }
    return $form;
  }

  /**
   * Returns the insert component form class.
   */
  protected function getInsertComponentFormClass() {
    return '\Drupal\mercury_editor\Form\InsertComponentForm';
  }

}
