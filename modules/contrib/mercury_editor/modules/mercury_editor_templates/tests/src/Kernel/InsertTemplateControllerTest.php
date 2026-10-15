<?php

declare(strict_types=1);

namespace Drupal\Tests\mercury_editor_templates\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mercury_editor_templates\Controller\InsertTemplateController;

/**
 * Tests template insertion.
 *
 * @group mercury_editor_templates
 * @coversDefaultClass \Drupal\mercury_editor_templates\Controller\InsertTemplateController
 */
class InsertTemplateControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'mercury_editor_templates',
  ];

  /**
   * Tests that cloning an empty list returns an empty array.
   *
   * @covers ::cloneList
   */
  public function testCloneListWithEmptyList(): void {
    $result = $this->cloneList([]);

    $this->assertSame([], $result);
  }

  /**
   * Tests that parent mapping does not depend on the input delta order.
   *
   * @covers ::cloneList
   */
  public function testCloneListWithChildBeforeParent(): void {
    $parent = $this->paragraph('parent');
    $child = $this->paragraph('child', 'parent');

    $result = $this->cloneList([$child, $parent]);

    $this->assertCount(2, $result);
    $this->assertSame('parent-clone', $result[1]->uuid());
    $this->assertSame('parent-clone', $result[0]->getAllBehaviorSettings()['layout_paragraphs']['parent_uuid']);
  }

  /**
   * Tests that a missing parent uuid does not abort cloning.
   *
   * @covers ::cloneList
   */
  public function testCloneListWithMissingParent(): void {
    $child = $this->paragraph('child', 'missing-parent');

    $result = $this->cloneList([$child]);

    $this->assertCount(1, $result);
    $this->assertSame('missing-parent', $result[0]->getAllBehaviorSettings()['layout_paragraphs']['parent_uuid']);
  }

  /**
   * Invokes the protected cloneList() method.
   *
   * @param array<object> $list
   *   Paragraph-like objects to clone.
   *
   * @return array<object>
   *   Cloned paragraph-like objects.
   */
  private function cloneList(array $list): array {
    $controller = (new \ReflectionClass(InsertTemplateController::class))
      ->newInstanceWithoutConstructor();
    $clone_list = new \ReflectionMethod(InsertTemplateController::class, 'cloneList');
    $clone_list->setAccessible(TRUE);

    return $clone_list->invoke($controller, $list);
  }

  /**
   * Creates a paragraph-like object for cloneList() tests.
   *
   * @param string $uuid
   *   The source uuid.
   * @param string|null $parent_uuid
   *   The source parent uuid, if any.
   *
   * @return object
   *   A paragraph-like test object.
   */
  private function paragraph(string $uuid, ?string $parent_uuid = NULL): object {
    return new class($uuid, $parent_uuid) {

      /**
       * @var string
       */
      private string $uuid;

      /**
       * @var array<string, array<string, string>>
       */
      private array $settings;

      public function __construct(string $uuid, ?string $parent_uuid) {
        $this->uuid = $uuid;
        $this->settings = ['layout_paragraphs' => $parent_uuid === NULL ? [] : ['parent_uuid' => $parent_uuid]];
      }

      public function uuid(): string {
        return $this->uuid;
      }

      public function createDuplicate(): self {
        return new self($this->uuid . '-clone', $this->settings['layout_paragraphs']['parent_uuid'] ?? NULL);
      }

      public function getAllBehaviorSettings(): array {
        return $this->settings;
      }

      public function setBehaviorSettings(string $name, array $settings): void {
        $this->settings[$name] = $settings;
      }

    };
  }

}
