<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Twig;

use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Image\ImageInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\canvas\Twig\CanvasTwigExtension;
use Drupal\canvas\Routing\ParametrizedImageStyleConverter;
use Drupal\file\FileInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;

// cspell:ignore itok
/**
 * Tests Twig filter functionality.
 *
 * @group canvas
 * @covers \Drupal\canvas\Twig\CanvasTwigExtension::toSrcSet
 * @covers \Drupal\canvas\Twig\CanvasTwigExtension::applyImageStyle
 */
#[RunTestsInSeparateProcesses]
class CanvasTwigExtensionFiltersTest extends CanvasKernelTestBase {

  /**
   * The Canvas Twig extension under test.
   *
   * @var \Drupal\canvas\Twig\CanvasTwigExtension
   */
  private CanvasTwigExtension $canvasTwigExtension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Fixate the private key & hash salt to get predictable `itok`.
    $this->container->get('state')->set('system.private_key', 'dynamic_image_style_private_key');
    $settings_class = new \ReflectionClass(Settings::class);
    $instance_property = $settings_class->getProperty('instance');
    $settings = new Settings([
      'hash_salt' => 'dynamic_image_style_hash_salt',
    ]);
    $instance_property->setValue(NULL, $settings);

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
    $imageFactory->method('get')->with('public://balloons.png')->willReturn($image);
    $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $streamWrapperManager->method('isValidUri')->willReturn(TRUE);
    $fileUrlGenerator = $this->container->get('file_url_generator');

    // Create the extension instance.
    $this->canvasTwigExtension = new CanvasTwigExtension($streamWrapperManager, $imageFactory, $fileUrlGenerator);

    $test_base_url = 'http://localhost/sites/default/files';
    $this->setSetting('file_public_base_url', $test_base_url);
  }

  /**
   * @dataProvider providerToSrcSet
   */
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
      array_filter(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, fn($w) => $w <= $actual_width)
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

    yield 'public stream wrapper image, provided width is bigger than actual width' => [
      'public://balloons.png',
      1024,
      $expect_all_srcset_widths,
    ];

    yield 'public stream wrapper image, provided width is smaller than actual width' => [
      'public://balloons.png',
      200,
      self::generateExpectedSrcSet(
        array_filter(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, fn($w) => $w <= 200)
      ),
    ];
  }

  /**
   * Generate expected srcset for balloons.png.
   */
  private static function generateExpectedSrcSet(array $widths): string {
    return implode(', ', \array_map(
      fn ($width) => "/sites/default/files/styles/canvas_parametrized_width--$width/public/balloons.png.avif?itok=Oa4IMo7_ {$width}w",
      $widths
    ));
  }

  /**
   * Tests the apply_image_style filter with stream wrapper URI.
   *
   * @covers \Drupal\canvas\Twig\CanvasTwigExtension::applyImageStyle
   */
  public function testApplyImageStyleWithStreamWrapperUri(): void {
    // Create a test image style with a resize effect.
    $style = ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ]);
    $style->addImageEffect([
      'id' => 'image_scale',
      'data' => ['width' => 200, 'height' => NULL, 'upscale' => FALSE],
    ]);
    $style->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    $image = [
      'src' => 'public://test-image.jpg',
      'width' => 800,
      'height' => 600,
      'alt' => 'Test image',
    ];
    $result = $extension->applyImageStyle($image, 'test_style');

    $this->assertIsArray($result);
    // Should return a URL for the styled derivative.
    $this->assertStringContainsString('/styles/test_style/', $result['src']);
    $this->assertStringContainsString('test-image.jpg', $result['src']);
    // Dimensions should be transformed (scaled to width 200).
    $this->assertEquals(200, $result['width']);
    $this->assertEquals(150, $result['height']);
    // Alt should be preserved.
    $this->assertEquals('Test image', $result['alt']);
  }

  /**
   * Tests the apply_image_style filter with local file URL.
   *
   * @covers \Drupal\canvas\Twig\CanvasTwigExtension::applyImageStyle
   */
  public function testApplyImageStyleWithLocalFileUrl(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    // Test with local file URL matching the actual public files path.
    $publicBasePath = PublicStream::basePath();
    $image = [
      'src' => '/' . $publicBasePath . '/test-image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $result = $extension->applyImageStyle($image, 'test_style');

    $this->assertIsArray($result);
    // Should return a URL for the styled derivative.
    $this->assertStringContainsString('/styles/test_style/', $result['src']);
    $this->assertStringContainsString('test-image.jpg', $result['src']);
  }

  /**
   * Tests the apply_image_style filter with external URL returns unchanged.
   *
   * @covers \Drupal\canvas\Twig\CanvasTwigExtension::applyImageStyle
   */
  public function testApplyImageStyleWithExternalUrl(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    $externalUrl = 'https://example.com/image.jpg';
    $image = [
      'src' => $externalUrl,
      'width' => 400,
      'height' => 300,
    ];
    $result = $extension->applyImageStyle($image, 'test_style');

    // External URLs should be returned unchanged.
    $this->assertIsArray($result);
    $this->assertSame($externalUrl, $result['src']);
    $this->assertSame(400, $result['width']);
    $this->assertSame(300, $result['height']);
  }

  /**
   * Tests the apply_image_style filter with local file outside public path.
   *
   * @covers \Drupal\canvas\Twig\CanvasTwigExtension::applyImageStyle
   */
  public function testApplyImageStyleWithLocalFileOutsidePublicPath(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    // Test with a local file URL that is NOT inside PublicStream::basePath().
    $image = [
      'src' => '/var/www/other/image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $result = $extension->applyImageStyle($image, 'test_style');

    // Should return unchanged since the file is outside the public files path.
    $this->assertIsArray($result);
    $this->assertSame('/var/www/other/image.jpg', $result['src']);
    $this->assertSame(400, $result['width']);
    $this->assertSame(300, $result['height']);
  }

  /**
   * Tests the apply_image_style filter with invalid style name.
   *
   * @covers \Drupal\canvas\Twig\CanvasTwigExtension::applyImageStyle
   */
  public function testApplyImageStyleWithInvalidStyleName(): void {
    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    $image = [
      'src' => 'public://test-image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $result = $extension->applyImageStyle($image, 'nonexistent_style');

    // With invalid style, the original image should be returned unchanged.
    $this->assertIsArray($result);
    $this->assertSame('public://test-image.jpg', $result['src']);
    $this->assertSame(400, $result['width']);
    $this->assertSame(300, $result['height']);
  }

}
