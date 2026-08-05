<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\entity_reference_revisions\EntityNeedsSaveInterface;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;
use Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository;

/**
 * Decorates the LayoutParagraphsLayoutTempstoreRepository class.
 *
 * Syncs changes made to Layout Paragraphs Layout instances to and from the
 * Mercury Editor tempstore, ensuring that the Mercury Editor entity remains in
 * sync with the Layout Paragraphs instance.
 */
class LayoutParagraphsLayoutTempstoreRepositoryDecorator extends LayoutParagraphsLayoutTempstoreRepository {

  /**
   * Constructs a new LayoutParagraphsLayoutTempstoreRepositoryDecorator object.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempstore_private
   *   The private tempstore factory service.
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayoutTempstoreRepository $originalService
   *   The original layout paragraphs tempstore repository service.
   * @param \Drupal\mercury_editor\MercuryEditorTempstore $mercuryEditorTempstore
   *   The mercury editor tempstore service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    PrivateTempStoreFactory $tempstore_private,
    protected LayoutParagraphsLayoutTempstoreRepository $originalService,
    protected MercuryEditorTempstore $mercuryEditorTempstore,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->tempStoreFactory = $tempstore_private;
  }

  /**
   * Extend set function to also set changes in Mercury Editor tempstore.
   *
   * This ensures that every time a change is made to a Layout Paragraphs Layout
   * instance, the change is applied to the parent entity and set in the Mercury
   * Editor Tempstore as well, preventing the ME entity from becoming out of
   * sync with the Layout Paragraphs instance.
   *
   * Also accommodates translations.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The layout paragraphs layout.
   */
  public function set(LayoutParagraphsLayout $layout_paragraphs_layout) {
    $parent_entity = $layout_paragraphs_layout->getEntity();
    $field_name = $layout_paragraphs_layout->getFieldName();
    $parent_uuid = $parent_entity->uuid();
    $me_entity = $this->mercuryEditorTempstore->get($parent_uuid);

    if ($me_entity && $me_entity instanceof ContentEntityInterface) {
      $items = $this->getParagraphReferenceFieldTranslations(
        $layout_paragraphs_layout->getParagraphsReferenceField(),
        $me_entity->language()->getId()
      );
      $me_entity->set($field_name, $items);
      $this->mercuryEditorTempstore->set($me_entity);
    }
    return $this->originalService->set($layout_paragraphs_layout);
  }

  /**
   * Get a layout paragraphs layout from the tempstore using its storage key.
   *
   * @param string $key
   *   The storage key.
   *
   * @return \Drupal\layout_paragraphs\LayoutParagraphsLayout|null
   *   The layout.
   */
  public function getWithStorageKey(string $key) {
    $layout = $this->originalService->getWithStorageKey($key);
    if ($layout) {
      $this->setLayoutTranslatedParagraphsReferenceField($layout);
    }
    return $layout;
  }

  /**
   * Get a layout paragraphs layout from the tempstore.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   A layout paragraphs layout instance.
   *
   * @return \Drupal\layout_paragraphs\LayoutParagraphsLayout
   *   The layout paragraphs layout instance from the tempstore.
   */
  public function get(LayoutParagraphsLayout $layout_paragraphs_layout) {
    $layout = $this->originalService->get($layout_paragraphs_layout);
    if ($layout) {
      $this->setLayoutTranslatedParagraphsReferenceField($layout);
    }
    return $layout;
  }

