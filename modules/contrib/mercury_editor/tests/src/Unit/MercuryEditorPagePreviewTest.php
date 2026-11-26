<?php

namespace Drupal\Tests\mercury_editor\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\mercury_editor\MercuryEditorContextService;
use Drupal\mercury_editor\MercuryEditorPagePreview;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests frontend route rendering and restoration of the editing context.
 */
#[CoversClass(MercuryEditorPagePreview::class)]
#[Group('mercury_editor')]
class MercuryEditorPagePreviewTest extends UnitTestCase {

  /**
   * Creates the renderer with checks for request and context restoration.
   */
  protected function createPreview(bool $throw = FALSE): array {
    $stack = new RequestStack();
    $parent = Request::create('https://example.com/mercury-editor/uuid', 'POST');
    $parent->headers->set('X-Requested-With', 'XMLHttpRequest');
    $stack->push($parent);
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('uuid')->willReturn('uuid');
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('language')->willReturn(new Language(['id' => 'fr']));
    $entity->method('label')->willReturn('Titre');
    $urls = $this->createMock(UrlGeneratorInterface::class);
    $urls->method('generateFromRoute')->with('entity.node.mercury_editor_preview', ['node' => 'uuid'], $this->anything(), FALSE)->willReturn('https://example.com/node/uuid/mercury-editor-preview?me_id=uuid');
    $container = new ContainerBuilder();
    $container->set('url_generator', $urls);
    \Drupal::setContainer($container);

    $context = $this->createMock(MercuryEditorContextService::class);
    $context->method('isPreview')->willReturn(FALSE);
    $preview_states = [TRUE, FALSE];
    $context->expects($this->exactly(2))->method('setPreview')->willReturnCallback(function ($value) use (&$preview_states) {
      $this->assertSame(array_shift($preview_states), $value);
    });
    $theme_manager = $this->createMock(ThemeManagerInterface::class);
    $admin = $this->createMock(ActiveTheme::class);
    $frontend = $this->createMock(ActiveTheme::class);
    $theme_manager->method('getActiveTheme')->willReturn($admin);
    $themes = [$frontend, $admin];
    $theme_manager->expects($this->exactly(2))->method('setActiveTheme')->willReturnCallback(function ($theme) use (&$themes) {
      $this->assertSame(array_shift($themes), $theme);
    });
    $initializer = $this->createMock(ThemeInitializationInterface::class);
    $initializer->method('getActiveThemeByName')->with('frontend')->willReturn($frontend);
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->method('handle')->willReturnCallback(function ($request, $type, $catch) use ($stack, $throw) {
      $this->assertSame($request, $stack->getCurrentRequest());
      $this->assertSame('GET', $request->getMethod());
      $this->assertFalse($request->isXmlHttpRequest());
      $this->assertTrue($request->attributes->get('_mercury_editor_page_refresh'));
      $this->assertSame(HttpKernelInterface::SUB_REQUEST, $type);
      $this->assertFalse($catch);
      if ($throw) {
        throw new \RuntimeException('Page rendering failed.');
      }
      $response = new HtmlResponse('<html><head><title>Titre &amp; héro</title></head><body class="preview"><header>Héro mis à jour</header><article>Content</article><script src="/app.js"></script></body></html>');
      $response->setAttachments(['library' => ['test/hero'], 'drupalSettings' => ['hero' => TRUE]]);
      return $response;
    });
    $renderer = new MercuryEditorPagePreview($kernel, $stack, $context, $theme_manager, $initializer, $this->getConfigFactoryStub(['system.theme' => ['default' => 'frontend']]));
    return [$renderer, $entity, $stack, $parent];
  }

  /**
   * Tests body markup, attachments, and document title for morphing.
   */
  public function testBuild(): void {
    [$renderer, $entity, $stack, $parent] = $this->createPreview();
    $build = $renderer->build($entity);
    $this->assertStringContainsString('<body class="preview">', (string) $build['#markup']);
    $this->assertStringContainsString('Héro mis à jour', (string) $build['#markup']);
    $this->assertStringNotContainsString('<script', (string) $build['#markup']);
    $this->assertSame('Titre & héro', $build['#title']);
    $this->assertSame(['test/hero'], $build['#attached']['library']);
    $this->assertTrue($build['#attached']['drupalSettings']['hero']);
    $this->assertSame($parent, $stack->getCurrentRequest());
  }

  /**
   * Tests exceptions cannot leave the subrequest on Drupal's request stack.
   */
  public function testFailureRestoresContext(): void {
    [$renderer, $entity, $stack, $parent] = $this->createPreview(TRUE);
    try {
      $renderer->build($entity);
      $this->fail('Rendering should fail.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Page rendering failed.', $exception->getMessage());
    }
    $this->assertSame($parent, $stack->getCurrentRequest());
  }

}
