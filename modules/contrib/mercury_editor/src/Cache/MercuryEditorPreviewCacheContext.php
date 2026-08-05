<?php

namespace Drupal\mercury_editor\Cache;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Cache\Context\RouteNameCacheContext;
use Drupal\mercury_editor\MercuryEditorContextService;

/**
 * Determines if an entity is being viewed in Mercury Editor Preview.
 *
 * Cache context ID: 'route.name.is_mercury_editor_preview'.
 *
 * @internal
 *   Tagged services are internal.
 */
class MercuryEditorPreviewCacheContext extends RouteNameCacheContext {

  /**
   * {@inheritDoc}
   */
  public function __construct(
    RouteMatchInterface $route_match,
    protected MercuryEditorContextService $mercuryEditorContext,
  ) {
    parent::__construct($route_match);
  }

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return t('Mercury Editor preview');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    if (
      $this->mercuryEditorContext->isPreview()
      && $entity = $this->mercuryEditorContext->getEntity()
    ) {
      return 'is_mercury_editor_preview.' . $entity->getEntityTypeId() . '.' . $entity->uuid();
    }
    else {
      return 'is_mercury_editor_preview.0';
    }
  }

}
