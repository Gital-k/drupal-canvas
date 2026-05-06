<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentMetadataRequirementsChecker;
use Drupal\Core\Theme\Component\ComponentMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @covers \Drupal\canvas\ComponentMetadataRequirementsChecker::check
 */
#[CoversClass(ComponentMetadataRequirementsChecker::class)]
#[Group('canvas')]
final class ComponentMetadataRequirementsCheckerPropGroupsTest extends CanvasKernelTestBase {

  /**
   * Builds a minimal valid ComponentMetadata for the given props.
   *
   * @param array<string, array> $properties
   *   The component prop schema property entries.
   * @param string[] $required
   *   Required prop names.
   */
  private static function buildMetadata(array $properties, array $required = []): ComponentMetadata {
    $info = [
      'id' => 'canvas_test_prop_groups:test_grouping',
      'machineName' => 'test_grouping',
      'path' => '',
      'provider' => 'canvas_test_prop_groups',
      'name' => 'Test grouping',
      'group' => 'Test',
      'props' => [
        'type' => 'object',
        'required' => $required,
        'properties' => $properties,
      ],
    ];
    return new ComponentMetadata($info, app_root: '', enforce_schemas: TRUE);
  }

  /**
   * The minimal valid prop set used across positive/negative cases.
   */
  private static function validProperties(): array {
    return [
      'text' => [
        'type' => 'string',
        'title' => 'Text',
        'x-prop-group' => 'content',
        'examples' => ['Click me'],
      ],
      'cssClass' => [
        'type' => 'string',
        'title' => 'CSS class',
        'x-prop-group' => 'advanced',
        'examples' => ['my-cta'],
      ],
      'legacyTooltip' => [
        'type' => 'string',
        'title' => 'Tooltip',
        'examples' => ['Hi there'],
      ],
    ];
  }

  /**
   * Valid declarations + valid `x-prop-group` references must pass.
   */
  public function testValidPropGroupsAndReferencesPass(): void {
    $metadata = self::buildMetadata(self::validProperties());
    $prop_groups = [
      ['id' => 'content', 'title' => 'Content', 'weight' => 0, 'open' => TRUE],
      ['id' => 'styling', 'title' => 'Styling', 'weight' => 10, 'open' => TRUE],
      ['id' => 'advanced', 'title' => 'Advanced', 'weight' => 20, 'open' => FALSE],
    ];

    // No exception expected.
    ComponentMetadataRequirementsChecker::check(
      'canvas_test_prop_groups:test_grouping',
      $metadata,
      [],
      $prop_groups,
    );
    $this->assertTrue(TRUE);
  }

  /**
   * An undeclared `x-prop-group` reference must throw with the expected message.
   */
  public function testUndeclaredPropGroupReferenceThrows(): void {
    $metadata = self::buildMetadata([
      'text' => [
        'type' => 'string',
        'title' => 'Text',
        'x-prop-group' => 'nonexistent',
        'examples' => ['Click'],
      ],
    ]);
    $prop_groups = [
      ['id' => 'content', 'title' => 'Content'],
    ];
    try {
      ComponentMetadataRequirementsChecker::check(
        'canvas_test_prop_groups:test_grouping',
        $metadata,
        [],
        $prop_groups,
      );
      $this->fail('Expected ComponentDoesNotMeetRequirementsException not thrown.');
    }
    catch (ComponentDoesNotMeetRequirementsException $e) {
      $this->assertContains(
        'Prop "text" references undeclared prop group "nonexistent".',
        $e->getMessages(),
      );
    }
  }

  /**
   * Duplicate group ids must throw.
   */
  public function testDuplicateGroupIdsThrow(): void {
    $metadata = self::buildMetadata([
      'text' => [
        'type' => 'string',
        'title' => 'Text',
        'x-prop-group' => 'content',
        'examples' => ['Click'],
      ],
    ]);
    $prop_groups = [
      ['id' => 'content', 'title' => 'Content A'],
      ['id' => 'content', 'title' => 'Content B'],
    ];
    try {
      ComponentMetadataRequirementsChecker::check(
        'canvas_test_prop_groups:test_grouping',
        $metadata,
        [],
        $prop_groups,
      );
      $this->fail('Expected ComponentDoesNotMeetRequirementsException not thrown.');
    }
    catch (ComponentDoesNotMeetRequirementsException $e) {
      $this->assertContains('Duplicate prop group id "content".', $e->getMessages());
    }
  }

