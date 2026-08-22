<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormStateInterface;
use Drupal\paragraphs\ParagraphsTypeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_paragraphs\Ajax\LayoutParagraphsEventCommand;
use Drupal\mercury_editor\MercuryEditorPreviewService;
use Drupal\mercury_editor\Ajax\MercuryEditorUpdateStateCommand;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;
use Drupal\layout_paragraphs\Form\InsertComponentForm as LayoutParagraphsInsertComponentForm;

/**
 * Renders a form for inserting a new component in Mercury Editor.
 */
class InsertComponentForm extends LayoutParagraphsInsertComponentForm {

  /**
   * Defaults for the paragraph.
   *
   * @var array
   */
  protected $paragraphDefaults = [];

  /**
   * {@inheritDoc}
   */
  public function __construct(
    LayoutParagraphsLayoutTempstoreRepository $tempstore,
    EntityTypeManagerInterface $entity_type_manager,
    LayoutPluginManagerInterface $layout_plugin_manager,
    ModuleHandlerInterface $module_handler,
    EventDispatcherInterface $event_dispatcher,
    EntityRepositoryInterface $entity_repository,
    protected MercuryEditorPreviewService $previewRendererService,
    protected IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper,
    protected MercuryEditorTempstore $mercuryEditorTempstore,
  ) {
    parent::__construct($tempstore, $entity_type_manager, $layout_plugin_manager, $module_handler, $event_dispatcher, $entity_repository);
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_paragraphs.tempstore_repository'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.core.layout'),
      $container->get('module_handler'),
      $container->get('event_dispatcher'),
      $container->get('entity.repository'),
      $container->get('mercury_editor.preview'),
      $container->get('mercury_editor.iframe_ajax_response_wrapper'),
      $container->get('mercury_editor.tempstore_repository'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?LayoutParagraphsLayout $layout_paragraphs_layout = NULL,
    ?ParagraphsTypeInterface $paragraph_type = NULL,
    ?string $parent_uuid = NULL,
    ?string $region = NULL,
    ?string $sibling_uuid = NULL,
    ?string $placement = NULL,
    array $paragraph_defaults = [],
  ) {
    $this->paragraphDefaults = $paragraph_defaults;
    $form = parent::buildForm($form, $form_state, $layout_paragraphs_layout, $paragraph_type, $parent_uuid, $region, $sibling_uuid, $placement);
    $form['error_messages'] = [
      '#type' => 'mercury_editor_form_errors',
      '#weight' => -1010,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $mercury_editor_entity = $this->mercuryEditorTempstore
      ->get($this->layoutParagraphsLayout->getEntity()->uuid());
    $this->mercuryEditorTempstore->saveState($mercury_editor_entity);
  }

  /**
   * {@inheritDoc}
   */
  public function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {

    $response = new AjaxResponse();
    $this->ajaxCloseForm($response);
    $uuid = $this->paragraph->uuid();
    $source_uuid = $this->siblingUuid ?: ($this->parentUuid ?: $uuid);
    $preview_command = $this->previewRendererService->ajaxRenderComponent(
      $this->originalLayoutParagraphsLayout,
      $this->layoutParagraphsLayout,
      $uuid,
      $source_uuid,
      $this->method,
      $this->domSelector,
    );
    $this->iFrameAjaxResponseWrapper->addCommand($preview_command);
    $this->iFrameAjaxResponseWrapper->addCommand(new LayoutParagraphsEventCommand($this->layoutParagraphsLayout, $uuid, 'component:insert'));
    $response->addCommand($this->iFrameAjaxResponseWrapper->getWrapperCommand());
    $response->addCommand(new MercuryEditorUpdateStateCommand(
      $this->mercuryEditorTempstore,
      $this->layoutParagraphsLayout->getEntity(),
    ));
    return $response;
  }

  /**
   * {@inheritDoc}
   */
  protected function newParagraph(ParagraphsTypeInterface $paragraph_type, string $langcode) {
    $entity_type = $this->entityTypeManager->getDefinition('paragraph');
    $langcode_key = $entity_type->getKey('langcode');
    $bundle_key = $entity_type->getKey('bundle');
    $values = [
      $bundle_key => $paragraph_type->id(),
      $langcode_key => $langcode,
      '_layoutParagraphsLayout' => $this->getLayoutParagraphsLayout(),
    ] + $this->paragraphDefaults;
    /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
    $paragraph = $this->entityTypeManager->getStorage('paragraph')
      ->create($values);
    return $paragraph;
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $form['error_messages']['#form_errors'] = $form_state->getErrors();
  }

}
