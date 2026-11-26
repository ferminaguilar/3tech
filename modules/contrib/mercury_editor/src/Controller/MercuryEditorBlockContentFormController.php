<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\block_content\BlockContentTypeInterface;
use Drupal\block_content\Controller\BlockContentController;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Opens new content blocks in Mercury Editor when their type is enabled.
 */
class MercuryEditorBlockContentFormController extends BlockContentController {

  /**
   * The working entity store.
   *
   * @var \Drupal\mercury_editor\MercuryEditorTempstore
   */
  protected MercuryEditorTempstore $tempstore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $controller = parent::create($container);
    $controller->tempstore = $container->get('mercury_editor.tempstore_repository');
    return $controller;
  }

  /**
   * {@inheritdoc}
   */
  public function addForm(BlockContentTypeInterface $block_content_type, Request $request) {
    $bundles = $this->config('mercury_editor.settings')->get('bundles');
    if (empty($bundles['block_content'][$block_content_type->id()])) {
      return parent::addForm($block_content_type, $request);
    }

    $block = $this->blockContentStorage->create([
      'type' => $block_content_type->id(),
    ]);
    if (($theme = $request->query->get('theme')) && isset($this->themeHandler->listInfo()[$theme])) {
      $block->setTheme($theme);
    }
    $this->tempstore->set($block);
    $query = $request->query->all();
    $request->query->remove('destination');
    return $this->redirect('mercury_editor.editor', [
      'mercury_editor_entity' => $block->uuid(),
    ], ['query' => $query]);
  }

}
