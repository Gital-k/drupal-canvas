<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Validation;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers Canvas' shims for core's link constraint validators.
 *
 * Core's LinkType, LinkAccess, LinkExternalProtocols, and
 * LinkNotExistingInternal validators call getUrl() on empty LinkItems,
 * which routes a NULL uri into Url::fromUri() and emits preg_match() /
 * parse_url() / str_starts_with() deprecations on PHP 8.1+.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasSkipEmptyLinkItemTrait
 * @todo Remove once the upstream core fix lands.
 */
#[Group('canvas')]
final class CanvasLinkItemValidatorShimTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'field',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'type' => 'link',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'bundle' => 'page',
      'settings' => [
        'title' => 0,
        // LinkItemInterface::LINK_GENERIC.
        'link_type' => 3,
      ],
    ])->save();
  }

  public function testEmptyLinkItemSkipsAllCoreLinkValidators(): void {
    $node = Node::create(['type' => 'page', 'title' => 'x']);
    // A NULL-uri item at delta 0 is the shape
    // ClientDataToEntityConverter::setEntityFields() plants when it reverts
    // an invalid new multi-value delta. A trailing non-empty item is
    // required so FieldItemList does not filter the empty delta out before
    // per-item constraint validation runs.
    $node->set('field_link', [
      ['uri' => NULL, 'title' => NULL, 'options' => []],
      ['uri' => 'route:<nolink>', 'title' => NULL, 'options' => []],
    ]);
    $field = $node->get('field_link');
    \assert($field instanceof FieldItemListInterface);
    $first = $field->get(0);
    \assert($first instanceof FieldItemInterface);
    self::assertTrue($first->isEmpty());
    self::assertNull($first->uri);

    $deprecations = [];
    \set_error_handler(
      static function (int $errno, string $message) use (&$deprecations): bool {
        if ($errno === \E_DEPRECATED || $errno === \E_USER_DEPRECATED) {
          $deprecations[] = $message;
          return TRUE;
        }
        return FALSE;
      },
      \E_DEPRECATED | \E_USER_DEPRECATED,
    );
    try {
      $violations = $node->validate();
    }
    finally {
      \restore_error_handler();
    }

    // Without the shim, each of the four validators routes NULL into
    // Url::fromUri() and emits these three deprecations.
    $link_deprecations = \array_values(\array_filter(
      $deprecations,
      static fn (string $message): bool =>
        \str_contains($message, 'preg_match()')
        || \str_contains($message, 'parse_url()')
        || \str_contains($message, 'str_starts_with()'),
    ));
    self::assertSame([], $link_deprecations, \implode(' | ', $link_deprecations));

    foreach ($violations as $violation) {
      self::assertStringStartsNotWith('field_link', (string) $violation->getPropertyPath());
    }
  }

}
