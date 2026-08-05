<?php

namespace Drupal\mercury_editor_templates\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\layout_paragraphs\Utility\Dialog;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\mercury_editor_templates\Entity\MeTemplate;
use Drupal\layout_paragraphs\LayoutParagraphsComponent;
use Drupal\mercury_editor\Ajax\OpenMercuryDialogCommand;
use Drupal\mercury_editor\DialogService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for inserting a template.
 */
class SaveAsTemplate extends ControllerBase {

  use AjaxHelperTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * A Mercury Editor layout object.
   *
   * @var \Drupal\layout_paragraphs\LayoutParagraphsLayout
   */
  protected $layoutParagraphsLayout;

  /**
   * Constructs a SaveAsTemplate controller.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Drupal\mercury_editor\DialogService $dialog_service
   *   The Mercury Editor dialog service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FormBuilderInterface $form_builder,
    protected DialogService $dialog_service,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('mercury_editor.dialog'),
    );
  }

  /**
   * Save a paragraph entity as a template.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The paragraph entity to save.
   * @param string $uuid
   *   The uuid of the paragraph entity.
   *
   * @return array
   *   A render array.
   */
  public function templateForm(LayoutParagraphsLayout $layout_paragraphs_layout, string $uuid) {
    $this->layoutParagraphsLayout = $layout_paragraphs_layout;
    $duplicate_component = $this->layoutParagraphsLayout->duplicateComponent($uuid);
    $uuids = $this->getComponentUuids($duplicate_component);
    $field_content = [];
    foreach ($uuids as $uuid) {
      $entity = $this->layoutParagraphsLayout->getComponentByUuid($uuid)->getEntity();
      $field_content[] = [
        'entity' => $entity,
      ];
    }
    // The first paragraph should be the layout paragraphs container, with
    // its parent uuid and region removed.
    $layout_settings = $field_content[0]['entity']->getAllBehaviorSettings()['layout_paragraphs'];
    $layout_settings['parent_uuid'] = NULL;
    $layout_settings['region'] = NULL;
    $field_content[0]['entity']->setBehaviorSettings('layout_paragraphs', $layout_settings);

    $me_template = MeTemplate::create([
      'content' => $field_content,
      'status' => TRUE,
    ]);
    $form_object = $this->entityTypeManager->getFormObject('me_template', 'dialog')->setEntity($me_template);
    $form = $this->formBuilder->getForm($form_object, $this->layoutParagraphsLayout);
    if ($this->isAjax()) {
      $response = new AjaxResponse();
      $selector = Dialog::dialogSelector($layout_paragraphs_layout);
      $response->addCommand(new OpenMercuryDialogCommand(
        $selector,
        $this->t('Save as template'),
        $form,
        $this->dialog_service->dialogSettings([
          'layout' => $layout_paragraphs_layout,
          'dialog' => 'mercury_editor_template',
        ]),
      ));
      return $response;
    }
    return $form;
  }

  /**
   * Returns a list of all component uuids and descendent uuids in a layout.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsComponent $component
   *   The component to get uuids for.
   * @param array $uuids
   *   The uuids array.
   *
   * @return array
   *   An array of uuids.
   */
  protected function getComponentUuids(LayoutParagraphsComponent $component, array $uuids = []) {
    $uuids[] = $component->getEntity()->uuid();
    if ($component->isLayout()) {
      $section = $this->layoutParagraphsLayout->getLayoutSection($component->getEntity());
      foreach ($section->getComponents() as $component) {
        $uuids += $this->getComponentUuids($component, $uuids);
      }
    }
    return $uuids;
  }

}
