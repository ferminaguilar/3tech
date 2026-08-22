<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Layout Paragraphs Layout Tempstore Repository class definition.
 */
class MercuryEditorTempstore {

  /**
   * Default max number of states to keep per entity.
   */
  protected const DEFAULT_STATE_HISTORY_LIMIT = 20;

  /**
   * Tempstore key prefix for the state history metadata.
   */
  protected const STATES_METADATA_PREFIX = 'states_metadata-';

  /**
   * Tempstore key prefix for individual state history items.
   */
  protected const STATE_PREFIX = 'state-';

  /**
   * The shared tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStoreFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * LayoutTempstoreRepository constructor.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The shared tempstore factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, ConfigFactoryInterface $config_factory) {
    $this->tempStoreFactory = $temp_store_factory->get('mercury_editor');
    $this->configFactory = $config_factory;
  }

  /**
   * Get a content entity from the tempstore.
   *
   * @param string $uuid
   *   The uuid of the content entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   A content entity.
   */
  public function get($uuid) {
    return $this->tempStoreFactory->get($uuid);
  }

  /**
   * Save a content entity to the tempstore.
   */
  public function set(ContentEntityInterface $entity, $save_state = TRUE) {
    $this->tempStoreFactory->set($entity->uuid(), $entity);
    return $entity;
  }

  /**
   * Set the layout paragraphs field IDs for a content entity in the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to set the layout paragraphs field IDs for.
   * @param array $ids
   *   The layout paragraphs field IDs to set.
   */
  public function setLayoutParagraphsFieldIds(
    ContentEntityInterface $entity,
    array $ids,
  ) {
    $this->tempStoreFactory->set($entity->uuid() . '-layout_paragraphs_field_ids', $ids);
  }

  /**
   * Get the layout paragraphs field IDs for a content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to get the layout paragraphs field IDs for.
   *
   * @return array
   *   The ids.
   */
  public function getLayoutParagraphsFieldIds(
    ContentEntityInterface $entity,
  ) : array {
    return $this->tempStoreFactory->get($entity->uuid() . '-layout_paragraphs_field_ids') ?? [];
  }

  /**
   * Gets the full state history of a content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to get the state history of.
   */
  public function getStates(ContentEntityInterface $entity) {
    $metadata = $this->getStatesMetadata($entity);
    if (!$metadata) {
      return [];
    }

    $states = [];
    for ($index = 0; $index < $metadata['count']; $index++) {
      $state = $this->tempStoreFactory->get($this->getStateKey($entity, $index));
      if ($state instanceof ContentEntityInterface) {
        $states[$index] = $state;
      }
    }
    return $states;
  }

  /**
   * Gets the full state history of a content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to get the state history of.
   * @param array $states
   *   The states to set.
   */
  public function setStates(ContentEntityInterface $entity, $states) {
    $state_history_limit = $this->getStateHistoryLimit();
    $old_count = $this->getStatesCount($entity);
    $states = array_values(array_slice($states, -$state_history_limit));
    foreach ($states as $index => $state) {
      $this->setState($entity, $index, $state);
    }
    for ($index = count($states); $index < $old_count; $index++) {
      $this->deleteState($entity, $index);
    }
    $this->setStatesCount($entity, count($states));
    $this->tempStoreFactory->delete('states-' . $entity->uuid());
    return $states;
  }

  /**
   * Save the state of the content entity to the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to save the state of.
   */
  public function saveState(ContentEntityInterface $entity) {
    $state_index = $this->getStateIndex($entity);
    $state_count = $this->getStatesCount($entity);
    $time = time();
    $last_edit = $this->getLastEditTime($entity);
    if ($last_edit && $time - $last_edit < 3 && $state_count) {
      $this->setState($entity, $state_index, $entity);
    }
    else {
      for ($index = $state_index + 1; $index < $state_count; $index++) {
        $this->deleteState($entity, $index);
      }
      $new_state_index = $state_count ? $state_index + 1 : 0;
      $this->setState($entity, $new_state_index, $entity);
      $this->setStateIndex($entity, $new_state_index);
      $this->setStatesCount($entity, $new_state_index + 1);
    }
    $this->enforceStateHistoryLimit($entity);
    $this->setLastEditTime($entity);
    $this->tempStoreFactory->delete('states-' . $entity->uuid());

    return $entity;
  }

  /**
   * Get the last edit time for a content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to get the last edit time of.
   */
  public function getLastEditTime($entity) {
    return $this->tempStoreFactory->get('last_edit_time-' . $entity->uuid());
  }

  /**
   * Set the last edit time for a content entity in the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to set the last edit time of.
   */
  public function setLastEditTime($entity) {
    return $this->tempStoreFactory->set('last_edit_time-' . $entity->uuid(), time());
  }

  /**
   * Get a specific state by index for a content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to get the state of.
   * @param int $index
   *   The index of the state to get.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The state of the content entity.
   */
  public function getState(ContentEntityInterface $entity, $index) {
    $state = $this->tempStoreFactory->get($this->getStateKey($entity, $index));
    return $state instanceof ContentEntityInterface ? $state : $entity;
  }

  /**
   * Get the current state index for a content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to get the state index of.
   */
  public function getStateIndex(ContentEntityInterface $entity) {
    return $this->tempStoreFactory->get('state_index-' . $entity->uuid()) ?? 0;
  }

  /**
   * Get the count of states for a content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to get the state count of.
   *
   * @return int
   *   The count of states for the content entity.
   */
  public function getStatesCount(ContentEntityInterface $entity) {
    $metadata = $this->tempStoreFactory->get($this->getStatesMetadataKey($entity));
    return is_array($metadata) && isset($metadata['count']) ? (int) $metadata['count'] : 0;
  }

