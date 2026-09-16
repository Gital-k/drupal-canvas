<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ShapeMatcher;

use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropSource\EntityFieldPropSource;
use Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests object prop shape matching against field types declared by contrib.
 *
 * Covers two things scalar-driven matching cannot do:
 * 1. Survive a nested `type: object` (or `type: array`) member — it used to
 *    throw a LogicException, taking down the whole Content Template UI.
 * 2. Find a field instance whose field type was declared to fit the shape by
 *    hook_canvas_storable_prop_shape_alter(), rather than by validation
 *    constraints on its properties.
 *
 * @see \Drupal\canvas_test_storable_prop_shape_alter\Plugin\Field\FieldType\StructuredPairItem
 */
#[CoversClass(EntityFieldPropSourceMatcher::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
class EntityFieldPropSourceMatcherObjectShapeTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'node',
    'field',
    'canvas_test_storable_prop_shape_alter',
  ];

  private EntityFieldPropSourceMatcher $matcher;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition(EntityFieldPropSourceMatcher::class)->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    NodeType::create(['name' => 'Foo', 'type' => 'foo'])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_pair',
      'type' => 'structured_pair',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'foo',
      'field_name' => 'field_pair',
      'label' => 'Pair',
    ])->save();
    $this->matcher = $this->container->get(EntityFieldPropSourceMatcher::class);
  }

  /**
   * A field type declared storable for an object shape is matched.
   *
   * A nested `type: object` member is simply left unmapped when the field type
   * does not offer it, and mapped when it does — but never fatal.
   *
   * @param array<string, mixed> $properties
   *   The object prop shape's `properties`.
   * @param string $expected
   *   The expected expression, as a string.
   */
  #[DataProvider('providerObjectShapes')]
  public function testObjectShapeMatchesDeclaredFieldType(array $properties, string $expected): void {
    $shape = PropShape::normalize([
      'type' => 'object',
      'properties' => $properties,
      'required' => ['first', 'second'],
    ]);

    $matches = $this->matcher->match(FALSE, $shape, 'node', 'foo');

    $this->assertSame(
      [$expected],
      \array_map(fn (EntityFieldPropSource $m): string => (string) $m->expression, $matches),
    );
    foreach ($matches as $match) {
      $this->assertInstanceOf(FieldObjectPropsExpression::class, $match->expression);
    }
  }

  /**
   * Data provider for ::testObjectShapeMatchesDeclaredFieldType().
   */
  public static function providerObjectShapes(): array {
    return [
      'all members scalar' => [
        [
          'first' => ['type' => 'string'],
          'second' => ['type' => 'string'],
        ],
        'ℹ︎␜entity:node:foo␝field_pair␞␟{first↠first,second↠second}',
      ],
      'nested object member, mapped by the field type' => [
        [
          'first' => ['type' => 'string'],
          'second' => ['type' => 'string'],
          'extra' => ['type' => 'object'],
        ],
        'ℹ︎␜entity:node:foo␝field_pair␞␟{first↠first,second↠second,extra↠extra}',
      ],
      'nested array member, not mapped by the field type' => [
        [
          'first' => ['type' => 'string'],
          'second' => ['type' => 'string'],
          'not_matchable' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'ℹ︎␜entity:node:foo␝field_pair␞␟{first↠first,second↠second}',
      ],
    ];
  }

  /**
   * A non-scalar or type-less object member never causes a fatal error.
   *
   * Canvas' object prop expressions map each object key to a scalar or
   * reference expression, so these members can never be expressed. Before,
   * they threw a LogicException from ::matchEntityPropsForScalar().
   *
   * @param array<string, mixed> $unmatchable_member
   *   Schema for an object member that cannot be matched.
   */
  #[DataProvider('providerUnmatchableMembers')]
  public function testUnmatchableObjectMemberDoesNotThrow(array $unmatchable_member): void {
    $shape = PropShape::normalize([
      'type' => 'object',
      'properties' => [
        'title' => ['type' => 'string'],
        'nested' => $unmatchable_member,
      ],
      'required' => ['title'],
    ]);

    // No exception. The node title populates the shape's one scalar member;
    // `nested` is absent from the expression rather than fatal.
    $expressions = \array_map(
      fn (EntityFieldPropSource $m): string => (string) $m->expression,
      $this->matcher->match(FALSE, $shape, 'node', 'foo'),
    );
    $this->assertNotEmpty($expressions);
    foreach ($expressions as $expression) {
      $this->assertStringNotContainsString('nested', $expression);
    }
    $this->assertContains('ℹ︎␜entity:node:foo␝title␞␟{title↠value}', $expressions);
  }

  /**
   * Data provider for ::testUnmatchableObjectMemberDoesNotThrow().
   */
  public static function providerUnmatchableMembers(): array {
    return [
      'nested object' => [['type' => 'object']],
      'nested array' => [['type' => 'array', 'items' => ['type' => 'string']]],
      'nullable object' => [['type' => ['object', 'null']]],
      // NOTE: a member with no `type` at all cannot be tested here — the
      // matcher never sees one, because PropShape::normalize() rejects it
      // first. ::matchEntityPropsForObjectUsingScalars() still guards against
      // it, since ::iterateObjectJsonSchema() is public API.
    ];
  }

}