  /**
   * Set the translated paragraphs for a layout paragraphs layout.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout_paragraphs_layout
   *   The layout paragraphs layout instance.
   */
  protected function setLayoutTranslatedParagraphsReferenceField(
    LayoutParagraphsLayout &$layout_paragraphs_layout,
  ) {
    $field_name = $layout_paragraphs_layout->getFieldName();
    $me_entity = $this->mercuryEditorTempstore->get($layout_paragraphs_layout->getEntity()->uuid());
    if ($me_entity && $me_entity instanceof ContentEntityInterface) {
      $langcode = $me_entity->language()->getId();

      // The ME entity is the authoritative source for what paragraphs belong
      // to this entity (it may contain paragraphs added/removed/reordered by
      // other fields or concurrent operations). However, the LP layout's
      // paragraph entities preserve unsaved in-memory changes (e.g. reordered
      // media items) that survive LP tempstore serialization but are lost in
      // ME tempstore serialization (ContentEntityBase::__sleep clears
      // $this->fields, causing entity references to lazy-load from DB with
      // stale field values).
      //
      // Strategy: use the ME entity's field as the authoritative list of items
      // (for correct paragraph ordering and translation handling), but
      // re-inject any LP paragraph entity objects that have needsSave=TRUE to
      // preserve their unsaved field-level changes.
      //
      // Step 1: Collect paragraph entities with unsaved changes from the LP
      // layout. Index by target_id for existing paragraphs and by UUID for
      // new paragraphs.
      $lp_entities_by_target_id = [];
      $lp_entities_by_uuid = [];
      foreach ($layout_paragraphs_layout->getParagraphsReferenceField() as $item) {
        if ($item->entity) {
          $entity = $item->entity;
          if ($entity->id()) {
            $lp_entities_by_target_id[$item->target_id] = $entity;
          }
          else {
            $lp_entities_by_uuid[$entity->uuid()] = $entity;
          }
        }
      }

      // Step 2: Build the translated reference field from the ME entity.
      $me_entity->set($field_name, $this->getParagraphReferenceFieldTranslations($me_entity->$field_name, $langcode));
      $layout_paragraphs_layout->setParagraphsReferenceField($me_entity->$field_name);

      // Step 3: Re-inject LP entity objects into the new reference field.
      // For entities with needsSave=TRUE, this preserves unsaved changes
      // (e.g. reordered media items, edited text) that were lost during ME
      // tempstore serialization. For all entities, this avoids unnecessary
      // entity lazy-loads from the database which can return stale data.
      if (!empty($lp_entities_by_target_id) || !empty($lp_entities_by_uuid)) {
        foreach ($layout_paragraphs_layout->getParagraphsReferenceField() as $delta => $item) {
          $lp_entity = NULL;
          if ($item->target_id && isset($lp_entities_by_target_id[$item->target_id])) {
            $lp_entity = $lp_entities_by_target_id[$item->target_id];
          }
          elseif (!$item->target_id && $item->entity && isset($lp_entities_by_uuid[$item->entity->uuid()])) {
            $lp_entity = $lp_entities_by_uuid[$item->entity->uuid()];
          }
          if ($lp_entity) {
            // Use the LP entity's translation to match the ME entity language.
            if ($lp_entity instanceof TranslatableInterface && $lp_entity->isTranslatable()) {
              if ($lp_entity->hasTranslation($langcode)) {
                $lp_entity = $lp_entity->getTranslation($langcode);
              }
            }
            $layout_paragraphs_layout->getParagraphsReferenceField()[$delta]->setValue([
              'entity' => $lp_entity,
              'target_id' => $item->target_id,
              'target_revision_id' => $item->target_revision_id,
            ], FALSE);
            $lp_entity->_referringItem = $layout_paragraphs_layout->getParagraphsReferenceField()[$delta];
            $lp_entity->_layoutParagraphsLayout = $layout_paragraphs_layout;
          }
        }
      }
    }
  }

