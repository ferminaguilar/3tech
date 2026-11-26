<?php

namespace Drupal\mercury_editor\Render;

use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\Context\LazyContextRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Isolates frontend block contexts from cached editing-form contexts.
 */
class PagePreviewContextRepository implements ContextRepositoryInterface {

  /**
   * Constructs the context repository decorator.
   */
  public function __construct(
    protected ContextRepositoryInterface $inner,
    protected RequestStack $requestStack,
    protected ContainerInterface $container,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getRuntimeContexts(array $context_ids) {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request?->attributes->get('_mercury_editor_page_refresh')) {
      return $this->inner->getRuntimeContexts($context_ids);
    }
    // Context providers read the current route, but the global repository may
    // already have cached values for the parent editing request. Keep a fresh
    // repository on this subrequest, and preserve the parent's cached values.
    $repository = $request->attributes->get('_mercury_editor_page_contexts');
    if (!$repository) {
      $repository = new LazyContextRepository($this->container, []);
      $request->attributes->set('_mercury_editor_page_contexts', $repository);
    }
    return $repository->getRuntimeContexts($context_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableContexts() {
    return $this->inner->getAvailableContexts();
  }

}
