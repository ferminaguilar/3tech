<?php

namespace Drupal\mercury_editor_templates\EventSubscriber;

use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\layout_paragraphs\Event\LayoutParagraphsAllowedTypesEvent;

/**
 * Class definition for LayoutParagraphsAllowedTypesSubscriber.
 */
class LayoutParagraphsAllowedTypesSubscriber implements EventSubscriberInterface {


  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Class constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    RequestStack $request_stack,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      LayoutParagraphsAllowedTypesEvent::EVENT_NAME => 'addTemplates',
    ];
  }

  /**
   * Adds templates to the allowed types.
   *
   * @param \Drupal\layout_paragraphs\Event\LayoutParagraphsAllowedTypesEvent $event
   *   The allowed types event.
   */
  public function addTemplates(LayoutParagraphsAllowedTypesEvent $event) {

    if (!$this->currentUser->hasPermission('use mercury editor templates')) {
      return;
    }

    $parent_uuid = $event->getParentUuid();
    $types = $event->getTypes();
    $layout = $event->getLayout();
    $settings = $layout->getSettings();

    $depth = 0;
    $max_depth_exceeded = FALSE;
    while ($parent = $layout->getComponentByUuid($parent_uuid)) {
      $depth++;
      $parent_uuid = $parent->getParentUuid();
    }
    if ($depth > $settings['nesting_depth']) {
      $max_depth_exceeded = TRUE;
    }

    $templates = $this->entityTypeManager->getStorage('me_template')->loadByProperties(['status' => 1]);
    if (count($templates)) {
      $types = $event->getTypes();
      foreach ($templates as $template) {
        $skip_template = FALSE;
        $paragraphs = $template->content->referencedEntities();
        // Check if any of the paragraphs in the template are a layout.
        foreach ($paragraphs as $paragraph) {
          $behaviors = $paragraph->getAllBehaviorSettings();
          $is_layout = isset($behaviors['layout_paragraphs']) &&
            array_key_exists('layout', $behaviors['layout_paragraphs']);
          if ($is_layout && $max_depth_exceeded) {
            // If we are nested past the max depth and any of the paragraphs
            // has a layout_paragraphs behavior, skip this template.
            $skip_template = TRUE;
            break;
          }
        }
        if ($skip_template) {
          continue;
        }
        $url = Url::fromRoute('mercury_editor_templates.insert_template', [
          'layout_paragraphs_layout' => $event->getLayout()->id(),
          'me_template' => $template->id(),
        ], ['query' => $this->request->query->all()]);
        $types['me_template_' . $template->id()] = [
          'id' => 'me_template_' . $template->id(),
          'label' => $template->label(),
          'image' => FALSE,
          'is_section' => TRUE,
          'is_template' => TRUE,
          'url_object' => $url,
        ];
      }
      $event->setTypes($types);
    }
  }

}
