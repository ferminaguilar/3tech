<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\Markup;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Masterminds\HTML5;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Renders the complete preview page in its own frontend route context.
 */
class MercuryEditorPagePreview {

  /**
   * Constructs the full-page preview renderer.
   */
  public function __construct(
    protected HttpKernelInterface $httpKernel,
    protected RequestStack $requestStack,
    protected MercuryEditorContextService $context,
    protected ThemeManagerInterface $themeManager,
    protected ThemeInitializationInterface $themeInitialization,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds body markup and attachments for an AJAX page morph.
   */
  public function build(ContentEntityInterface $entity): array {
    $parent = $this->requestStack->getCurrentRequest();
    $url = Url::fromRoute('entity.' . $entity->getEntityTypeId() . '.mercury_editor_preview', [
      $entity->getEntityTypeId() => $entity->uuid(),
    ], [
      'absolute' => TRUE,
      'language' => $entity->language(),
      'query' => ['me_id' => $entity->uuid()],
    ])->toString();
    $request = Request::create($url, 'GET', [], $parent->cookies->all(), [], $parent->server->all());
    $request->headers->remove('X-Requested-With');
    $request->headers->set('Accept', 'text/html');
    $request->attributes->set('_mercury_editor_page_refresh', TRUE);
    if ($parent->hasSession()) {
      $request->setSession($parent->getSession());
    }

    $theme = $this->themeManager->getActiveTheme();
    $preview = $this->context->isPreview();
    $default_theme = $this->configFactory->get('system.theme')->get('default');
    $this->themeManager->setActiveTheme($this->themeInitialization->getActiveThemeByName($default_theme));
    $this->context->setPreview(TRUE);
    // The basic kernel uses its own stack, so also push onto Drupal's stack.
    $this->requestStack->push($request);
    try {
      $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST, FALSE);
      if (!$response instanceof HtmlResponse || !$response->isSuccessful()) {
        throw new \UnexpectedValueException('The Mercury Editor preview did not return a successful HTML page.');
      }

      $html5 = new HTML5(['disable_html_ns' => TRUE]);
      $document = $html5->loadHTML($response->getContent());
      $body = $document->getElementsByTagName('body')->item(0);
      if (!$body) {
        throw new \UnexpectedValueException('The Mercury Editor preview page has no body.');
      }
      // Assets and settings travel through AJAX attachments, not script tags
      // inside morphed markup. Keep the iframe's running document intact.
      foreach (iterator_to_array($body->getElementsByTagName('script')) as $script) {
        $script->parentNode->removeChild($script);
      }
      return [
        '#markup' => Markup::create($html5->saveHTML($body)),
        '#title' => $document->getElementsByTagName('title')->item(0)?->textContent ?? $entity->label(),
        '#attached' => array_intersect_key($response->getAttachments(), array_flip(['library', 'drupalSettings'])),
        '#cache' => ['max-age' => 0],
      ];
    }
    finally {
      $this->requestStack->pop();
      $this->context->setPreview($preview);
      $this->themeManager->setActiveTheme($theme);
    }
  }

}
