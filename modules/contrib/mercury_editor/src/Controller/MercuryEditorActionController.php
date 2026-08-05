<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\mercury_editor\DialogService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\layout_paragraphs\Utility\Dialog;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\mercury_editor\Form\EditComponentForm;
use Drupal\mercury_editor\Form\DeleteComponentForm;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\mercury_editor\Ajax\OpenMercuryDialogCommand;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\mercury_editor\Ajax\MercuryEditorSelectComponentCommand;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;

/**
 * Controller for handling Mercury Editor actions.
 */
class MercuryEditorActionController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly ClassResolverInterface $classResolver,
    private readonly IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper,
    private readonly LayoutParagraphsLayoutTempstoreRepository $layoutParagraphsTempstore,
    private readonly DialogService $dialogService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('class_resolver'),
      $container->get('mercury_editor.iframe_ajax_response_wrapper'),
      $container->get('layout_paragraphs.tempstore_repository'),
      $container->get('mercury_editor.dialog')
    );
  }

  /**
   * Runs the specified action and returns an AjaxResponse.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\Core\Entity\ContentEntityInterface $mercury_editor_entity
   *   The entity being edited in Mercury Editor.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The layout paragraphs layout.
   * @param string $action
   *   The action to perform.
   * @param string|null $component_uuid
   *   (optional) The UUID of the component to act upon.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The Ajax response.
   */
  public function run(
    Request $request,
    ContentEntityInterface $mercury_editor_entity,
    LayoutParagraphsLayout $layout_paragraphs_layout,
    string $action,
    ?string $component_uuid = NULL,
  ): AjaxResponse {

    // Validate/resolve your context here (permissions, workflow, etc.).
    $response = new AjaxResponse();

    switch ($action) {

      case 'insert':
        /** @var \Drupal\mercury_editor\Controller\ComponentDuplicateController $dup */
        $insert_controller = $this->classResolver->getInstanceFromDefinition(
          ChooseComponentController::class
        );
        // Assume it exposes a simple method you can call.
        $response = $insert_controller->list($request, $layout_paragraphs_layout);
        break;

      case 'edit':
        if (empty($component_uuid)) {
          return $response;
        }
        $component = $layout_paragraphs_layout->getComponentByUuid($component_uuid);
        if ($component === NULL) {
          return $response;
        }
        $form = $this
          ->formBuilder()
          ->getForm(
            EditComponentForm::class,
            $layout_paragraphs_layout,
            $component_uuid,
          );
        $component = $layout_paragraphs_layout->getComponentByUuid($component_uuid);
        $paragraph_type = $component?->getEntity()->getParagraphType()->id() ?? 'component';
        $paragraph_label = $component?->getEntity()->getParagraphType()->label ?? 'component';
        $response->addCommand(new OpenMercuryDialogCommand(
          '#' . Dialog::dialogId($layout_paragraphs_layout),
          $this->t('Edit %component_type', ['%component_type' => $paragraph_label]),
          $form,
          $this->dialogService->dialogSettings([
            'layout' => $layout_paragraphs_layout,
            'dialog' => $paragraph_type . '_form',
            'dock' => 'right',
          ])
        ));
        break;

      case 'edit-form':
        $response->addCommand(new MercuryEditorSelectComponentCommand($component_uuid));
        break;

      case 'duplicate':
        /** @var \Drupal\mercury_editor\Controller\ComponentDuplicateController $dup */
        $duplicate_controller = $this->classResolver->getInstanceFromDefinition(
          DuplicateController::class
        );
        // Assume it exposes a simple method you can call.
        $response = $duplicate_controller->duplicate($layout_paragraphs_layout, $component_uuid);
        break;

      case 'reorder':
        $reorder_controller = $this->classResolver->getInstanceFromDefinition(
          ReorderController::class
        );
        $response = $reorder_controller->build($request, $layout_paragraphs_layout);
        $rendered_layout = [
          '#type' => 'layout_paragraphs_builder',
          '#layout_paragraphs_layout' => $this->layoutParagraphsTempstore->get($layout_paragraphs_layout),
        ];
        $this
          ->iFrameAjaxResponseWrapper
          ->addCommand(new ReplaceCommand(
            '[data-lpb-id="' . $layout_paragraphs_layout->id() . '"]',
            $rendered_layout,
          ));
        $response->addCommand($this->iFrameAjaxResponseWrapper->getWrapperCommand());
        if ($request->query->has('component_uuid')) {
          $response->addCommand(
            new MercuryEditorSelectComponentCommand($request->query->get('component_uuid'))
          );
        }
        break;

      case 'delete':
        $form = $this
          ->formBuilder()
          ->getForm(
            DeleteComponentForm::class,
            $layout_paragraphs_layout,
            $component_uuid,
          );
        $response->addCommand(new OpenMercuryDialogCommand(
          '#' . Dialog::dialogId($layout_paragraphs_layout),
          $this->t('Delete component'),
          $form,
          [
            'width' => '400px',
            'height' => 'fit-content',
          ],
        ));
        break;
    }

    return $response;
  }

}