  /**
   * Get the translations of the paragraph reference field.
   *
   * @param \Drupal\entity_reference_revisions\EntityReferenceFieldItemListInterface $reference_field
   *   The reference field item list.
   * @param string $langcode
   *   The language code for which to retrieve translations.
   *
   * @return array
   *   An array of translated paragraph references.
   */
  protected function getParagraphReferenceFieldTranslations(
    EntityReferenceFieldItemListInterface $reference_field,
    string $langcode,
  ) {
    $items = [];
    foreach ($reference_field as $item) {
      $entity = $item->entity;
      if (!$entity) {
        // Entity could not be loaded (e.g. orphaned reference). Use target_id
        // and target_revision_id from the field item if available, skip if not.
        if ($item->target_id) {
          $items[] = [
            'target_id' => $item->target_id,
            'target_revision_id' => $item->target_revision_id,
          ];
        }
        continue;
      }
      $is_translated = FALSE;
      if (
        $entity instanceof TranslatableInterface
          && $entity->isTranslatable()
          && $entity->language()->getId() !== $langcode
      ) {
        $is_translated = TRUE;
        if ($entity->hasTranslation($langcode)) {
          $entity = $entity->getTranslation($langcode);
        }
        else {
          // If the translation does not exist, create it.
          $entity = $entity->addTranslation($langcode, $entity->toArray());
        }
      }
      // For existing paragraphs, return reference-only arrays with just
      // target_id and target_revision_id — do NOT include the entity object.
      //
      // When an entity object is passed through
      // EntityReferenceRevisionsItem::setValue(), the field item's
      // onChange('entity') handler fires and recalculates target_id from
      // entity->id(). If the entity object's id() returns NULL for any reason
      // (entityKeys cache cleared by onChange during translation handling,
      // read-only id field preventing recalculation, tempstore
      // serialization/deserialization breaking internal references), the
      // target_id is overwritten with NULL. This makes hasNewEntity() return
      // TRUE and causes an INSERT that collides with the existing UUID.
      //
      // By omitting the entity object for existing paragraphs, we avoid the
      // onChange('entity') chain entirely. The entity will be lazy-loaded from
      // the database (via target_id/target_revision_id) when it is actually
      // needed, ensuring it always has a valid ID from the authoritative
      // database record.
      //
      // Entities that need saving (needsSave=TRUE) retain their entity object
      // so that unsaved changes (e.g. from component editing) are preserved.
      $needs_save = $entity instanceof EntityNeedsSaveInterface && $entity->needsSave();
      // If the entity appears new (id=NULL) but its UUID exists in the
      // database, this is a stale entity object from a concurrent request that
      // hasn't been updated with the freshly-saved IDs. This happens when:
      // 1. A new paragraph is created in tempstore (id=NULL)
      // 2. The Save button persists it to the DB (gets a real ID)
      // 3. A concurrent autosave request still holds the old layout with
      //    the id=NULL entity object
      // 4. That autosave calls setComponent() + decorator.set(), writing the
      //    stale entity back
      // We recover the IDs from the database to prevent the stale entity from
      // overwriting the correct IDs in ME tempstore.
      if (!$entity->id() && $entity->uuid()) {
        $existing = $this->entityTypeManager
          ->getStorage('paragraph')
          ->loadByProperties(['uuid' => $entity->uuid()]);
        if (!empty($existing)) {
          /** @var \Drupal\Core\Entity\RevisionableInterface $existing_paragraph */
          $existing_paragraph = reset($existing);
          $items[] = [
            'target_id' => $existing_paragraph->id(),
            'target_revision_id' => $existing_paragraph->getRevisionId(),
          ];
          continue;
        }
      }
      if ($entity->id()) {
        $entity->enforceIsNew(FALSE);
        if ($needs_save || $is_translated) {
          // Include the entity object when it needs saving OR when a specific
          // translation was applied, so the correct language is preserved in
          // the layout. Both target_id and target_revision_id are provided
          // explicitly alongside the entity to prevent onChange() from
          // recalculating target_id from entity->id() and potentially
          // clobbering it.
          $items[] = [
            'entity' => $entity,
            'target_id' => $entity->id(),
            'target_revision_id' => $entity->getRevisionId(),
          ];
        }
        else {
          $items[] = [
            'target_id' => $entity->id(),
            'target_revision_id' => $entity->getRevisionId(),
          ];
        }
      }
      else {
        // Genuinely new paragraph — pass the bare entity so it can be saved.
        $items[] = $entity;
      }
    }
    return $items;
  }

  /**
   * Get the Mercury Editor entity for a given layout paragraphs layout.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsLayout $layout
   *   The layout paragraphs layout instance.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The Mercury Editor entity associated with the layout paragraphs layout,
   *   or NULL if it does not exist.
   */
  protected function getMercuryEditorEntity(LayoutParagraphsLayout $layout) {
    return $this->mercuryEditorTempstore->get($layout->getEntity()->uuid());
  }

}
