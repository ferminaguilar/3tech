<?php

namespace Drupal\mercury_editor_templates\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\layout_paragraphs\Utility\Dialog;
use Symfony\Component\HttpFoundation\Request;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\mercury_editor_templates\Entity\MeTemplate;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutRefreshTrait;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;

/**
 * Controller for inserting a template.
 */
class InsertTemplateController extends ControllerBase {

  use LayoutParagraphsLayoutRefreshTrait;

  /**
   * Constructs a new InsertTemplateController.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository $tempstore
   *   The Layout Paragraphs tempstore service.
   * @param \Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper
   *   The IFrame Ajax Response Wrapper service.
   */
  public function __construct(
    protected readonly LayoutParagraphsLayoutTempstoreRepository $tempstore,
    protected readonly IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper,
  ) {

  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_paragraphs.tempstore_repository'),
      $container->get('mercury_editor.iframe_ajax_response_wrapper')
    );
  }

  /**
   * Inserts a template into a layout.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The layout paragraphs layout.
   * @param \Drupal\mercury_editor_templates\Entity\MeTemplate $me_template
   *   The template to insert.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax response.
   */
  public function insertTemplate(Request $request, LayoutParagraphsLayout $layout_paragraphs_layout, MeTemplate $me_template) {
    $response = new AjaxResponse();
    $this->setLayoutParagraphsLayout($layout_paragraphs_layout);

    $parent_uuid = $request->query->get('parent_uuid');
    $region = $request->query->get('region');
    $sibling_uuid = $request->query->get('sibling_uuid');
    $placement = $request->query->get('placement');

    $source_components = $this->cloneList($me_template->content->referencedEntities());
    $paragraph_reference_field = $this->layoutParagraphsLayout->getParagraphsReferenceField();
    $list = $paragraph_reference_field->getValue();

    // Append to the end of the list if no sibling uuid is provided.
    $delta = count($source_components);

    // Set the parent uuid and region for all root level components being
    // inserted.
    $field_name = $this->layoutParagraphsLayout->getParagraphsReferenceField()->getFieldDefinition()->getName();
    if ($parent_uuid && $region) {
      foreach (array_keys($source_components) as $key) {
        $source_components[$key]->setParentEntity($this->layoutParagraphsLayout->getEntity(), $field_name);
        $settings = $source_components[$key]->getAllBehaviorSettings()['layout_paragraphs'];
        if (empty($settings['parent_uuid'])) {
          $settings['parent_uuid'] = $parent_uuid;
          $settings['region'] = $region;
          $source_components[$key]->setBehaviorSettings('layout_paragraphs', $settings);
        }
      }
    }

    // Find the delta of the sibling component and set the delta accordingly.
    if ($sibling_uuid && $placement) {
      $delta = -1;
      foreach ($paragraph_reference_field as $key => $item) {
        if (isset($item->entity) && $item->entity->uuid() == $sibling_uuid) {
          $delta = $key;
          break;
        }
      }
      $delta += ($placement == 'before' ? 0 : 1);
    }

    // Splice the source components into the list at the correct delta.
    foreach ($source_components as $source_component) {
      array_splice($list, $delta, 0, ['entity' => $source_component]);
      $delta++;
    }

    $paragraph_reference_field->setValue($list);
    $this->layoutParagraphsLayout->setParagraphsReferenceField($paragraph_reference_field);
    $this->tempstore->set($this->layoutParagraphsLayout);
    $response = new AjaxResponse();
    $dom_selector = '[data-lpb-id="' . $this->layoutParagraphsLayout->id() . '"]';
    $dialog_selector = Dialog::dialogSelector($this->layoutParagraphsLayout);
    $response->addCommand(new CloseDialogCommand($dialog_selector));

    $this->iFrameAjaxResponseWrapper->addCommand(new ReplaceCommand($dom_selector, [
      '#type' => 'layout_paragraphs_builder',
      '#layout_paragraphs_layout' => $this->layoutParagraphsLayout,
    ]));
    $response->addCommand($this->iFrameAjaxResponseWrapper->getWrapperCommand());
    return $response;
  }

  /**
   * Clones an array of paragraph components, correctly mapping parent uuids.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph[] $list
   *   An array of paragraphs to clone.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph[]
   *   The cloned array with new parent uuids correctly mapped.
   */
  protected function cloneList(array $list) {
    foreach ($list as $delta => $item) {
      $uuid_map[$item->uuid()] = $delta;
      $cloned[$delta] = $item->createDuplicate();
      $settings = $cloned[$delta]->getAllBehaviorSettings()['layout_paragraphs'];
      if ($old_parent_uuid = $settings['parent_uuid']) {
        $settings['parent_uuid'] = $cloned[$uuid_map[$old_parent_uuid]]->uuid();
        $cloned[$delta]->setBehaviorSettings('layout_paragraphs', $settings);
      }
    }
    return $cloned;
  }

}
