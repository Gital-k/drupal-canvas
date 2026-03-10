<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Twig;

use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\canvas\Twig\CanvasPropVisitor;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for CanvasPropVisitor static helper methods.
 */
#[CoversMethod(CanvasPropVisitor::class, 'isInCustomElementAttribute')]
#[CoversMethod(CanvasPropVisitor::class, 'hasChildrenAttribute')]
#[Group('canvas')]
class CanvasPropVisitorTest extends UnitTestCase {

  /**
   * Calls the protected static isInCustomElementAttribute() via Reflection.
   */
  private static function isInCustomElementAttribute(string $buffer): bool {
    $method = new \ReflectionMethod(CanvasPropVisitor::class, 'isInCustomElementAttribute');
    return $method->invoke(NULL, $buffer);
  }

  /**
   * Calls the protected static hasChildrenAttribute() via Reflection.
   */
  private static function hasChildrenAttribute(string $buffer): bool {
    $method = new \ReflectionMethod(CanvasPropVisitor::class, 'hasChildrenAttribute');
    return $method->invoke(NULL, $buffer);
  }

  /**
   * Tests isInCustomElementAttribute().
   */
  #[DataProvider('providerIsInCustomElementAttribute')]
  public function testIsInCustomElementAttribute(string $buffer, bool $expected): void {
    $this->assertSame($expected, self::isInCustomElementAttribute($buffer));
  }

  /**
   * Data provider for testIsInCustomElementAttribute().
   */
  public static function providerIsInCustomElementAttribute(): \Generator {
    // Not inside any tag at all.
    yield 'empty buffer' => ['', FALSE];
    yield 'plain text' => ['Hello world', FALSE];

    // Inside a standard HTML element – not a Canvas custom element.
    yield 'inside a normal div attribute value' => ['<div class="', FALSE];
    yield 'inside a normal div, attribute value closed' => ['<div class="foo">', FALSE];

    // Inside a custom element but outside any attribute value (odd number of
    // quotes has not been opened yet).
    yield 'canvas- custom element, no attributes yet' => ['<canvas-button', FALSE];
    yield 'drupal-canvas- custom element, no attributes yet' => ['<drupal-canvas-input', FALSE];

    // Inside a custom element, between two completed attribute values (even
    // number of quotes = not inside a value).
    yield 'canvas- custom element, after a closed attribute' => [
      '<canvas-button type="primary"',
      FALSE,
    ];
    yield 'drupal-canvas- custom element, after a closed attribute' => [
      '<drupal-canvas-input type="text" ',
      FALSE,
    ];

    // Inside a custom element attribute VALUE (odd number of double-quotes).
    yield 'canvas- element, inside double-quoted attribute value' => [
      '<canvas-button type="',
      TRUE,
    ];
    yield 'drupal-canvas- element, inside double-quoted attribute value' => [
      '<drupal-canvas-input attributes="',
      TRUE,
    ];
    yield 'drupal-canvas- element, inside double-quoted value, one prior closed attr' => [
      '<drupal-canvas-input type="text" attributes="',
      TRUE,
    ];

    // Inside a custom element attribute VALUE (odd number of single-quotes).
    yield 'canvas- element, inside single-quoted attribute value' => [
      "<canvas-button type='",
      TRUE,
    ];
    yield 'drupal-canvas- element, inside single-quoted attribute value' => [
      "<drupal-canvas-input attributes='",
      TRUE,
    ];

    // Buffer contains a previously-closed tag followed by a new unclosed one.
    yield 'previous closed tag, then drupal-canvas- element inside value' => [
      '<div class="foo"><drupal-canvas-input attributes="',
      TRUE,
    ];
    yield 'previous closed tag, then drupal-canvas- element outside value' => [
      '<div class="foo"><drupal-canvas-input',
      FALSE,
    ];

    // Escaped quote inside the value – the backslash path.
    yield 'canvas- element, escaped quote inside value' => [
      '<canvas-button label="a \\"quoted\\" word" data="',
      TRUE,
    ];
    yield 'canvas- element, escaped quote, value closed' => [
      '<canvas-button label="a \\"quoted\\" word"',
      FALSE,
    ];
    // Buffer ends mid-value, after an escaped quote: we're still inside the
    // open attribute value (the escaped quote must not be mistaken for a
    // closing quote).
    yield 'canvas- element, buffer ends after escaped quote still inside value' => [
      '<canvas-button label="a \\"',
      TRUE,
    ];

    // Tag name that starts with canvas- but has mixed case.
    yield 'Canvas-Button (capital C), inside value' => [
      '<Canvas-Button type="',
      TRUE,
    ];

    // Not a canvas element (e.g. starts with "my-canvas").
    yield 'non-canvas custom element, inside value' => [
      '<my-canvas-button type="',
      FALSE,
    ];
  }

  /**
   * Tests hasChildrenAttribute().
   */
  #[DataProvider('providerHasChildrenAttribute')]
  public function testHasChildrenAttribute(string $buffer, bool $expected): void {
    $this->assertSame($expected, self::hasChildrenAttribute($buffer));
  }

  /**
   * Data provider for testHasChildrenAttribute().
   */
  public static function providerHasChildrenAttribute(): \Generator {
    // No tag at all.
    yield 'empty buffer' => ['', FALSE];
    yield 'plain text containing children word' => ['children', FALSE];

    // Has a 'children' attribute.
    yield 'tag with children double-quoted' => [
      '<drupal-canvas-input children="',
      TRUE,
    ];
    yield 'tag with children single-quoted' => [
      "<drupal-canvas-input children='",
      TRUE,
    ];
    yield 'tag with children and other attributes before it' => [
      '<drupal-canvas-input type="text" children="',
      TRUE,
    ];
    yield 'tag where children is part of another attribute name' => [
      '<drupal-canvas-input no-children="',
      FALSE,
    ];
    yield 'tag where children appears in a value, not as attr name' => [
      '<drupal-canvas-input data="children',
      FALSE,
    ];

    // 'children' appears in a previously-closed tag, not the current one.
    yield 'children in a prior closed tag, current tag has no children attr' => [
      '<div children="foo"><drupal-canvas-input type="',
      FALSE,
    ];

    // No 'children' keyword in the buffer at all (fast-path return).
    yield 'no children anywhere in buffer' => [
      '<drupal-canvas-input type="text" ',
      FALSE,
    ];
  }

}
