<?php

namespace Drupal\mercury_editor\Element;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Template\Attribute;
use Drupal\layout_paragraphs\LayoutParagraphsComponent;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;
use Drupal\mercury_editor\AutosaveHelper;
use Drupal\mercury_editor\DialogService;
use Drupal\mercury_editor\MercuryEditorComponentIcons;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mercury Editor component outline render element.
 *
 * @RenderElement("mercury_editor_component_outline")
 */
class MercuryEditorComponentOutline extends RenderElementBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected LayoutParagraphsLayoutTempstoreRepository $layoutParagraphsTempstore,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LayoutPluginManagerInterface $layoutPluginManager,
    protected RendererInterface $renderer,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected MercuryEditorTempstore $tempstore,
    protected DialogService $dialogSettings,
    protected AutosaveHelper $autosaveHelper,
    protected MercuryEditorComponentIcons $componentIcon,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('layout_paragraphs.tempstore_repository'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.core.layout'),
      $container->get('renderer'),
      $container->get('entity_type.bundle.info'),
      $container->get('mercury_editor.tempstore_repository'),
      $container->get('mercury_editor.dialog'),
      $container->get('mercury_editor.autosave_helper'),
      $container->get('mercury_editor.component_icons'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#entity' => NULL,
      '#pre_render' => [
        [$this, 'preRender'],
      ],
      '#attributes' => [
        'class' => [
          'me-component-outline',
        ],
      ],
    ];
  }

  /**
   * Pre-render callback: renders the outline.
   *
   * @param array $element
   *   The render array for the element.
   *
   * @return array
   *   The render array for the element.
   */
  public function preRender(array $element) {
    $entity = $element['#entity'];
    $element['#type'] = 'container';
    // $element['#attached']['library'][] = 'mercury_editor/component_outline';
    $element['components'] = [];

    if ($entity) {
      $ids = $this->tempstore->getLayoutParagraphsFieldIds($entity);
      foreach ($ids as $id) {
        $layout = $this->layoutParagraphsTempstore->getWithStorageKey($id);
        $field_name = $layout->getFieldName();
        $field_definition = $entity->getFieldDefinition($field_name);
        $label = $field_definition->getLabel() ?: $field_name;
        $element['component-tree'][] = [
          '#type' => 'component',
          '#component' => 'mercury_editor:component-outline',
          '#props' => [
            'items' => $this->componentTree($layout, $layout->getRootComponents()),
            'mercury_editor_id' => $entity->uuid(),
            'layout_id' => $layout->id(),
            'field_label' => $label,
            'attributes' => new Attribute([
              'data-mercury-editor-id' => $entity->uuid(),
              'data-layout-id' => $layout->id(),
            ]),
          ],
        ];
      }
    }
    return $element;
  }

  /**
   * Builds a tree structure of components and their children.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout
   *   The layout to render.
   * @param array $components
   *   A list of components to process.
   *
   * @return array
   *   A hierarchical array representing the component tree.
   */
  protected function componentTree(
    LayoutParagraphsLayout $layout,
    array $components,
  ) {
    $items = [];
    /** @var \Drupal\layout_paragraphs\LayoutParagraphsComponent $component */
    foreach ($components as $component) {
      $entity = $component->getEntity();
      $label = $this->getBundleLabel($entity);
      if ($component->isLayout()) {
        $section = $layout->getLayoutSection($entity);
        $layout_plugin_id = $section->getLayoutId();
        $definition = $this->layoutPluginManager->getDefinition($layout_plugin_id);
        $label .= ' - ' . $definition->getLabel();
      }

      // Check if autosave is enabled for this component.
      $is_autosave_enabled = $this->autosaveHelper->isEnabled($entity->bundle());

      $attributes = new Attribute([
        'data-uuid' => $component->getEntity()->uuid(),
        'data-type' => $entity->bundle(),
        'data-is-layout' => $component->isLayout() ? 'true' : 'false',
        'data-me-autosave' => $is_autosave_enabled ? 'true' : 'false',
      ]);
      if ($component->isLayout()) {
        $attributes->setAttribute('data-layout', $layout_plugin_id);
      }
      $item = [
        'id' => $component->getEntity()->uuid(),
        'entity' => $component->getEntity(),
        'type' => 'component',
        'data-type' => $entity->bundle(),
        'data-me-icon' => $this->componentIcon->getBundleOutlineIcon($component),
        'parent_uuid' => $component->getParentUuid(),
        'sibling_uuid' => $component->getEntity()->uuid(),
        'region_id' => $component->getRegion(),
        'is_layout' => $component->isLayout(),
        'label' => $label,
        'attributes' => $attributes,
      ];
      if ($component->isLayout()) {
        $item['children'] = $this->layoutRegions($component, $layout);
      }
      $items[] = $item;
    }
    return $items;
  }

  /**
   * Processes layout regions to include in the component tree.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsComponent $component
   *   The component to process.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout
   *   The layout to process.
   *
   * @return array
   *   A list of regions with their components.
   */
  protected function layoutRegions(LayoutParagraphsComponent $component, LayoutParagraphsLayout $layout) {
    $section = $layout->getLayoutSection($component->getEntity());
    $layout_config = $section->getLayoutConfiguration();
    $layout_config['layout_paragraphs_section'] = $section;
    $layout_plugin_id = $section->getLayoutId();
    $layout_instance = $this->layoutPluginManager->createInstance($layout_plugin_id, $layout_config);
    $regions = $layout_instance->getPluginDefinition()->getRegionLabels();
    $items = [];
    foreach ($regions as $id => $label) {
      $components = $section->getComponentsForRegion($id);
      $items[] = [
        'id' => $id,
        'region_id' => $id,
        'parent_uuid' => $component->getEntity()->uuid(),
        'sibling_uuid' => '',
        'type' => 'region',
        'label' => $label,
        'children' => $this->componentTree($layout, $components),
        'attributes' => new Attribute([
          'data-region' => $id,
        ]),
      ];
    }
    return $items;

  }

  /**
   * Get the bundle label for the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to get the bundle label for.
   *
   * @return string|null
   *   The bundle label, or NULL if the entity does not have a bundle.
   */
  protected function getBundleLabel($entity) {
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $bundle_entity_type = $this->entityTypeManager
      ->getDefinition($entity_type_id)
      ->getBundleEntityType();

    if ($bundle_entity_type) {
      $bundle_entity = $this->entityTypeManager
        ->getStorage($bundle_entity_type)
        ->load($bundle);
      return $bundle_entity ? $bundle_entity->label() : NULL;
    }

    return NULL;
  }

}
