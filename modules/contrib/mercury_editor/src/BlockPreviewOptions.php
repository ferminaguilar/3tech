<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the temporary display options for a content block preview.
 */
class BlockPreviewOptions {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ThemeHandlerInterface $themeHandler,
    protected ConfigFactoryInterface $configFactory,
    protected PrivateKey $privateKey,
  ) {}

  /**
   * Signs placement context for an edit link.
   */
  public function placementToken(string $placement_id, string $uuid): string {
    return Crypt::hmacBase64($placement_id . ':' . $uuid, Settings::getHashSalt() . $this->privateKey->get());
  }

  /**
   * Resolves a placement and optional per-request preview overrides.
   */
  public function resolve(ContentEntityInterface $entity, Request $request): array {
    $placement_id = $request->query->get('me_block_placement');
    $token = $request->query->get('me_block_token');
    $placement = NULL;
    if ($placement_id !== NULL && $placement_id !== '') {
      if (!$token || !hash_equals($this->placementToken($placement_id, $entity->uuid()), $token)) {
        throw new NotFoundHttpException('The block placement context is invalid.');
      }
      $placement = $this->entityTypeManager->getStorage('block')->load($placement_id);
      if (!$placement || $placement->getPluginId() !== 'block_content:' . $entity->uuid()) {
        throw new NotFoundHttpException('The block placement does not match the content block.');
      }
    }

    $themes = $this->themeHandler->listInfo();
    $theme = $placement ? $placement->getTheme() : $this->configFactory->get('system.theme')->get('default');
    if (!isset($themes[$theme])) {
      throw new NotFoundHttpException('The preview theme is not installed.');
    }
    $theme_info = $themes[$theme]->info;
    $regions = $theme_info['regions'];
    if (!$regions) {
      throw new \LogicException('The preview theme has no regions.');
    }
    $default_region = isset($regions['content']) ? 'content' : array_key_first($regions);
    $region = $placement && isset($regions[$placement->getRegion()]) ? $placement->getRegion() : $default_region;
    $settings = $placement ? $placement->getPlugin()->getConfiguration() : [];
    $show_title = $placement ? ($settings['label_display'] === BlockPluginInterface::BLOCK_LABEL_VISIBLE) : TRUE;
    $title = $placement ? (string) $settings['label'] : (string) $entity->label();

    if ($request->query->has('me_preview_region')) {
      $region = $request->query->get('me_preview_region');
      if (!isset($regions[$region])) {
        throw new BadRequestHttpException('The preview region is not available in the theme.');
      }
    }
    if ($request->query->has('me_preview_show_title')) {
      $visible = $request->query->get('me_preview_show_title');
      if ($visible !== '0' && $visible !== '1') {
        throw new BadRequestHttpException('The preview title visibility is invalid.');
      }
      $show_title = $visible === '1';
    }
    if ($request->query->has('me_preview_title')) {
      $title = $request->query->get('me_preview_title');
      if (mb_strlen($title) > 255) {
        throw new BadRequestHttpException('The preview title is too long.');
      }
    }

    return [
      'placement' => $placement_id,
      'token' => $token,
      'theme' => $theme,
      'regions' => $regions,
      'region' => $region,
      'show_title' => $show_title,
      'title' => $title,
    ];
  }

}
