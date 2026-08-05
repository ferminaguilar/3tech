<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Render\Element;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mercury_editor\MercuryEditorPreviewService;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mercury_editor\Ajax\MercuryEditorSaveSuccessCommand;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;

/**
 * Provides form alterations for Mercury Editor entity forms.
 */
class MercuryEditorEntityFormAlterer {

  use StringTranslationTrait;

  /**
   * The class constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   Drupal configuration service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\mercury_editor\MercuryEditorTempstore $tempstore
   *   The mercury editor tempstore service.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository $layoutParagraphsTempstore
   *   The layout paragraphs tempstore repository.
   * @param \Drupal\mercury_editor\MercuryEditorPreviewService $preview
   *   The Mercury Editor preview service.
   */
  public function __construct(
    protected ConfigFactoryInterface $config,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MercuryEditorTempstore $tempstore,
    protected LayoutParagraphsLayoutTempstoreRepository $layoutParagraphsTempstore,
    protected MercuryEditorPreviewService $preview,
  ) {}

  /**
   * Alters the Mercury Editor entity.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function alter(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    /** @var \Drupal\Core\Entity\ContentEntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $form_object->getEntity();

    // Set default values for a new entity.
    if (!$form_state->get('init') && !$entity->id()) {
      $entity->in_preview = TRUE;
      $entity->preview_view_mode = 'full';
      $entity_type = $this->entityTypeManager->getDefinition($entity->getEntityTypeId());
      $label_field = $entity_type->getKey('label');
      if ($entity->hasField($label_field) && $entity->{$label_field}->isEmpty()) {
        $label = $entity->type
          ? $entity->type->entity->label()
          : $entity->getEntityType()->getLabel();
        $default_label = $this->t('New @label', ['@label' => $label]);
        $entity->{$label_field} = $default_label;
        $form[$label_field]['widget'][0]['value']['#default_value'] = $default_label;
      }
    }

    $form['#theme'] = 'mercury_editor_entity_form';
    $form['error_messages'] = [
      '#type' => 'mercury_editor_form_errors',
      '#weight' => -10,
    ];
    $form['status_messages'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'me-form-status-messages',
        ],
      ],
      'messages' => [
        '#type' => 'status_messages',
      ],
      '#weight' => -100,
    ];
    $form['#attributes']['class'][] = 'me-entity-form';
    $form['#attributes']['class'][] = 'me-form';

    // Check for autosave configuration (this would need to be implemented)
    // For now, we'll add the class unconditionally.
    $form['#attributes']['class'][] = 'me-autosave-form';
    $form['#attributes']['class'][] = 'me-autosave';

    // Temporarily save a reference to the layout paragraphs layout builder in
    // the entity so we can swap the field for the builder when rendering.
    $lp_storage_keys = [];
    foreach (Element::children($form, TRUE) as $field_name) {
      if (isset($form[$field_name]['widget']['layout_paragraphs_storage_key'])) {
        $lp_storage_keys[$field_name] = $form[$field_name]['widget']['layout_paragraphs_storage_key']['#default_value'];
        // Hide the layout paragraphs field for rendering in the frontend.
        $form[$field_name]['widget']['layout_paragraphs_builder']['#access'] = FALSE;
        $form[$field_name]['widget']['#type'] = 'container';
        $form[$field_name]['#attributes']['class'][] = 'hidden';
        // Set the Mercury Editor context on the layout.
        /** @var \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout */
        $layout = $form[$field_name]['widget']['layout_paragraphs_builder']['#layout_paragraphs_layout'];

        // Set referring items so we can save the layout to the correct entity.
        $items = $layout->getParagraphsReferenceField();
        foreach ($items as $key => $item) {
          if (!empty($item->entity)) {
            $items[$key]->entity->_referringItem = $items[$key];
          }
        }
        $layout->setParagraphsReferenceField($items);

