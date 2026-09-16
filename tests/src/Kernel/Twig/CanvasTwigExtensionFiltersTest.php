<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Twig;

// cspell:ignore itok Fitok Bwidth WHATWG lRrFaDXm FnuBmrOc
use Drupal\canvas\Routing\ParametrizedImageStyleConverter;
use Drupal\canvas\Twig\CanvasTwigExtension;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Image\ImageInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Twig filter functionality.
 *
 * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::toSrcSet
 * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::getWidth
 * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::getHeight
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class CanvasTwigExtensionFiltersTest extends CanvasKernelTestBase {

  use PredictableImageStyleItokTestTrait;

  /**
   * The Canvas Twig extension.
   *
   * @var \Drupal\canvas\Twig\CanvasTwigExtension
   */
  private CanvasTwigExtension $canvasTwigExtension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setupPredictableItok();

    // Mock File entity.
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn('public://balloons.png');
    $file->method('id')->willReturn('123');

    // Mock Image.
    $image = $this->createMock(ImageInterface::class);
    $image->method('getWidth')->willReturn(640);
    $image->method('getHeight')->willReturn(427);
    $image->method('isValid')->willReturn(TRUE);

    // Configure mocks.
    $imageFactory = $this->createMock(ImageFactory::class);
    $imageFactory->method('get')->willReturnCallback(function (string $path) use ($image) {
      if (\in_array($path, [
        'public://balloons.png',
        'public://my test image.png',
        'core/misc/druplicon.png',
      ], TRUE)) {
        return $image;
      }
      $invalid_image = $this->createMock(ImageInterface::class);
      $invalid_image->method('isValid')->willReturn(FALSE);
      return $invalid_image;
    });
    $streamWrapperManager = $this->container->get(StreamWrapperManagerInterface::class);
    $fileUrlGenerator = $this->container->get(FileUrlGeneratorInterface::class);
    $renderer = $this->container->get('renderer');

    // Create the extension instance.
    $this->canvasTwigExtension = new CanvasTwigExtension($streamWrapperManager, $imageFactory, $fileUrlGenerator, $renderer);
    $test_base_url = 'http://localhost/sites/default/files';
    $this->setSetting('file_public_base_url', $test_base_url);
  }

  /**
   * Tests toSrcSet filter.
   */
  #[DataProvider('providerToSrcSet')]
  public function testToSrcSet(string $src, ?int $intrinsicImageWidth, ?string $expected): void {
    $actual = $this->canvasTwigExtension->toSrcSet($src, $intrinsicImageWidth);
    $this->assertSame($expected, $actual);
  }

  /**
   * Data provider for testToSrcSet.
   */
  public static function providerToSrcSet(): \Generator {
    $actual_width = 640;
    $expect_all_srcset_widths = self::generateExpectedSrcSet(
      self::getWidthsIncludingNextLarger($actual_width)
    );

    yield 'public stream wrapper image' => [
      'public://balloons.png',
      $actual_width,
      $expect_all_srcset_widths,
    ];

    yield 'public stream wrapper image, no given width — should inspect image to fetch actual width' => [
      'public://balloons.png',
      NULL,
      $expect_all_srcset_widths,
    ];

    yield 'public stream wrapper image with query and fragment' => [
      'public://balloons.png?foo=bar#fragment',
      NULL,
      $expect_all_srcset_widths,
    ];

    yield 'public stream wrapper image, provided width is bigger than actual width' => [
      'public://balloons.png',
      1024,
      $expect_all_srcset_widths,
    ];

    yield 'public stream wrapper image, provided width is smaller than actual width' => [
      'public://balloons.png',
      200,
      self::generateExpectedSrcSet(
        self::getWidthsIncludingNextLarger(200)
      ),
    ];

    yield 'URL with alternateWidths query param and no intrinsic width — should inspect image' => [
      '/sites/default/files/balloons.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/balloons.png.avif%3Fitok%3DTeB392qG',
      NULL,
      $expect_all_srcset_widths,
    ];

    yield 'URL with alternateWidths and filename with spaces preserves %20' => [
      '/sites/default/files/my%20test%20image.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/my%2520test%2520image.png.avif%3Fitok%3DlRrFaDXm',
      NULL,
      self::generateExpectedSrcSet(
        self::getWidthsIncludingNextLarger($actual_width),
        'public/my%20test%20image.png.avif?itok=lRrFaDXm'
      ),
    ];

    yield 'stream wrapper image with spaces in filename' => [
      'public://my%20test%20image.png',
      NULL,
      self::generateExpectedSrcSet(
        self::getWidthsIncludingNextLarger($actual_width),
        'public/my%20test%20image.png.avif?itok=lRrFaDXm'
      ),
    ];
  }

  /**
   * Tests getWidth filter.
   */
  #[DataProvider('providerTestGetWidth')]
  public function testGetWidth(string $src, ?int $expected): void {
    $this->assertSame($expected, $this->canvasTwigExtension->getWidth($src));
  }

  /**
   * Data provider for testGetWidth.
   */
  public static function providerTestGetWidth(): \Generator {
    yield 'stream wrapper' => ['public://balloons.png', 640];
    yield 'stream wrapper with query and fragment' => ['public://balloons.png?foo=bar#hash', 640];
    yield 'stream wrapper with encoded space' => ['public://my%20test%20image.png', 640];
    yield 'URL with alternateWidths' => [
      '/sites/default/files/balloons.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/balloons.png.avif%3Fitok%3DTeB392qG',
      640,
    ];
    yield 'URL with alternateWidths and spaces' => [
      '/sites/default/files/my%20test%20image.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/my%2520test%2520image.png.avif%3Fitok%3DlRrFaDXm',
      640,
    ];
    yield 'relative path in public files' => ['/sites/default/files/balloons.png', 640];
    yield 'relative path in core' => ['core/misc/druplicon.png', 640];
    yield 'root-relative path in core' => ['/core/misc/druplicon.png', 640];
    yield 'invalid or non-existent file' => ['/sites/default/files/nonexistent.png', NULL];
    yield 'external URL' => ['https://placehold.co/600x400', NULL];
  }

  /**
   * Tests getHeight filter.
   */
  #[DataProvider('providerTestGetHeight')]
  public function testGetHeight(string $src, ?int $expected): void {
    $this->assertSame($expected, $this->canvasTwigExtension->getHeight($src));
  }

  /**
   * Data provider for testGetHeight.
   */
  public static function providerTestGetHeight(): \Generator {
    yield 'stream wrapper' => ['public://balloons.png', 427];
    yield 'stream wrapper with query and fragment' => ['public://balloons.png?foo=bar#hash', 427];
    yield 'stream wrapper with encoded space' => ['public://my%20test%20image.png', 427];
    yield 'URL with alternateWidths' => [
      '/sites/default/files/balloons.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/balloons.png.avif%3Fitok%3DTeB392qG',
      427,
    ];
    yield 'URL with alternateWidths and spaces' => [
      '/sites/default/files/my%20test%20image.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/my%2520test%2520image.png.avif%3Fitok%3DlRrFaDXm',
      427,
    ];
    yield 'relative path in public files' => ['/sites/default/files/balloons.png', 427];
    yield 'relative path in core' => ['core/misc/druplicon.png', 427];
    yield 'root-relative path in core' => ['/core/misc/druplicon.png', 427];
    yield 'invalid or non-existent file' => ['/sites/default/files/nonexistent.png', NULL];
    yield 'external URL' => ['https://placehold.co/600x400', NULL];
  }

  /**
   * Gets allowed widths up to the target width, plus the next larger width.
   */
  private static function getWidthsIncludingNextLarger(int $target_width): array {
    $widths = [];
    foreach (ParametrizedImageStyleConverter::ALLOWED_WIDTHS as $allowed_width) {
      $widths[] = $allowed_width;
      if ($allowed_width > $target_width) {
        break;
      }
    }
    return $widths;
  }

  /**
   * Generates expected srcset for given widths and target relative path.
   */
  private static function generateExpectedSrcSet(array $widths, string $relative_path = 'public/balloons.png.avif?itok=TeB392qG'): string {
    return implode(', ', \array_map(
      fn ($width) => "/sites/default/files/styles/canvas_parametrized_width--$width/$relative_path {$width}w",
      $widths
    ));
  }

}