  /**
   * Set the current state index for a content entity in the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to set the state index of.
   * @param int $index
   *   The index of the state to set.
   */
  public function setStateIndex(ContentEntityInterface $entity, $index) {
    $this->tempStoreFactory->set('state_index-' . $entity->uuid(), $index);
  }

  /**
   * Get the previous state of the content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to get the next state of.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The next state of the content entity.
   */
  public function previousState(ContentEntityInterface $entity) {
    $state_index = max(0, $this->getStateIndex($entity) - 1);
    return $this->restoreState($entity, $state_index);
  }

  /**
   * Get the next state of the content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to get the next state of.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The next state of the content entity.
   */
  public function nextState(ContentEntityInterface $entity) {
    $state_index = max(0, min($this->getStateIndex($entity) + 1, $this->getStatesCount($entity) - 1));
    return $this->restoreState($entity, $state_index);
  }

  /**
   * Restores a specific state of the content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to restore the state of.
   * @param int $index
   *   The index of the state to restore.
   */
  public function restoreState(ContentEntityInterface $entity, $index) {
    $this->setStateIndex($entity, $index);
    $entity = $this->getState($entity, $index);
    $this->set($entity, FALSE);
    return $entity;
  }

  /**
   * Clears the state history for an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to clear the state history of.
   */
  public function clearStates(ContentEntityInterface $entity) {
    $metadata = $this->tempStoreFactory->get($this->getStatesMetadataKey($entity));
    $state_count = is_array($metadata) && isset($metadata['count']) ? (int) $metadata['count'] : 0;
    for ($index = 0; $index < $state_count; $index++) {
      $this->deleteState($entity, $index);
    }
    $this->tempStoreFactory->delete('states-' . $entity->uuid());
    $this->tempStoreFactory->delete($this->getStatesMetadataKey($entity));
    $this->tempStoreFactory->delete('state_index-' . $entity->uuid());
  }

  /**
   * Delete a content entity from the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to delete.
   */
  public function delete(ContentEntityInterface $entity) {
    $this->tempStoreFactory->delete($entity->uuid());
  }

  /**
   * Gets the metadata for a content entity's state history.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   *
   * @return array|null
   *   The state metadata, or NULL when no state history has been stored.
   */
  protected function getStatesMetadata(ContentEntityInterface $entity): ?array {
    $metadata = $this->tempStoreFactory->get($this->getStatesMetadataKey($entity));
    if (is_array($metadata) && isset($metadata['count'])) {
      return [
        'count' => (int) $metadata['count'],
      ];
    }

    $states = $this->tempStoreFactory->get('states-' . $entity->uuid()) ?? [];
    if (!$states) {
      return NULL;
    }

    $this->setStates($entity, $states);
    return [
      'count' => count($states),
    ];
  }

  /**
   * Stores an individual content entity state.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity the state belongs to.
   * @param int $index
   *   The state index.
   * @param \Drupal\Core\Entity\ContentEntityInterface $state
   *   The state snapshot.
   */
  protected function setState(ContentEntityInterface $entity, int $index, ContentEntityInterface $state): void {
    $this->tempStoreFactory->set($this->getStateKey($entity, $index), $state);
  }

  /**
   * Deletes an individual content entity state.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity the state belongs to.
   * @param int $index
   *   The state index.
   */
  protected function deleteState(ContentEntityInterface $entity, int $index): void {
    $this->tempStoreFactory->delete($this->getStateKey($entity, $index));
  }

  /**
   * Stores the count of states for a content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   * @param int $count
   *   The state count.
   */
  protected function setStatesCount(ContentEntityInterface $entity, int $count): void {
    $this->tempStoreFactory->set($this->getStatesMetadataKey($entity), [
      'count' => $count,
    ]);
  }

  /**
   * Builds the metadata key for a content entity's state history.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   *
   * @return string
   *   The metadata key.
   */
  protected function getStatesMetadataKey(ContentEntityInterface $entity): string {
    return static::STATES_METADATA_PREFIX . $entity->uuid();
  }

  /**
   * Builds a key for an individual content entity state.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   * @param int $index
   *   The state index.
   *
   * @return string
   *   The state key.
   */
  protected function getStateKey(ContentEntityInterface $entity, int $index): string {
    return static::STATE_PREFIX . $entity->uuid() . '-' . $index;
  }

  /**
   * Gets the configured maximum number of states to keep per entity.
   *
   * @return int
   *   The maximum number of states to keep.
   */
  protected function getStateHistoryLimit(): int {
    $limit = (int) $this->configFactory
      ->get('mercury_editor.settings')
      ->get('state_history_limit');

    return $limit > 0 ? $limit : static::DEFAULT_STATE_HISTORY_LIMIT;
  }

  /**
   * Trims old states when the state count exceeds the configured limit.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   */
  protected function enforceStateHistoryLimit(ContentEntityInterface $entity): void {
    $state_history_limit = $this->getStateHistoryLimit();
    $state_count = $this->getStatesCount($entity);

    if ($state_count <= $state_history_limit) {
      return;
    }

    $state_index = $this->getStateIndex($entity);
    $offset = $state_count - $state_history_limit;

    for ($index = 0; $index < $state_history_limit; $index++) {
      $state = $this->tempStoreFactory->get($this->getStateKey($entity, $index + $offset));
      if ($state instanceof ContentEntityInterface) {
        $this->setState($entity, $index, $state);
      }
      else {
        $this->deleteState($entity, $index);
      }
    }

    for ($index = $state_history_limit; $index < $state_count; $index++) {
      $this->deleteState($entity, $index);
    }

    $this->setStatesCount($entity, $state_history_limit);
    $this->setStateIndex($entity, max(0, min($state_history_limit - 1, $state_index - $offset)));
  }

}
