<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\FormController;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Wrapping controller for ME entity forms that serve as the main page body.
 */
class MercuryEditorHtmlEntityFormController extends FormController {

  /**
   * The entity form controller being decorated.
   *
   * @var \Drupal\Core\Controller\FormController
   */
  protected $entityFormController;

  /**
   * The Mercury Editor config.
   *
   * @var \Drupal\Core\Config\Config
   *   The Mercury Editor config.
   */
  protected $config;

  /**
   * The Mercury Editor Edit Tray tempstore service.
   *
   * @var \Drupal\mercury_editor\MercuryEditorTempstore
   */
  protected $tempstore;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Constructs a MercuryEditorHtmlEntityFormController object.
   *
   * @param \Drupal\Core\Controller\FormController $entity_form_controller
   *   The entity form controller being decorated.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\mercury_editor\MercuryEditorTempstore $tempstore
   *   The Mercury Editor tempstore service.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder service.
   */
  public function __construct(FormController $entity_form_controller, ConfigFactoryInterface $config_factory, MercuryEditorTempstore $tempstore, FormBuilderInterface $form_builder) {
    $this->entityFormController = $entity_form_controller;
    $this->config = $config_factory->get('mercury_editor.settings');
    $this->tempstore = $tempstore;
    $this->formBuilder = $form_builder;
  }

  /**
   * Renders the form with Mercury Editor if applicable.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return array
   *   The render array that results from invoking the controller.
   */
  public function getContentResult(Request $request, RouteMatchInterface $route_match) {

    if ($route_match->getRouteName() !== 'mercury_editor.editor') {
      $bundles = $this->config->get('bundles');
      $form_arg = $this->entityFormController->getFormArgument($route_match);
      [$entity_type_id, $form_mode] = explode('.', $form_arg);
      if (($form_mode == 'default' || $form_mode == 'edit' || $form_mode == 'add') && isset($bundles[$entity_type_id])) {
        /** @var \Drupal\Core\Entity\EntityFormInterface $form_object */
        $form_object = $this->entityFormController->getFormObject($route_match, $form_arg);
        $entity = $form_object->getEntity();
        $bundle_id = $entity->bundle();
        // If entity type / bundle is ME enabled:
        if (isset($bundles[$entity_type_id][$bundle_id])) {
          /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
          $entity = $form_object->getEntity();
          // Set default values for all fields to ensure they are available
          // for the ME live preview.
          foreach ($entity->getFieldDefinitions() as $name => $definition) {
            if ($definition->isComputed() || $definition->isReadOnly()) {
              continue;
            }
            $items = $entity->get($name);
            if ($items->isEmpty()) {
              $items->applyDefaultValue();
            }
          }
          if ($entity->hasField('moderation_state') && $entity->get('moderation_state')->isEmpty()) {
            $entity->get('moderation_state')->applyDefaultValue();
          }
          // Store the entity in tempstore for retrieval in the ME editor.
          $this->tempstore->set($entity);
          $params = [
            'mercury_editor_entity' => $entity->uuid(),
          ];
          $query = $request->query->all();
          // Remove the destination parameter from the query to prevent
          // the destination redirect from being triggered.
          $request->query->remove('destination');
          $options = [
            'query' => $query,
          ];
          return new RedirectResponse(Url::fromRoute('mercury_editor.editor', $params, $options)->toString());
        }
      }
    }
    return $this->entityFormController->getContentResult($request, $route_match);
  }

  /**
   * {@inheritdoc}
   */
  protected function getFormArgument(RouteMatchInterface $route_match) {
    return $this->entityFormController->getFormArgument($route_match);
  }

  /**
   * {@inheritdoc}
   */
  protected function getFormObject(RouteMatchInterface $route_match, $form_arg) {
    return $this->entityFormController->getFormObject($route_match, $form_arg);
  }

}
