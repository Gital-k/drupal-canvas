<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Twig;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\canvas\Routing\ParametrizedImageStyleConverter;
use Drupal\canvas\Twig\CanvasTwigExtension;
use Drupal\Tests\UnitTestCase;

// cspell:ignore fitok itok Bwidth
/**
 * Tests Twig filter/function functionality.
 *
 * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::toSrcSet
 * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::jsxAttributes
 * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::slot
 */
#[Group('canvas')]
#[Group('Twig')]
class CanvasTwigExtensionFiltersTest extends UnitTestCase {

  /**
   * @var \Drupal\canvas\Twig\CanvasTwigExtension
   */
  private CanvasTwigExtension $canvasTwigExtension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock the required dependencies.
    $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $imageFactory = $this->createMock(ImageFactory::class);
    $fileUrlInterfaceManager = $this->createMock(FileUrlGeneratorInterface::class);
    $renderer = $this->createMock(RendererInterface::class);

    // Create the extension instance
    $this->canvasTwigExtension = new CanvasTwigExtension($streamWrapperManager, $imageFactory, $fileUrlInterfaceManager, $renderer);
  }

  /**
 * Tests to src set.
 */
  #[DataProvider('providerToSrcSet')]
  public function testToSrcSet(string $src, int $intrinsicImageWidth, ?string $expected): void {
    $actual = $this->canvasTwigExtension->toSrcSet($src, $intrinsicImageWidth);
    $this->assertSame($expected, $actual);
  }

  /**
   * Data provider for testToSrcSet.
   */
  public static function providerToSrcSet(): \Generator {
    yield 'simple image without query' => [
      '/simple-image.png',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      NULL,
    ];

    yield 'simple image with fragments' => [
      '/simple-image.png#fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      NULL,
    ];

    yield 'image with non-alternateWidths query' => [
      '/simple-image.png?foo=bar&itok=asdf&baz=lol',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      NULL,
    ];

    yield 'complex image with alternateWidths with the image *exactly* the maximum allowed width — generate even the equally wide src because it may get converted to a more optimized format such as AVIF' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      self::generateExpectedSrcSetForWidths(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
    ];

    yield 'complex image with alternateWidths with the image *slightly smaller* than the maximum allowed width' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS) - 1,
      self::generateExpectedSrcSetForWidths(array_slice(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, 0, -1)),
    ];

    yield 'complex image with alternateWidths with the image *slightly bigger* than the maximum allowed width' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS) + 1,
      self::generateExpectedSrcSetForWidths(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
    ];

    yield 'complex image with alternateWidths and defined intrinsic image width' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#fragment',
      // Simulate a real image width.
      1245,
      // Do not generate higher resolutions than 1200.
      // Same ParametrizedImageStyleConverter::ALLOWED_WIDTHS array but lower
      // values than 1245, the image's real width.
      self::generateExpectedSrcSetForWidths([16, 32, 48, 64, 96, 128, 256, 384, 640, 750, 828, 1080, 1200]),
    ];

    // @todo Add test cases in https://drupal.org/i/3533563 that test a customized set of allowed widths
    // @phpcs:disable
    /*
    yield 'custom widths test' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#and-thats-a-fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      self::generateExpectedSrcSetForWidths([400, 800]),
    ];

    yield 'custom widths with complex url' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk&sort=recent&filter=status:active,owner:me&page=2#view=grid&selected=project-456&panel=details',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      self::generateExpectedSrcSetForWidths([400, 800]),
    ];
    */
    // @phpcs:enable
  }

  private static function generateExpectedSrcSetForWidths(array $widths): string {
    return implode(', ', \array_map(function ($width) {
      return "/sites/default/files/styles/canvas_parametrized_width--$width/public/2025-07/Screenshot 2025-07-08 at 8.56.02.png.webp?itok=Wp4lG4Wk {$width}w";
    }, $widths));
  }

  /**
   * Test invalid URLs.
   *
   * @param string $src
   *   An image URL.
   * @param int $intrinsicImageWidth
   *   The intrinsic width of the image in $src.
   * @param class-string<\Throwable> $expectedException
   *   The expected exception.
   */
  #[DataProvider('invalidProviderToSrcSet')]
  public function testToSrcSetWithInvalidWidth(string $src, int $intrinsicImageWidth, string $expectedException): void {
    $this->expectException($expectedException);
    $this->canvasTwigExtension->toSrcSet($src, $intrinsicImageWidth);
  }

  public static function invalidProviderToSrcSet(): \Generator {
    yield 'simple image with wrongly formatted alternateWidths query' => [
      '/simple-image.png?alternateWidths=something',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      \AssertionError::class,
    ];

    yield 'simple image with two alternateWidths query params' => [
      '/simple-image.png?alternateWidths[]=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-07/image.png.webp%3Fitok%3DWp4lG4Wk#and-thats-a-fragment&alternateWidths[]=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-07/image.png.webp%3Fitok%3DWp4lG4Wk#and-thats-a-fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      \AssertionError::class,
    ];
  }

  /**
   * Tests jsxAttributes() filter.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::jsxAttributes
   */
  #[DataProvider('providerJsxAttributes')]
  public function testJsxAttributes(mixed $input, mixed $expected): void {
    $actual = $this->canvasTwigExtension->jsxAttributes($input);
    $this->assertSame($expected, $actual);
  }

  /**
   * Data provider for testJsxAttributes().
   */
  public static function providerJsxAttributes(): \Generator {
    // Scalars pass through unchanged.
    yield 'string' => ['hello', 'hello'];
    yield 'empty string' => ['', ''];
    yield 'integer' => [42, 42];
    yield 'float' => [3.14, 3.14];
    yield 'boolean true' => [TRUE, TRUE];
    yield 'boolean false' => [FALSE, FALSE];
    yield 'null' => [NULL, NULL];

    // Arrays get JSON-encoded.
    yield 'empty array' => [[], '[]'];
    yield 'indexed array' => [['a', 'b'], '["a","b"]'];
    yield 'associative array' => [
      ['class' => 'foo', 'id' => 'bar'],
      '{"class":"foo","id":"bar"}',
    ];
    yield 'nested array' => [
      ['data' => ['x' => 1]],
      '{"data":{"x":1}}',
    ];

    // Attribute objects: toArray() is called, then JSON-encoded.
    $attr = new Attribute(['class' => ['foo', 'bar'], 'id' => 'my-id']);
    yield 'Attribute object' => [$attr, Json::encode($attr->toArray())];

    // Generic object without toArray() → JSON-encoded.
    $obj = new \stdClass();
    $obj->foo = 'bar';
    yield 'plain stdClass' => [$obj, '{"foo":"bar"}'];

    // Object with a toArray() method (but not Attribute subclass).
    $custom = new class {

      public function toArray(): array {
        return ['key' => 'value'];
      }

    };
    yield 'object with toArray()' => [$custom, '{"key":"value"}'];
  }

  // ---------------------------------------------------------------------------
  // slot
  // ---------------------------------------------------------------------------

  /**
   * Tests slot() with content that does NOT require the renderer.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::slot
   */
  #[DataProvider('providerSlotNoRenderer')]
  public function testSlotNoRenderer(string $slot_name, mixed $content, ?string $expected_markup): void {
    $result = $this->canvasTwigExtension->slot($slot_name, $content);

    if ($expected_markup === NULL) {
      $this->assertNull($result);
    }
    else {
      $this->assertInstanceOf(MarkupInterface::class, $result);
      $this->assertSame($expected_markup, (string) $result);
    }
  }

  /**
   * Data provider for testSlotNoRenderer() – cases that skip the renderer.
   */
  public static function providerSlotNoRenderer(): \Generator {
    // Empty / null content returns NULL.
    yield 'null content' => ['my-slot', NULL, NULL];
    yield 'empty string content' => ['my-slot', '', NULL];
    yield 'empty array content' => ['my-slot', [], NULL];

    // String content wraps in element-prop.
    yield 'string content' => [
      'my-slot',
      'Hello world',
      '<element-prop slot="my-slot">Hello world</element-prop>',
    ];

    // Scalar (non-string) cast to string.
    yield 'integer content' => [
      'count',
      42,
      '<element-prop slot="count">42</element-prop>',
    ];
    yield 'float content' => [
      'ratio',
      1.5,
      '<element-prop slot="ratio">1.5</element-prop>',
    ];

    // MarkupInterface content is cast directly without escaping.
    yield 'markup content' => [
      'body',
      Markup::create('<strong>Bold</strong>'),
      '<element-prop slot="body"><strong>Bold</strong></element-prop>',
    ];

    // Slot name with HTML-special characters is escaped.
    yield 'slot name with quotes' => [
      'foo"bar',
      'content',
      '<element-prop slot="foo&quot;bar">content</element-prop>',
    ];
  }

  /**
   * Tests slot() when the renderer is invoked for render-array content.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::slot
   */
  public function testSlotWithRenderArray(): void {
    $render_array = ['#markup' => '<em>Rendered</em>'];
    $rendered_output = '<em>Rendered</em>';

    $renderer = $this->createMock(RendererInterface::class);
    $renderer->expects($this->once())
      ->method('render')
      ->with($render_array)
      ->willReturn($rendered_output);

    $extension = new CanvasTwigExtension(
      $this->createMock(StreamWrapperManagerInterface::class),
      $this->createMock(ImageFactory::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $renderer,
    );

    $result = $extension->slot('body', $render_array);

    $this->assertInstanceOf(MarkupInterface::class, $result);
    $this->assertSame(
      '<element-prop slot="body"><em>Rendered</em></element-prop>',
      (string) $result,
    );
  }

  /**
   * Tests slot() returns NULL when the renderer returns an empty string.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::slot
   */
  public function testSlotWithRenderArrayThatRendersEmpty(): void {
    $render_array = ['#markup' => ''];

    $renderer = $this->createMock(RendererInterface::class);
    $renderer->expects($this->once())
      ->method('render')
      ->with($render_array)
      ->willReturn('');

    $extension = new CanvasTwigExtension(
      $this->createMock(StreamWrapperManagerInterface::class),
      $this->createMock(ImageFactory::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $renderer,
    );

    $result = $extension->slot('body', $render_array);

    $this->assertNull($result);
  }

}
