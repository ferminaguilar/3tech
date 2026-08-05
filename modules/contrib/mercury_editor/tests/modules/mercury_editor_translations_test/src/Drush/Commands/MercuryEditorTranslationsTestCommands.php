<?php

namespace Drupal\mercury_editor_translations_test\Drush\Commands;

use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Utility\Token;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 */
final class MercuryEditorTranslationsTestCommands extends DrushCommands {

  /**
   * Constructs a MercuryEditorTranslationsTestCommands object.
   */
  public function __construct(
    private readonly Token $token,
    private readonly ContentTranslationManagerInterface $contentTranslationManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('token'),
      $container->get('content_translation.manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Configures asymmetrical translations for testing.
   */
  #[CLI\Command(name: 'mercury_editor_translations_test:asym-setup')]
  #[CLI\Usage(name: 'mercury_editor_translations_test:asym-setup', description: 'Configures Mercury Editor for testing asymmetrical translations.')]
  public function asymSetup() {
    $this->contentTranslationManager->setEnabled('node', 'me_test_ct', TRUE);
    // Paragraph reference field is NOT translatable for symmetrical
    // translations.
    $this->entityFieldManager->getFieldDefinitions('node', 'me_test_ct')['field_me_test_content']->setTranslatable(TRUE)->save();
    // Enable translation for the text paragraph type.
    $this->contentTranslationManager->setEnabled('paragraph', 'me_test_text', FALSE);
    // Text field is translatable for symmetrical translations.
    $this->entityFieldManager->getFieldDefinitions('paragraph', 'me_test_text')['field_me_test_text']->setTranslatable(FALSE)->save();
    $this->logger()->success(dt('Configured for asymmetrical translations.'));
  }

  /**
   * Configures symmetrical translations for testing.
   */
  #[CLI\Command(name: 'mercury_editor_translations_test:sym-setup')]
  #[CLI\Usage(name: 'mercury_editor_translations_test:sym-setup', description: 'Configures Mercury Editor for testing symmetrical translations.')]
  public function symSetup() {
    $this->contentTranslationManager->setEnabled('node', 'me_test_ct', TRUE);
    // Paragraph reference field is NOT translatable for symmetrical
    // translations.
    $this->entityFieldManager->getFieldDefinitions('node', 'me_test_ct')['field_me_test_content']->setTranslatable(FALSE)->save();
    // Enable translation for the text paragraph type.
    $this->contentTranslationManager->setEnabled('paragraph', 'me_test_text', TRUE);
    // Text field is translatable for symmetrical translations.
    $this->entityFieldManager->getFieldDefinitions('paragraph', 'me_test_text')['field_me_test_text']->setTranslatable(TRUE)->save();
    $this->logger()->success(dt('Configured for symmetrical translations.'));
  }

}
