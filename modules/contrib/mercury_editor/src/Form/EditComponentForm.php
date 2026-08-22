<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\layout_paragraphs\Ajax\LayoutParagraphsEventCommand;
use Drupal\layout_paragraphs\Form\EditComponentForm as LayoutParagraphsEditComponentForm;
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
 * Class for editing a component in Mercury Editor.
 */
class EditComponentForm extends LayoutParagraphsEditComponentForm {

  use AjaxFormErrorMessagesTrait;

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
    ConfigFactoryInterface $configFactory,
    protected MercuryEditorPreviewService $previewRendererService,
    protected IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper,
    protected MercuryEditorTempstore $mercuryEditorTempstore,
    protected RendererInterface $renderer,
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
      $container->get('config.factory'),
      $container->get('mercury_editor.preview'),
      $container->get('mercury_editor.iframe_ajax_response_wrapper'),
      $container->get('mercury_editor.tempstore_repository'),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?LayoutParagraphsLayout $layout_paragraphs_layout = NULL,
    ?string $component_uuid = NULL,
  ) {
    $form = parent::buildForm($form, $form_state, $layout_paragraphs_layout, $component_uuid);
    $form['error_messages'] = [
      '#type' => 'mercury_editor_form_errors',
      '#weight' => -1010,
    ];
    $form['#attributes']['class'][] = 'me-form';
    if ($this->isAutosaveEnabled()) {
      $form['actions']['submit']['#attributes']['class'][] = 'me-autosave-btn';
      // @todo Decide which one to use.
      $form['#attributes']['class'][] = 'me-autosave-form';
      $form['#attributes']['class'][] = 'me-autosave';
    }
    else {
      $form['unsaved_changes_notice'] = [
        '#type' => 'markup',
        '#markup' => '<div class="unsaved-changes-notice-wrapper"><p class="unsaved-changes-notice" role="status">' . $this->t('Unsaved changes') . '</p></div>',
        '#weight' => -1005,
      ];
      // Custom button text for components without autosave enabled.
      $form['actions']['submit']['#value'] = $this->t('Apply Changes');
      $form['#attributes']['class'][] = 'me-autosave-form--disabled';
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // If the layout has changed and regions have been removed, move orphaned
    // items to the first available region.
    if ($selected_layout = $form_state->getValue(['layout_paragraphs', 'layout'])) {
      $section = $this->layoutParagraphsLayout->getLayoutSection($this->paragraph);
      if ($section && $selected_layout != $section->getLayoutId()) {

        $old_regions = $this->getLayoutRegionNames($section->getLayoutId());
        $new_regions = $this->getLayoutRegionNames($selected_layout);
        $default_region = array_key_first($new_regions);
        $section = $this->layoutParagraphsLayout->getLayoutSection($this->paragraph);

        foreach ($old_regions as $region_name => $region) {
          if ($section->getComponentsForRegion($region_name) && empty($new_regions[$region_name])) {
            $form_state->setValue([
              'layout_paragraphs',
              'move_items',
              $region_name,
            ], $default_region);
          }
        }
      }
    }
    parent::submitForm($form, $form_state);
    $mercury_editor_entity = $this->mercuryEditorTempstore
      ->get($this->layoutParagraphsLayout->getEntity()->uuid());
    $this->mercuryEditorTempstore->saveState($mercury_editor_entity);
    // Only rebuild the form when autosave is disabled, since the component form
    // will be reloaded via MercuryEditorSelectComponentCommand. When autosave is
    // enabled, the admin form is NOT replaced by the AJAX response, so
    // rebuilding causes the server's form structure (e.g., delta-to-entity
    // mapping in multi-value fields) to diverge from the client DOM, leading to
    // incorrect reorder behavior on subsequent saves.
    if (!$this->isAutosaveEnabled()) {
      $form_state->setRebuild();
    }
    else {
      // Enable form caching without rebuilding. During autosave, the admin form
      // DOM is not replaced by the AJAX response, so the browser retains the
      // original form element names and hidden input values. By caching the form
      // after the first autosave, subsequent AJAX requests load the cached form
      // tree whose #value mapping (delta -> target_id) matches the browser DOM,
      // instead of rebuilding a fresh form from the entity whose field order may
      // have changed due to previous autosaves.
      $form_state->setCached();
    }
  }

  /**
   * {@inheritDoc}
   *
   * Don't replace the form if there are errors.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    if (!$form_state->hasAnyErrors()) {
      $response = $this->successfulAjaxSubmit($form, $form_state);
    }
    $response->addCommand(new ReplaceCommand('.layout-paragraphs-component-form .me-form-error-messages__wrapper', $form['error_messages']));
    return $response;
  }

  /**
   * {@inheritDoc}
   */
  public function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $uuid = $this->paragraph->uuid();
    $this->iFrameAjaxResponseWrapper->addCommand(new SettingsCommand([
      'mercuryEditor' => [
        'activeComponentUuid' => $uuid,
      ],
    ], TRUE));
    $preview_command = $this->previewRendererService->ajaxRenderComponent(
      $this->originalLayoutParagraphsLayout,
      $this->layoutParagraphsLayout,
      $uuid,
      $uuid,
      'update',
    );
    $this->iFrameAjaxResponseWrapper->addCommand($preview_command);
    $this->iFrameAjaxResponseWrapper->addCommand(new LayoutParagraphsEventCommand($this->layoutParagraphsLayout, $uuid, 'component:update', ['editing' => TRUE]));
    $response->addCommand($this->iFrameAjaxResponseWrapper->getWrapperCommand());
    $response->addCommand(new MercuryEditorUpdateStateCommand(
      $this->mercuryEditorTempstore,
      $this->layoutParagraphsLayout->getEntity(),
    ));
    // If autosave is not enabled, re-select the component to reload its form.
    if (!$this->isAutosaveEnabled()) {
      $response->addCommand(new MercuryEditorSelectComponentCommand($this->paragraph->uuid(), TRUE));
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function orphanedItemsElement(array &$form, $form_state) {
    // In Mercury Editor, we do not provide an interface for moving orphaned
    // items, so we do not need to implement this method.
  }

  /**
   * Returns the renderer service.
   *
   * @return \Drupal\Core\Render\RendererInterface
   *   The renderer service.
   */
  protected function renderer(): RendererInterface {
    return $this->renderer;
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $form['error_messages']['#form_errors'] = $form_state->getErrors();
  }

  /**
   * Determines if autosave is enabled.
   *
   * @return bool
   *   TRUE if autosave is enabled, FALSE otherwise.
   */
  protected function isAutosaveEnabled(): bool {
    $settings = $this->configFactory->get('mercury_editor.settings')->get('auto_update') ?? [];
    if (($settings['enabled'] ?? TRUE) === FALSE) {
      return FALSE;
    }
    $paragraph_overrides = $settings['paragraphs'] ?? [];
    return empty($paragraph_overrides[$this->paragraph->bundle()]);
  }

}
