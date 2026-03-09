<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Twig\Error\SyntaxError;

/**
 * Kernel tests for the new CanvasTwigExtension and CanvasPropVisitor features.
 *
 * Covers:
 * - CanvasPropVisitor auto-injecting |jsx_attributes inside Canvas custom
 *   element attribute values.
 * - CanvasPropVisitor throwing SyntaxError when a 'children' attribute is used
 *   inside a Canvas custom element.
 * - CanvasTwigExtension::slot() Twig function end-to-end, including render
 *   arrays processed by the real renderer.
 *
 * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor
 * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::slot
 * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::jsxAttributes
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('Twig')]
final class CanvasPropVisitorAndSlotKernelTest extends CanvasKernelTestBase {

  /**
   * The renderer service.
   */
  private RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->renderer = $this->container->get(RendererInterface::class);
  }

  /**
   * Tests automatically wrapping with the |jsx_attributes filter.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor::enterNode
   * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor::isInCustomElementAttribute
   */
  #[DataProvider('providerJsxAttributesAutoInjection')]
  public function testJsxAttributesAutoInjection(
    string $template,
    array $context,
    string $expected_output,
  ): void {
    $element = [
      '#type' => 'inline_template',
      '#template' => $template,
      '#context' => $context,
    ];
    $output = (string) $this->renderer->renderInIsolation($element);
    $this->assertSame($expected_output, $output);
  }

  /**
   * Data provider for testJsxAttributesAutoInjection().
   */
  public static function providerJsxAttributesAutoInjection(): \Generator {
    // A plain string value is returned as-is by jsxAttributes().
    yield 'drupal-canvas- element: plain string attribute' => [
      '<drupal-canvas-input type="{{ type }}"></drupal-canvas-input>',
      ['type' => 'text'],
      '<drupal-canvas-input type="text"></drupal-canvas-input>',
    ];

    // An array is JSON-encoded by jsxAttributes().
    yield 'drupal-canvas- element: array attribute' => [
      '<drupal-canvas-input data="{{ data }}"></drupal-canvas-input>',
      ['data' => ['key' => 'value']],
      '<drupal-canvas-input data="{&quot;key&quot;:&quot;value&quot;}"></drupal-canvas-input>',
    ];

    // An Attribute object is converted via toArray() then JSON-encoded.
    yield 'drupal-canvas- element: Attribute object' => [
      '<drupal-canvas-input attributes="{{ attributes }}"></drupal-canvas-input>',
      ['attributes' => new Attribute(['class' => ['foo']])],
      '<drupal-canvas-input attributes="{&quot;class&quot;:[&quot;foo&quot;]}"></drupal-canvas-input>',
    ];

    // canvas-* prefix also triggers the filter.
    yield 'canvas- element: plain string attribute' => [
      '<canvas-button label="{{ label }}"></canvas-button>',
      ['label' => 'Click me'],
      '<canvas-button label="Click me"></canvas-button>',
    ];

    // A variable outside the custom element tag is NOT affected.
    yield 'variable outside canvas element is not json-encoded' => [
      '<canvas-button></canvas-button>{{ plain }}',
      ['plain' => 'hello'],
      '<canvas-button></canvas-button>hello',
    ];

    // A variable in a normal HTML element attribute is NOT affected.
    yield 'variable in normal element attribute is not affected' => [
      '<div class="{{ cls }}"></div>',
      ['cls' => 'my-class'],
      '<div class="my-class"></div>',
    ];
  }

  /**
   * Tests use of 'children' as an attribute name in a Canvas custom element.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor::enterNode
   * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor::hasChildrenAttribute
   */
  #[DataProvider('providerChildrenAttributeThrowsSyntaxError')]
  public function testChildrenAttributeThrowsSyntaxError(string $template): void {
    $this->expectException(SyntaxError::class);
    $this->expectExceptionMessage('"children" is reserved for React components');

    // Rendering triggers Twig compilation, which is where the SyntaxError is
    // thrown by CanvasPropVisitor::enterNode().
    $element = [
      '#type' => 'inline_template',
      '#template' => $template,
      '#context' => ['value' => 'foo'],
    ];
    $this->renderer->renderInIsolation($element);
  }

  /**
   * Data provider for testChildrenAttributeThrowsSyntaxError().
   */
  public static function providerChildrenAttributeThrowsSyntaxError(): \Generator {
    yield 'drupal-canvas- element with children attribute (double-quoted)' => [
      '<drupal-canvas-input children="{{ value }}"></drupal-canvas-input>',
    ];
    yield 'drupal-canvas- element with children attribute (single-quoted)' => [
      "<drupal-canvas-input children='{{ value }}'></drupal-canvas-input>",
    ];
    yield 'canvas- element with children attribute' => [
      '<canvas-button children="{{ value }}"></canvas-button>',
    ];
    yield 'drupal-canvas- element: children attr with other attrs before it' => [
      '<drupal-canvas-input type="text" children="{{ value }}"></drupal-canvas-input>',
    ];
  }

  /**
   * Tests that 'children' as an attribute name on a normal HTML element.
   *
   * This should not throw an error, since the protection only applies to Canvas custom
   * elements.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor::enterNode
   */
  public function testChildrenAttributeOnNormalElementDoesNotThrow(): void {
    // This should compile and render without errors.
    $element = [
      '#type' => 'inline_template',
      '#template' => '<div children="{{ value }}"></div>',
      '#context' => ['value' => 'foo'],
    ];
    // No exception should be thrown.
    $output = (string) $this->renderer->renderInIsolation($element);
    $this->assertStringContainsString('foo', $output);
  }

  /**
   * Tests the slot() Twig function end-to-end, including render arrays.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::slot
   */
  #[DataProvider('providerSlot')]
  public function testSlot(string $template, array $context, string $expected_output): void {
    $element = [
      '#type' => 'inline_template',
      '#template' => $template,
      '#context' => $context,
    ];
    $output = (string) $this->renderer->renderInIsolation($element);
    $this->assertSame($expected_output, $output);
  }

  /**
   * Data provider for testSlot().
   */
  public static function providerSlot(): \Generator {
    // Plain string content.
    yield 'string content' => [
      "{{ slot('my-slot', content) }}",
      ['content' => 'Hello world'],
      '<element-prop slot="my-slot">Hello world</element-prop>',
    ];

    // Render array content – the real Drupal renderer is invoked.
    yield 'render array content' => [
      "{{ slot('body', content) }}",
      ['content' => ['#markup' => '<strong>Bold</strong>']],
      '<element-prop slot="body"><strong>Bold</strong></element-prop>',
    ];

    // NULL content returns empty string (slot() returns NULL, Twig prints '').
    yield 'null content returns empty' => [
      "{{ slot('my-slot', content) }}",
      ['content' => NULL],
      '',
    ];

    // Empty string content returns empty string.
    yield 'empty string content returns empty' => [
      "{{ slot('my-slot', content) }}",
      ['content' => ''],
      '',
    ];

    // Empty array content returns empty string.
    yield 'empty array content returns empty' => [
      "{{ slot('my-slot', content) }}",
      ['content' => []],
      '',
    ];

    // Slot name with HTML-special characters is escaped.
    yield 'slot name with special chars is escaped' => [
      "{{ slot('foo\"bar', content) }}",
      ['content' => 'test'],
      '<element-prop slot="foo&quot;bar">test</element-prop>',
    ];
  }

}
