<?php

namespace Drupal\mercury_editor\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Event that is fired before a new component is created.
 */
class BeforeCreateComponentEvent extends Event {

  // This makes it easier for subscribers to reliably use our event name.
  const EVENT_NAME = 'before_create_component';

  /**
   * Constructs the object.
   *
   * @param string $paragraphType
   *   The paragraph type.
   * @param array $defaultValues
   *   The default values for the paragraph.
   */
  public function __construct(
    protected string $paragraphType,
    protected array $defaultValues,
  ) {
  }

  /**
   * Sets the paragraph type.
   *
   * @param string $paragraphType
   *   The paragraph type.
   *
   * @return $this
   */
  public function setParagraphType(string $paragraphType): self {
    $this->paragraphType = $paragraphType;
    return $this;
  }

  /**
   * Gets the paragraph type.
   *
   * @return string
   *   The paragraph type.
   */
  public function getParagraphType(): string {
    return $this->paragraphType;
  }

  /**
   * Sets the default values for the paragraph.
   *
   * @param array $defaultValues
   *   The default values for the paragraph.
   *
   * @return $this
   */
  public function setDefaultValues(array $defaultValues): self {
    $this->defaultValues = $defaultValues;
    return $this;
  }

  /**
   * Gets the default values for the paragraph.
   *
   * @return array
   *   The default values for the paragraph.
   */
  public function getDefaultValues(): array {
    return $this->defaultValues;
  }

}