  /**
   * Non-numeric weight must throw.
   */
  public function testNonNumericWeightThrows(): void {
    $metadata = self::buildMetadata([
      'text' => [
        'type' => 'string',
        'title' => 'Text',
        'x-prop-group' => 'content',
        'examples' => ['Click'],
      ],
    ]);
    $prop_groups = [
      ['id' => 'content', 'title' => 'Content', 'weight' => 'heavy'],
    ];
    try {
      ComponentMetadataRequirementsChecker::check(
        'canvas_test_prop_groups:test_grouping',
        $metadata,
        [],
        $prop_groups,
      );
      $this->fail('Expected ComponentDoesNotMeetRequirementsException not thrown.');
    }
    catch (ComponentDoesNotMeetRequirementsException $e) {
      $this->assertContains('Prop group "content" "weight" must be numeric.', $e->getMessages());
    }
  }

  /**
   * Empty title must throw.
   */
  public function testEmptyTitleThrows(): void {
    $metadata = self::buildMetadata([
      'text' => [
        'type' => 'string',
        'title' => 'Text',
        'x-prop-group' => 'content',
        'examples' => ['Click'],
      ],
    ]);
    $prop_groups = [
      ['id' => 'content', 'title' => ''],
    ];
    try {
      ComponentMetadataRequirementsChecker::check(
        'canvas_test_prop_groups:test_grouping',
        $metadata,
        [],
        $prop_groups,
      );
      $this->fail('Expected ComponentDoesNotMeetRequirementsException not thrown.');
    }
    catch (ComponentDoesNotMeetRequirementsException $e) {
      $this->assertContains('Prop group "content" must have a non-empty string "title".', $e->getMessages());
    }
  }

  /**
   * Missing title must throw.
   */
  public function testMissingTitleThrows(): void {
    $metadata = self::buildMetadata([
      'text' => [
        'type' => 'string',
        'title' => 'Text',
        'x-prop-group' => 'content',
        'examples' => ['Click'],
      ],
    ]);
    $prop_groups = [
      ['id' => 'content'],
    ];
    try {
      ComponentMetadataRequirementsChecker::check(
        'canvas_test_prop_groups:test_grouping',
        $metadata,
        [],
        $prop_groups,
      );
      $this->fail('Expected ComponentDoesNotMeetRequirementsException not thrown.');
    }
    catch (ComponentDoesNotMeetRequirementsException $e) {
      $this->assertContains('Prop group "content" must have a non-empty string "title".', $e->getMessages());
    }
  }

  /**
   * Non-boolean `open` must throw.
   */
  public function testNonBooleanOpenThrows(): void {
    $metadata = self::buildMetadata([
      'text' => [
        'type' => 'string',
        'title' => 'Text',
        'x-prop-group' => 'content',
        'examples' => ['Click'],
      ],
    ]);
    $prop_groups = [
      ['id' => 'content', 'title' => 'Content', 'open' => 'yes'],
    ];
    try {
      ComponentMetadataRequirementsChecker::check(
        'canvas_test_prop_groups:test_grouping',
        $metadata,
        [],
        $prop_groups,
      );
      $this->fail('Expected ComponentDoesNotMeetRequirementsException not thrown.');
    }
    catch (ComponentDoesNotMeetRequirementsException $e) {
      $this->assertContains('Prop group "content" "open" must be a boolean.', $e->getMessages());
    }
  }

  /**
   * Non-empty propGroups but no `x-prop-group` references must still pass.
   */
  public function testValidPropGroupsWithNoReferencesPass(): void {
    $metadata = self::buildMetadata([
      'legacyTooltip' => [
        'type' => 'string',
        'title' => 'Tooltip',
        'examples' => ['Hi there'],
      ],
    ]);
    $prop_groups = [
      ['id' => 'content', 'title' => 'Content'],
    ];
    ComponentMetadataRequirementsChecker::check(
      'canvas_test_prop_groups:test_grouping',
      $metadata,
      [],
      $prop_groups,
    );
    $this->assertTrue(TRUE);
  }

  /**
   * Empty group id must throw.
   */
  public function testEmptyGroupIdThrows(): void {
    $metadata = self::buildMetadata([
      'text' => [
        'type' => 'string',
        'title' => 'Text',
        'examples' => ['Click'],
      ],
    ]);
    $prop_groups = [
      ['id' => '', 'title' => 'Content'],
    ];
    try {
      ComponentMetadataRequirementsChecker::check(
        'canvas_test_prop_groups:test_grouping',
        $metadata,
        [],
        $prop_groups,
      );
      $this->fail('Expected ComponentDoesNotMeetRequirementsException not thrown.');
    }
    catch (ComponentDoesNotMeetRequirementsException $e) {
      $this->assertContains('Prop group at index 0 must have a non-empty string "id".', $e->getMessages());
    }
  }

}
