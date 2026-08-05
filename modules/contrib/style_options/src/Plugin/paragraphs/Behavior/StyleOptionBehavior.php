<?php

namespace Drupal\style_options\Plugin\paragraphs\Behavior;

use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Core\Form\FormStateInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\paragraphs\ParagraphsBehaviorBase;
use Drupal\style_options\StyleOptionContextTrait;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;

/**
 * Provides a way to define grid based layouts.
 *
 * @ParagraphsBehavior(
 *   id = "style_options",
 *   label = @Translation("Style Options"),
 *   description = @Translation("Integrates paragraphs with Style Options."),
 *   weight = 0
 * )
 */
class StyleOptionBehavior extends ParagraphsBehaviorBase {

  use StyleOptionContextTrait;

  /**
   * {@inheritDoc}
   */
  public function buildBehaviorForm(
    ParagraphInterface $paragraph,
    array &$form,
    FormStateInterface $form_state
  ) {

    $context_options = $this
      ->styleOptionPluginConfigurationDiscovery()
      ->getContextOptions($this->getStyleOptionContextId(), $paragraph->bundle());

    foreach (array_keys(array_filter($context_options)) as $option_id) {
      $values = $paragraph->getBehaviorSetting($this->pluginId, $option_id);
      $form[$option_id] = $this->getStyleOptionPluginForm($option_id, $form, $form_state, $values);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function view(array &$build, Paragraph $paragraph, EntityViewDisplayInterface $display, $view_mode) {

    $context_options = $this
      ->styleOptionPluginConfigurationDiscovery()
      ->getContextOptions($this->getStyleOptionContextId(), $paragraph->bundle());

    foreach (array_keys(array_filter($context_options)) as $option_id) {
      $values = $paragraph->getBehaviorSetting($this->pluginId, $option_id);
      $build = $this->buildStyleOptions($option_id, $values, $build);
    }
  }

  /**
   * Returns the context id.
   *
   * @return string
   *   The context id.
   */
  public static function getStyleOptionContextId() {
    return 'paragraphs';
  }

}
