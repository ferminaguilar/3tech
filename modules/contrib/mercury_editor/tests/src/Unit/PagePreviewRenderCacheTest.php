<?php

namespace Drupal\Tests\mercury_editor\Unit;

use Drupal\Core\Render\RenderCacheInterface;
use Drupal\mercury_editor\Render\PagePreviewRenderCache;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests cache isolation for updated preview pages.
 */
#[CoversClass(PagePreviewRenderCache::class)]
#[Group('mercury_editor')]
class PagePreviewRenderCacheTest extends UnitTestCase {

  /**
   * Tests that only the preview subrequest bypasses cache reads and writes.
   */
  public function testScopedBypass(): void {
    $stack = new RequestStack();
    $stack->push(new Request());
    $inner = $this->createMock(RenderCacheInterface::class);
    $elements = ['#cache' => ['keys' => ['hero']]];
    $cached = ['#markup' => 'Saved title'];
    $inner->expects($this->exactly(2))->method('get')->with($elements)->willReturn($cached);
    $inner->expects($this->never())->method('set');
    if (method_exists(RenderCacheInterface::class, 'getMultiple')) {
      $inner->expects($this->never())->method('getMultiple');
    }
    $inner->method('getCacheableRenderArray')->with($cached)->willReturn($cached);
    $cache = new PagePreviewRenderCache($inner, $stack);
    $this->assertSame($cached, $cache->get($elements));

    $preview = new Request();
    $preview->attributes->set('_mercury_editor_page_refresh', TRUE);
    $stack->push($preview);
    $this->assertFalse($cache->get($elements));
    $this->assertSame([], $cache->getMultiple(['hero' => $elements]));
    $this->assertFalse($cache->set($elements, $elements));
    $this->assertSame($cached, $cache->getCacheableRenderArray($cached));
    $stack->pop();
    $this->assertSame($cached, $cache->get($elements));
  }

  /**
   * Tests normal cache writes and batched reads are delegated unchanged.
   */
  public function testNormalCaching(): void {
    $inner = $this->createMock(RenderCacheInterface::class);
    $elements = ['#markup' => 'Normal page'];
    $inner->expects($this->once())->method('set')->with($elements, $elements);
    if (method_exists(RenderCacheInterface::class, 'getMultiple')) {
      $inner->expects($this->once())->method('getMultiple')->with(['page' => $elements])->willReturn(['page' => $elements]);
    }
    else {
      $inner->expects($this->once())->method('get')->with($elements)->willReturn($elements);
    }
    $cache = new PagePreviewRenderCache($inner, new RequestStack());
    $cache->set($elements, $elements);
    $this->assertSame(['page' => $elements], $cache->getMultiple(['page' => $elements]));
  }

}
