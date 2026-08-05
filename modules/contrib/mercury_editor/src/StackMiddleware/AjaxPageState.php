<?php

namespace Drupal\mercury_editor\StackMiddleware;

use Drupal\Component\Utility\UrlHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Expands the compressed ajax_preview_page_state query parameter into an array.
 *
 * @see \Drupal\Core\StackMiddleware\AjaxPageState
 */
class AjaxPageState implements HttpKernelInterface {

  /**
   * Constructs a new AjaxPageState instance.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *   The wrapped HTTP kernel.
   */
  public function __construct(protected readonly HttpKernelInterface $httpKernel) {
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    if ($type === static::MAIN_REQUEST) {
      if ($request->request->has('ajax_preview_page_state')) {
        $request->request->set('ajax_preview_page_state', $this->parseAjaxPageState($request->request->all('ajax_preview_page_state')));
      }
      elseif ($request->query->has('ajax_preview_page_state')) {
        $request->query->set('ajax_preview_page_state', $this->parseAjaxPageState($request->query->all('ajax_preview_page_state')));
      }
    }
    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Parse the ajax_preview_page_state variable in the request.
   *
   * Decompresses the libraries array key.
   *
   * @param array $ajax_page_state
   *   Array of query parameters, where the libraries parameter is compressed.
   *
   * @return array
   *   The decompressed ajax_preview_page_state array.
   */
  private function parseAjaxPageState(array $ajax_page_state): array {
    $uncompressed = UrlHelper::uncompressQueryParameter($ajax_page_state['libraries']);
    if ($uncompressed !== FALSE) {
      $ajax_page_state['libraries'] = $uncompressed;
    }
    return $ajax_page_state;
  }

}
