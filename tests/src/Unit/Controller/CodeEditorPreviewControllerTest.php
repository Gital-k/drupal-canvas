<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Controller;

use Drupal\canvas\Controller\CodeEditorPreviewController;
use Drupal\canvas\GlobalImports;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Render\RendererInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests Drupal\canvas\Controller\CodeEditorPreviewController.
 */
#[CoversClass(CodeEditorPreviewController::class)]
#[Group('canvas')]
final class CodeEditorPreviewControllerTest extends TestCase {

  /**
   * Tests asset rejects a filename that does not match its own filter.
   *
   * The route's own path requirement regex already restricts what reaches
   * this method; this is the redundant, defense-in-depth check inside the
   * controller itself.
   *
   * @legacy-covers ::asset
   */
  #[DataProvider('providerInvalidFilenames')]
  public function testAssetRejectsInvalidFilename(string $filename): void {
    $extension_path_resolver = $this->createMock(ExtensionPathResolver::class);
    // The filename is rejected before any path is resolved.
    $extension_path_resolver->expects($this->never())->method('getPath');

    $controller = new CodeEditorPreviewController(
      $this->createMock(GlobalImports::class),
      $this->createMock(RendererInterface::class),
      $extension_path_resolver,
    );

    $this->expectException(NotFoundHttpException::class);
    $controller->asset($filename);
  }

  public static function providerInvalidFilenames(): array {
    return [
      'path traversal' => ['../../../etc/passwd.js'],
      'directory separator' => ['foo/bar.js'],
      'wrong extension' => ['preact.module.php'],
      'no extension' => ['preact'],
      'empty string' => [''],
    ];
  }

  /**
   * Tests asset rejects a validly-named file that does not exist on disk.
   *
   * @legacy-covers ::asset
   */
  public function testAssetRejectsMissingFile(): void {
    $extension_path_resolver = $this->createMock(ExtensionPathResolver::class);
    $extension_path_resolver->method('getPath')->willReturn('does/not/exist');

    $controller = new CodeEditorPreviewController(
      $this->createMock(GlobalImports::class),
      $this->createMock(RendererInterface::class),
      $extension_path_resolver,
    );

    $this->expectException(NotFoundHttpException::class);
    $controller->asset('preact.module.js');
  }

}
