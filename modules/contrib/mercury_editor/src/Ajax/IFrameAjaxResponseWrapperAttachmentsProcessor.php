<?php

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Ajax\AjaxResponseAttachmentsProcessor;

/**
 * IFrame Ajax response wrapper attachments processor.
 *
 * Replaces ajax_page_state with ajax_preview_page_state in the request.
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
    $preview_ajax_page_state = $request->request->all('ajax_preview_page_state');
    $request->request->set('ajax_page_state', $preview_ajax_page_state);

    if ($response->getContent() == '{}') {
      $response->setData($this->buildAttachmentsCommands($response, $request));
    }

    $request->request->set('ajax_page_state', $original_ajax_page_state);

    return $response;
  }

}
