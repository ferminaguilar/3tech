<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Layout Paragraphs Layout Tempstore Repository class definition.
 */
class MercuryEditorTempstore {

  /**
   * The shared tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStoreFactory;

  /**
   * LayoutTempstoreRepository constructor.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The shared tempstore factory.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory) {
    $this->tempStoreFactory = $temp_store_factory->get('mercury_editor');
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
    return $this->tempStoreFactory->get('states-' . $entity->uuid()) ?? [];
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
    return $this->tempStoreFactory->set('states-' . $entity->uuid(), $states) ?? [];
  }

  /**
   * Save the state of the content entity to the tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to save the state of.
   */
  public function saveState(ContentEntityInterface $entity) {
    $state_index = $this->getStateIndex($entity);
    $states = $this->getStates($entity) ?? [];
    $time = time();
    $last_edit = $this->getLastEditTime($entity);
    if ($last_edit && $time - $last_edit < 3) {
      $states[$state_index] = $entity;
    }
    else {
      $states = array_slice($states, 0, $state_index + 1);
      $states[] = $entity;
      $this->setStateIndex($entity, count($states) - 1);
    }
    $this->setLastEditTime($entity);
    $this->setStates($entity, $states);

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
    $states = $this->getStates($entity);
    $entity = $states[$index] ?? $entity;
    return $entity;
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
    return count($this->getStates($entity));
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
    $state_index = min($this->getStateIndex($entity) + 1, count($this->getStates($entity)) - 1);
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
    $this->tempStoreFactory->delete('states-' . $entity->uuid());
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

}
