<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\PathProcessor;

use Drupal\canvas\PathProcessor\CanvasPathProcessor;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests Drupal\canvas\PathProcessor\CanvasPathProcessor.
 */
#[CoversClass(CanvasPathProcessor::class)]
#[Group('canvas')]
final class CanvasPathProcessorTest extends UnitTestCase {

  /**
   * Tests processInbound.
   *
   * @legacy-covers ::processInbound
   */
  #[DataProvider('data')]
  public function testProcessInbound(string $path, string $expected): void {
    $sut = new CanvasPathProcessor();
    self::assertSame($expected, $sut->processInbound($path, Request::create($path)));
  }

  public static function data(): array {
    return [
      'non-canvas path is untouched' => [
        '/node/1',
        '/node/1',
      ],
      'canvas API path is untouched' => [
        '/canvas/api/v0/config/js_component',
        '/canvas/api/v0/config/js_component',
      ],
      'code editor preview shell is untouched' => [
        '/canvas/code-editor-preview',
        '/canvas/code-editor-preview',
      ],
      'code editor preview script is untouched' => [
        '/canvas/code-editor-preview/script.js',
        '/canvas/code-editor-preview/script.js',
      ],
      'code editor preview asset is untouched' => [
        '/canvas/code-editor-preview/asset/preact.module.js',
        '/canvas/code-editor-preview/asset/preact.module.js',
      ],
      'extension deep link is truncated to its own route' => [
        '/canvas/app/foo/some/deep/page',
        '/canvas/app/foo',
      ],
      'editor deep link collapses to the bare bootstrap route' => [
        '/canvas/editor/canvas_page/1',
        '/canvas',
      ],
    ];
  }

}
