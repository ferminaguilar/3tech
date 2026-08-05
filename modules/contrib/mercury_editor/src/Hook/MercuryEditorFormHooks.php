<?php

declare(strict_types=1);

namespace Drupal\mercury_editor\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\mercury_editor\MercuryEditorEntityTypeInfo;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\mercury_editor\Form\MercuryEditorEntityFormAlterer;

/**
 * Defines hooks for Mercury Editor form integration.
 */
class MercuryEditorFormHooks {

  /**
   * Constructs a new MercuryEditorFormHooks object.
   *
   * @param \Drupal\mercury_editor\Form\MercuryEditorEntityFormAlterer $alterer
   *   The form alterer service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver service.
   */
  public function __construct(
    protected MercuryEditorEntityFormAlterer $alterer,
    protected ModuleHandlerInterface $moduleHandler,
    protected ClassResolverInterface $classResolver,
  ) {}

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(
    array &$form,
    FormStateInterface $form_state,
    string $form_id,
  ): void {
    // Apply Mercury Editor specific content moderation alterations if the
    // content moderation module is enabled. The MercuryEditorEntityTypeInfo
    // class extends the core ContentModerationEntityTypeInfo class to add
    // support for the 'mercury_editor' form operation.
    if ($this->moduleHandler->moduleExists('content_moderation')) {
      $this->classResolver
        ->getInstanceFromDefinition(MercuryEditorEntityTypeInfo::class)
        ->formAlter($form, $form_state, $form_id);
    }
    // Apply Mercury Editor specific alterations for entity forms.
    if ($this->appliesToForm($form_state)) {
      $this->alterer->alter($form, $form_state);
    }
  }

  /**
   * Determines if the hook should apply to the given form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE if the hook should apply to the given form, FALSE otherwise.
   */
  protected function appliesToForm($form_state) {
    $form_object = $form_state->getFormObject();
    // Only run for ContentEntityForm forms in the mercury_editor form mode.
    if (!($form_object instanceof ContentEntityForm)) {
      return FALSE;
    }
    $operation = $form_object->getOperation();
    if ($operation !== 'mercury_editor') {
      return FALSE;
    }
    return TRUE;
  }

}
