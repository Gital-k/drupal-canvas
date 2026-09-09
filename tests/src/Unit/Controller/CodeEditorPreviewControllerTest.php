<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Controller;

use Drupal\canvas\Controller\CodeEditorPreviewController;
use Drupal\canvas\GlobalImports;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\Core\Extension\ExtensionPathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests Drupal\canvas\Controller\CodeEditorPreviewController.
 */
#[CoversClass(CodeEditorPreviewController::class)]
#[Group('canvas')]
final class CodeEditorPreviewControllerTest extends TestCase {

  /**
   * Tests importMap() returns only the global imports, as plain JSON.
   *
   * The parent window fetches this to relay each entry into the sandboxed
   * shell as a blob URL; `scopes` (always empty in practice, and not
   * something a flat specifier => URL blob-URL map could represent anyway)
   * is deliberately not included.
   *
   * @legacy-covers ::importMap
   */
  public function testImportMapReturnsOnlyGlobalImports(): void {
    $imports = [
      'preact' => '/modules/contrib/canvas/packages/astro-hydration/dist/preact.module.js?v1',
      'react' => '/modules/contrib/canvas/packages/astro-hydration/dist/compat.module.js?v1',
    ];
    $global_imports = $this->createMock(GlobalImports::class);
    $global_imports->method('getImportMap')->willReturn([
      ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => $imports,
      ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS => [
        '/some/scope/' => ['some-specifier' => '/some/url.js'],
      ],
    ]);

    $controller = new CodeEditorPreviewController(
      $global_imports,
      $this->createMock(ExtensionPathResolver::class),
    );

    $response = $controller->importMap();
    self::assertSame($imports, \json_decode((string) $response->getContent(), TRUE));
  }

}
