<?php

namespace Drupal\Tests\mercury_editor\Unit;

use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Render\Markup;
use Drupal\mercury_editor\Ajax\IFrameAjaxResponseWrapper;
use Drupal\mercury_editor\Ajax\IFrameCommandsWrapperCommand;
use Drupal\mercury_editor\Ajax\MercuryMorphCommand;
use Drupal\mercury_editor\BlockPreviewOptions;
use Drupal\mercury_editor\MercuryEditorContextService;
use Drupal\mercury_editor\MercuryEditorPagePreview;
use Drupal\mercury_editor\MercuryEditorPreviewService;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests configured preview scope and use of the existing morph command.
 */
#[CoversClass(MercuryEditorPreviewService::class)]
#[Group('mercury_editor')]
class MercuryEditorPreviewModeTest extends UnitTestCase {

  /**
   * Preview scope configurations, including an unrelated bundle.
   */
  public static function modes(): array {
    return [
      'default' => [[], FALSE],
      'enabled' => [['node' => ['page' => 'page']], TRUE],
      'other bundle' => [['node' => ['article' => 'article']], FALSE],
    ];
  }

  /**
   * Tests entity-only and page-wide updates both use MercuryMorphCommand.
   */
  #[DataProvider('modes')]
  public function testPreviewScope(array $settings, bool $full_page): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('uuid')->willReturn('uuid');
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('language')->willReturn(new Language(['id' => 'en']));
    $context = $this->createMock(MercuryEditorContextService::class);
    $context->method('getEntity')->willReturn($entity);
    $page = $this->createMock(MercuryEditorPagePreview::class);
    $page->expects($full_page ? $this->once() : $this->never())->method('build')->with($entity)->willReturn([
      '#markup' => Markup::create('<body><header>Hero</header><article>Page</article></body>'),
      '#title' => 'Updated title',
    ]);
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $view = $this->createMock(EntityViewBuilderInterface::class);
    $types->expects($full_page ? $this->never() : $this->once())->method('getViewBuilder')->with('node')->willReturn($view);
    $view->method('view')->with($entity, 'full', 'en')->willReturn(['#markup' => 'Entity']);
    $commands = [];
    $wrapper = $this->createMock(IFrameAjaxResponseWrapper::class);
    $wrapper->method('addCommand')->willReturnCallback(function (CommandInterface $command) use (&$commands, $wrapper) {
      $commands[] = $command;
      return $wrapper;
    });
    $wrapper->method('getWrapperCommand')->willReturn(new IFrameCommandsWrapperCommand([]));
    $service = $this->getMockBuilder(MercuryEditorPreviewService::class)
      ->setConstructorArgs([
        $context,
        $this->createMock(MercuryEditorTempstore::class),
        $wrapper,
        $types,
        $this->createMock(EventDispatcherInterface::class),
        $this->createStub(BlockPreviewOptions::class),
        new RequestStack(),
        $this->getConfigFactoryStub(['mercury_editor.settings' => ['full_page_preview' => $settings]]),
        $page,
      ])
      ->onlyMethods(['updateEntityTitle'])
      ->getMock();
    $service->expects($full_page ? $this->never() : $this->once())->method('updateEntityTitle');
    $response = $service->ajaxUpdatePreview($entity);
    $this->assertInstanceOf(MercuryMorphCommand::class, $commands[0]);
    // Inspect the selector without requiring a bootstrapped Drupal renderer.
    $property = new \ReflectionProperty(get_parent_class($commands[0]), 'selector');
    $this->assertSame($full_page ? 'body' : '[data-me-edit-screen-key="uuid"]', $property->getValue($commands[0]));
    $this->assertSame('mercuryEditorUpdateState', $response->getCommands()[0]['command']);
  }

}