        $settings = $layout->getSettings();
        $settings['mercury_editor_context'] = TRUE;
        $settings['is_translating'] = $form[$field_name]['widget']['layout_paragraphs_builder']['#is_translating'] ?? FALSE;
        $layout->setSettings($settings);
        $this->layoutParagraphsTempstore->set($layout);
      }
    }
    if ($lp_storage_keys) {
      $this->tempstore->setLayoutParagraphsFieldIds($entity, $lp_storage_keys);
    }
    if (!$form_state->get('init')) {
      $form_state->set('init', TRUE);
    }
    $this->tempstore->set($entity);

    if ($this->isMercuryAutosaveEnabled()) {
      $form['actions']['autosave'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save progress'),
        '#submit' => [
          [self::class, 'submitForm'],
          [self::class, 'saveProgress'],
        ],
        '#ajax' => [
          'callback' => [self::class, 'ajaxAutosaveCallback'],
        ],
        '#attributes' => [
          'class' => [
            'me-autosave-btn',
          ],
        ],
      ];
    }
    if (isset($form['actions']['delete'])) {
      $form['actions']['delete']['#attributes']['target'] = '_self';
      $query = $form['actions']['delete']['#url']->getOption('query') ?? [];
      $form['actions']['delete']['#url']->setOption('query', ['_mercury_editor_iframe' => TRUE] + $query);
    }
    $form['actions']['submit']['#access'] = TRUE;
    $form['actions']['submit']['#ajax'] = [
      'callback' => [self::class, 'ajaxCallback'],
    ];
    $form['actions']['submit']['#submit'][] = [self::class, 'afterSave'];
    $form['actions']['preview']['#access'] = FALSE;
    $form['actions']['#attributes']['class'][] = 'me-form-actions';

    $form['#validate'][] = [self::class, 'validateForm'];
    $admin_theme = $this->config
      ->get('system.theme')
      ->get('admin');
    $mercury_theme = $this->config
      ->get('mercury_editor.settings')
      ->get('edit_screen_theme');
    $current_theme = empty($mercury_theme)
      ? $admin_theme
      : $mercury_theme;
    if ($current_theme === 'gin') {
      $form['#attached']['library'][] = 'mercury_editor/gin_overrides';
    }
  }

  /**
   * Validation handler for Mercury Editor forms.
   *
   * Sets the 'changed' value to the current time to avoid conflicts and gathers
   * form errors to display in the error messages area.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateForm(array &$form, FormStateInterface $form_state) {
    $form_state->setValue('changed', time());
    $form['error_messages']['#form_errors'] = $form_state->getErrors();
  }

  /**
   * Determines if autosave is enabled globally for Mercury Editor forms.
   */
  protected function isMercuryAutosaveEnabled(): bool {
    $auto_update = $this->config->get('mercury_editor.settings')->get('auto_update') ?? [];
    return ($auto_update['enabled'] ?? TRUE) !== FALSE;
  }

  /**
   * Submit handler that runs after saving the entity for the form.
   *
   * Reloads the entity from storage, saves it to the tempstore, and clears the
   * revision log.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function afterSave(array $form, FormStateInterface $form_state) {
    $tempstore_service = \Drupal::service('mercury_editor.tempstore_repository');
    $messenger_service = \Drupal::messenger();
    /** @var \Drupal\Core\Entity\ContentEntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    $entity = $form_object->getEntity();
    // Reload the entity from storage to ensure all field values are correctly
    // populated before re-rendering the form.
    // @todo Consider moving this to a service as it is also used in
    // MercuryEditorController::editor().
    $entity_type_id = $entity->getEntityTypeId();
    $entity_id = $entity->id();
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */

    $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
    $revision_id = $storage->getLatestRevisionId($entity_id);
    $entity = !is_null($revision_id)
      ? $storage->loadRevision($revision_id)
      : $storage->load($entity_id);

    if ($entity instanceof ContentEntityInterface) {
      if ($form_state->get('langcode') !== $entity->language()->getId()) {
        $entity = $entity->getTranslation($form_state->get('langcode'));
      }
    }
    // Resave the entity to tempstore and clear any saved states.
    $tempstore_service->set($entity);
    $tempstore_service->clearStates($entity);

    // After saving to the DB, the ME tempstore now has correct paragraph IDs
    // (freshly loaded from the database). But the LP tempstore still holds
    // stale layout objects with unsaved paragraph entity objects (id=NULL).
    // We must refresh the LP tempstore layouts from the ME entity so that
    // subsequent autosave requests (which read from LP tempstore via
    // getWithStorageKey()) get paragraphs with correct database IDs.
    // Without this, a race condition occurs: the next autosave reads the
    // stale LP layout, passes id=NULL entities through the decorator, which
    // overwrites the correct IDs in ME tempstore, and the next save attempt
    // tries to INSERT a paragraph that already exists (duplicate UUID).
    $lp_tempstore = \Drupal::service('layout_paragraphs.tempstore_repository');
    $lp_field_ids = $tempstore_service->getLayoutParagraphsFieldIds($entity);
    foreach ($lp_field_ids as $field_name => $storage_key) {
      $layout = $lp_tempstore->getWithStorageKey($storage_key);
      if ($layout) {
        // Replace the layout's reference field with the fresh data from the
        // reloaded entity, then save it back to LP tempstore.
        if ($entity->hasField($field_name)) {
          $layout->setParagraphsReferenceField($entity->get($field_name));
          $lp_tempstore->set($layout);
        }
      }
    }

    // Clear status messages; visual feedback is handled elsewhere in the ME UI.
    $messenger_service->deleteAll();

    // Clear the revision log message for the entity if applicable.
    if (
      $entity->getEntityType()->showRevisionUi() && !$entity->isNew() && $entity instanceof RevisionLogInterface
    ) {
      $entity->setRevisionLogMessage(NULL);
    }

    // Modify user input to ensure a clean rebuild of the form.
    $input = $form_state->getUserInput();
    // Conflict tracking relies on a stale 'changed' in user input during AJAX.
    // Remove it so the rebuilt form takes the fresh default from $reloaded.
    unset($input['changed']);
    // Clear the revision log message input to prevent it from being reused.
    $entity_type = $entity->getEntityType();
    if ($entity_type instanceof ContentEntityTypeInterface) {
      $revision_field = $entity_type->getRevisionMetadataKey('revision_log_message');
      if ($revision_field) {
        $input[$revision_field] = [];
      }
    }
    $form_state->setUserInput($input);

    // Force ContentEntityForm to re-init its "original" tracking on rebuild.
    $form_state->set('entity_form_initialized', NULL);
    $form_object->setEntity($entity);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit callback for autosave.
   *
   * Saves the entity to the tempstore and updates the state for undo/redo
   * purposes.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function saveProgress(array $form, FormStateInterface $form_state) {
    $tempstore_service = \Drupal::service('mercury_editor.tempstore_repository');
    // Remove 'menu' from the form state to prevent menu validation errors.
    // This is necessary because the menu_ui submit handler expects the
    // entity to have an ID, and the entity may not have one yet.
    // @todo Consider other fields that may need to be unset.
    /** @var \Drupal\Core\Entity\ContentEntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    $entity = $form_object->getEntity();
    if (!$entity->id()) {
      $form_state->unsetValue('menu');
    }
    $tempstore_service->set($entity);
    $tempstore_service->saveState($entity);
  }

  /**
   * AJAX callback for the autosave button.
   *
   * Updates the preview and collects any form error messages.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function ajaxAutosaveCallback(array &$form, FormStateInterface $form_state) {
    $preview_service = \Drupal::service('mercury_editor.preview');
    /** @var \Drupal\Core\Entity\ContentEntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    $entity = $form_object->getEntity();
    $response = $preview_service->ajaxUpdatePreview($entity);
    $response->addCommand(new ReplaceCommand('.me-form-status-messages', $form['status_messages']));
    $response->addCommand(new ReplaceCommand('.me-entity-form .me-form-error-messages__wrapper', $form['error_messages']));
    return $response;
  }

  /**
   * Ajax callback for rendering the form.
   *
   * Updates the preview and edit form after a mercury editor entity has been
   * saved.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function ajaxCallback(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Entity\ContentEntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    $entity = $form_object->getEntity();
    $form['#updated'] = TRUE;
    $preview = \Drupal::service('mercury_editor.preview');
    $response = $preview->ajaxUpdatePreview($entity);
    $response->addCommand(new ReplaceCommand('.me-entity-form', $form));
    if (!$form_state->hasAnyErrors()) {
      $response->addCommand(new MercuryEditorSaveSuccessCommand());
    }
    return $response;
  }

  /**
   * Submit handler for the form.
   *
   * Calls the form object's submitForm method and sets the form to rebuild.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->getFormObject()->submitForm($form, $form_state);
    $form_state->setRebuild(TRUE);
  }

}
