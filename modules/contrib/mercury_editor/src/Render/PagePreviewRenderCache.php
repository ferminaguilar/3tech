<?php

namespace Drupal\mercury_editor\Render;

use Drupal\Core\Render\RenderCacheInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Bypasses render caching only while rendering an updated preview page.
 *
 * Surrounding blocks must see unsaved fields, not previously cached markup.
 * Preview markup must never be written into the site's shared render cache.
 */
class PagePreviewRenderCache implements RenderCacheInterface {

  /**
   * Constructs the render cache decorator.
   */
  public function __construct(
    protected RenderCacheInterface $inner,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Whether the current request is rendering an updated preview page.
   */
  protected function isPageRefresh(): bool {
    return (bool) $this->requestStack->getCurrentRequest()?->attributes->get('_mercury_editor_page_refresh');
  }

  /**
   * {@inheritdoc}
   */
  public function get(array $elements) {
    return $this->isPageRefresh() ? FALSE : $this->inner->get($elements);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $multiple_elements): array {
    if ($this->isPageRefresh()) {
      return [];
    }
    // Drupal 10's render cache interface does not provide batched reads.
    if (method_exists($this->inner, 'getMultiple')) {
      return $this->inner->getMultiple($multiple_elements);
    }
    $results = [];
    foreach ($multiple_elements as $key => $elements) {
      if (($result = $this->inner->get($elements)) !== FALSE) {
        $results[$key] = $result;
      }
    }
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function set(array &$elements, array $pre_bubbling_elements) {
    return $this->isPageRefresh() ? FALSE : $this->inner->set($elements, $pre_bubbling_elements);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableRenderArray(array $elements) {
    return $this->inner->getCacheableRenderArray($elements);
  }

}
