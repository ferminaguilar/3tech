<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Core\Url;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\mercury_editor\DialogService;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\mercury_editor\MercuryEditorContextService;
use Drupal\mercury_editor\MercuryEditorPreviewService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Entity\Controller\EntityViewController;
use Drupal\mercury_editor\Ajax\OpenMercuryDialogCommand;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;

/**
 * Class EditTrayController.
 *
 * Refreshes the node being edited in the tray.
 */
class MercuryEditorController extends EntityViewController {

  use AjaxHelperTrait;
  use StringTranslationTrait;

  /**
   * The Mercury Editor Edit Tray tempstore service.
   *
   * @var \Drupal\mercury_editor\MercuryEditorTempstore
   */
  protected $tempstore;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The mercury editor context service.
   *
   * @var \Drupal\mercury_editor\MercuryEditorContextService
   */
  protected $mercuryEditorContext;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Creates a NodeViewController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\mercury_editor\MercuryEditorTempstore $tempstore
   *   The tempstore.
   * @param \Drupal\mercury_editor\MercuryEditorContextService $mercury_editor_context
   *   The mercury editor context service.
   * @param \Drupal\mercury_editor\MercuryEditorPreviewService $mercuryEditorPreviewService
   *   The mercury editor preview service.
   * @param \Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper
   *   The IFrame ajax response wrapper.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository $layoutParagraphsTempstore
   *   The layout paragraphs tempstore repository.
   * @param \Drupal\mercury_editor\DialogService $mercuryEditorDialog
   *   The mercury editor dialog service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager service.
   * @param \Drupal\Core\Entity\EntityFormBuilderInterface $entityFormBuilder
   *   The entity form builder service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
    AccountInterface $current_user,
    EntityRepositoryInterface $entity_repository,
    MercuryEditorTempstore $tempstore,
    MercuryEditorContextService $mercury_editor_context,
    protected MercuryEditorPreviewService $mercuryEditorPreviewService,
    protected IFrameAjaxResponseWrapper $iFrameAjaxResponseWrapper,
    protected LayoutParagraphsLayoutTempstoreRepository $layoutParagraphsTempstore,
    protected DialogService $mercuryEditorDialog,
    MessengerInterface $messenger,
    protected LanguageManagerInterface $languageManager,
    protected EntityFormBuilderInterface $entityFormBuilder,
  ) {
    parent::__construct($entity_type_manager, $renderer);
    $this->currentUser = $current_user;
    $this->entityRepository = $entity_repository;
    $this->tempstore = $tempstore;
    $this->mercuryEditorContext = $mercury_editor_context;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('current_user'),
      $container->get('entity.repository'),
      $container->get('mercury_editor.tempstore_repository'),
      $container->get('mercury_editor.context'),
      $container->get('mercury_editor.preview'),
      $container->get('mercury_editor.iframe_ajax_response_wrapper'),
      $container->get('layout_paragraphs.tempstore_repository'),
      $container->get('mercury_editor.dialog'),
      $container->get('messenger'),
      $container->get('language_manager'),
      $container->get('entity.form_builder'),
    );
  }

  /**
   * Edit an entity with the mercury editor edit tray.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\Core\Entity\ContentEntityInterface $mercury_editor_entity
   *   The entity.
   *
   * @return array
   *   The edit form.
   */
  public function editor(Request $request, ContentEntityInterface $mercury_editor_entity) {
    $access = $this->access();
    if ($access->isForbidden()) {
      $message = $this->currentUser->isAnonymous()
        ? $this->t('You must be logged in to edit this entity.')
        : $this->t('You do not have permission to edit this entity.');
      $this->messenger->addError($message);
      if (method_exists($mercury_editor_entity, 'toUrl')) {
        $url = $mercury_editor_entity->toUrl();
      }
      else {
        $url = Url::fromRoute('<front>');
      }
      return new RedirectResponse($url->toString());
    }
    $form_state_additions = [];
    if ($request->query->get('translation_mode')) {
      $form_state_additions['langcode'] = $request->query->get('langcode');
      $form_state_additions['content_translation']['translation_form'] = $request->query->get('translation_form');
      if ($request->query->get('source')) {
        $form_state_additions['content_translation']['source'] = $this->languageManager->getLanguage($request->query->get('source'));
      }
      if ($request->query->get('target')) {
        $form_state_additions['content_translation']['target'] = $this->languageManager->getLanguage($request->query->get('target'));
      }
    }
    $form = $this->entityFormBuilder->getForm($mercury_editor_entity, 'mercury_editor', $form_state_additions);
    // $form = $this->entityFormBuilder->getForm($mercury_editor_entity, 'mercury_editor', $form_state_additions);
    $form['#attached']['drupalSettings']['mercuryEditor']['id'] = $mercury_editor_entity->uuid();
    $form['#attached']['drupalSettings']['mercuryEditor']['isEditor'] = TRUE;
    if ($this->isAjax()) {
      $response = $this->mercuryEditorPreviewService->ajaxUpdatePreview($mercury_editor_entity);
      $response->addCommand(new ReplaceCommand('.me-entity-form', $form));
      return $response;
    }
    else {
      return $form;
    }
  }

  /**
   * Undoes the last change to the entity being edited.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $mercury_editor_entity
   *   The entity.
   */
  public function undo(ContentEntityInterface $mercury_editor_entity) {
    $this->tempstore->previousState($mercury_editor_entity);
    return $this->isAjax() ? new AjaxResponse() : [];
  }

  /**
   * Redoes the last undone change to the entity being edited.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $mercury_editor_entity
   *   The entity.
   */
  public function redo(ContentEntityInterface $mercury_editor_entity) {
    $this->tempstore->nextState($mercury_editor_entity);
    return $this->isAjax() ? new AjaxResponse() : [];
  }

  /**
   * Exits the editor.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\Core\Entity\ContentEntityInterface $mercury_editor_entity
   *   The entity.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function exit(Request $request, ContentEntityInterface $mercury_editor_entity) {
    $ids = $this->tempstore->getLayoutParagraphsFieldIds($mercury_editor_entity);
    foreach ($ids as $id) {
      $layout = $this->layoutParagraphsTempstore->getWithStorageKey($id);
      // Check we have a layout before attempting to delete it.
      if ($layout !== NULL) {
        $this->layoutParagraphsTempstore->delete($layout);
      }
    }
    $this->tempstore->clearStates($mercury_editor_entity);
    $this->tempstore->delete($mercury_editor_entity);

    $url = NULL;
    if ($request->query->get('destination')) {
      $url = $request->query->get('destination');
    }
    elseif (!$mercury_editor_entity->id()) {
      $url = Url::fromRoute('<front>')->toString();
    }
    elseif ($mercury_editor_entity instanceof RevisionableInterface && $mercury_editor_entity->getRevisionId()) {
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
      $storage = $this->entityTypeManager
        ->getStorage($mercury_editor_entity->getEntityTypeId());
      /** @var \Drupal\Core\Entity\RevisionableInterface $revision */
      $revision = $storage->loadRevision($mercury_editor_entity->getRevisionId());
      if ($revision && !$revision->isDefaultRevision() && $mercury_editor_entity->hasLinkTemplate('latest-version')) {
        $url = $revision
          ->toUrl('latest-version', [
            'absolute' => TRUE,
            'language' => $mercury_editor_entity->language(),
          ])
          ->toString();
      }
    }
    if (empty($url)) {
      $url = $mercury_editor_entity
        ->toUrl('canonical', ['absolute' => TRUE])
        ->toString();
    }
    return new RedirectResponse($url);
  }

  /**
   * Renders an outline of components for a given Mercury Editor entity.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\Core\Entity\ContentEntityInterface $mercury_editor_entity
   *   The entity.
   *
   * @return array|\Drupal\Core\Ajax\AjaxResponse
   *   The outline of components.
   */
  public function componentOutline(Request $request, ContentEntityInterface $mercury_editor_entity) {
    $outline = [
      '#type' => 'mercury_editor_component_outline',
      '#entity' => $mercury_editor_entity,
      // Wrapper for ajax replacement.
      '#prefix' => '<div id="me-component-outline-wrapper">',
      '#suffix' => '</div>',
      '#cache' => ['max-age' => 0],
    ];
    if ($this->isAjax()) {
      $response = new AjaxResponse();
      $command = $request->query->get('update')
        ? new ReplaceCommand('#me-component-outline-wrapper', $outline)
        : new OpenMercuryDialogCommand(
          '#mercury-editor-component-outline',
          $this->t('Component Outline'),
          $outline,
          [
            'width' => 'fit-content',
            'height' => 'fit-content',
            'drupalAutoButtons' => 'false',
            'resizable' => TRUE,
            'modal' => FALSE,
            'dock' => 'left',
            'classes' => [
              'lpb-dialog',
            ],
          ],
        );
      $response->addCommand($command);
      return $response;
    }
    else {
      return $outline;
    }
  }

  /**
   * Preview an entity being edited with the mercury editor edit tray.
   */
  public function preview() {
    $mercury_editor_entity = $this->mercuryEditorContext->getEntity();
    $this->tempstore->clearStates($mercury_editor_entity);
    $this->tempstore->saveState($mercury_editor_entity);
    $this->mercuryEditorContext->setPreview(TRUE);
    $preview = $this->view($mercury_editor_entity);
    $preview['#attached']['drupalSettings']['mercuryEditor']['id'] = $mercury_editor_entity->uuid();
    $preview['#attached']['drupalSettings']['mercuryEditor']['isPreview'] = TRUE;
    $preview['#attached']['drupalSettings']['mercuryEditorId'] = $mercury_editor_entity->uuid();
    $preview['#cache']['max-age'] = 0;
    return $preview;
  }

  /**
   * Access callback for using mercury editor.
   */
  public function access() {
    $mercury_editor_entity = $this->mercuryEditorContext->getEntity();

    if ($mercury_editor_entity instanceof ContentEntityInterface) {
      return $mercury_editor_entity->id()
        ? $mercury_editor_entity->access('update', NULL, TRUE)
        : $mercury_editor_entity->access('create', NULL, TRUE);
    }

    return AccessResult::forbidden();
  }

}
