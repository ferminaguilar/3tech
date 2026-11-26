<?php

namespace Drupal\Tests\mercury_editor\Unit;

use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\Core\Plugin\Context\ContextProviderInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\mercury_editor\Render\PagePreviewContextRepository;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests isolation from contexts cached while building the editing form.
 */
#[CoversClass(PagePreviewContextRepository::class)]
#[Group('mercury_editor')]
class PagePreviewContextRepositoryTest extends UnitTestCase {

  /**
   * Tests page providers do not reuse or replace parent request contexts.
   */
  public function testRequestIsolation(): void {
    $stack = new RequestStack();
    $stack->push(new Request());
    $parent_context = $this->createMock(ContextInterface::class);
    $page_context = $this->createMock(ContextInterface::class);
    $ids = ['@test.provider:node'];
    $inner = $this->createMock(ContextRepositoryInterface::class);
    $inner->expects($this->exactly(2))->method('getRuntimeContexts')->with($ids)->willReturn([$ids[0] => $parent_context]);
    $inner->method('getAvailableContexts')->willReturn([]);
    $provider = $this->createMock(ContextProviderInterface::class);
    $provider->expects($this->once())->method('getRuntimeContexts')->with(['node'])->willReturn(['node' => $page_context]);
    $container = new ContainerBuilder();
    $container->set('test.provider', $provider);
    $repository = new PagePreviewContextRepository($inner, $stack, $container);
    $this->assertSame([$ids[0] => $parent_context], $repository->getRuntimeContexts($ids));

    $preview = new Request();
    $preview->attributes->set('_mercury_editor_page_refresh', TRUE);
    $stack->push($preview);
    $this->assertSame([$ids[0] => $page_context], $repository->getRuntimeContexts($ids));
    $this->assertSame([$ids[0] => $page_context], $repository->getRuntimeContexts($ids));
    $stack->pop();
    $this->assertSame([$ids[0] => $parent_context], $repository->getRuntimeContexts($ids));
    $this->assertSame([], $repository->getAvailableContexts());
  }

}
