<?php

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Ajax\AjaxResponseAttachmentsProcessor;

/**
 * IFrame Ajax response wrapper attachments processor.
 *
 * Resolves AJAX attachments against the libraries loaded in the preview iframe.
 *
 * @see \Drupal\Core\Ajax\AjaxResponseAttachmentsProcessor
 */
class IFrameAjaxResponseWrapperAttachmentsProcessor extends AjaxResponseAttachmentsProcessor {

  /**
   * {@inheritdoc}
   */
  public function processAttachments(AttachmentsInterface $response) {
    // @todo Convert to assertion once https://www.drupal.org/node/2408013 lands
    if (!$response instanceof AjaxResponse) {
      throw new \InvalidArgumentException('\Drupal\Core\Ajax\AjaxResponse instance expected.');
    }

    $request = $this->requestStack->getCurrentRequest();
    $original_ajax_page_state = $request->request->all('ajax_page_state');
    $original_ajax_page_state_attribute = $request->attributes->get('ajax_page_state');
    $preview_ajax_page_state = $request->request->all('ajax_preview_page_state');
    $request->request->set('ajax_page_state', $preview_ajax_page_state);
    // Core can read the normalized state from request attributes instead of
    // POST data. Both must describe the iframe, not the outer editor.
    $request->attributes->set('ajax_page_state', $preview_ajax_page_state);

    try {
      if ($response->getContent() == '{}') {
        $response->setData($this->buildAttachmentsCommands($response, $request));
      }
    }
    finally {
      $request->request->set('ajax_page_state', $original_ajax_page_state);
      if ($original_ajax_page_state_attribute === NULL) {
        $request->attributes->remove('ajax_page_state');
      }
      else {
        $request->attributes->set('ajax_page_state', $original_ajax_page_state_attribute);
      }
    }

    return $response;
  }

}
