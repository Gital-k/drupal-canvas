<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\JavaScriptComponent;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests escaping the `meta:enum` keys stored by existing code components.
 */
#[CoversFunction('canvas_post_update_0031_escape_code_component_meta_enum_keys')]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class CodeComponentMetaEnumKeyEscapingUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
  }

  /**
   * Tests that a pre-existing `meta:enum` key keeps labeling the same value.
   *
   * A code component predating the escaping stored each `enum` value verbatim
   * as the `meta:enum` key labeling it. None of those keys contains a dot —
   * that was rejected outright — but a `%` in one would now be read as the
   * start of an escape sequence, so the label would stop being found.
   *
   * @see \Drupal\canvas\Component\Schema\MetaEnumConfigKeyEscaper
   */
  public function testPreExistingMetaEnumKeysAreEscaped(): void {
    $legacy_meta_enum = ['%2E' => 'Not a dot', 'half' => 'Half'];
    JavaScriptComponent::create([
      'machineName' => 'legacy_meta_enum',
      'name' => 'Legacy meta:enum',
      'status' => TRUE,
      'props' => [
        'discount' => [
          'type' => 'string',
          'title' => 'Discount',
          'enum' => \array_keys($legacy_meta_enum),
          'meta:enum' => $legacy_meta_enum,
          'examples' => ['half'],
        ],
      ],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ])->save();
    self::assertSame($legacy_meta_enum, self::loadStoredMetaEnum());

    $this->runUpdates();

    // The stored keys are canonical escaped forms now …
    self::assertSame(['%252E' => 'Not a dot', 'half' => 'Half'], self::loadStoredMetaEnum());

    // … so each one still labels the `enum` value it was written for.
    $component = JavaScriptComponent::load('legacy_meta_enum');
    self::assertInstanceOf(JavaScriptComponent::class, $component);
    self::assertSame($legacy_meta_enum, $component->toSdcDefinition()['props']['properties']['discount']['meta:enum']);
  }

  /**
   * Reads the `meta:enum` of the test component's prop straight from config.
   *
   * @return array<string, string>
   *   The stored labels, keyed by the stored (escaped) key.
   */
  private static function loadStoredMetaEnum(): array {
    \Drupal::entityTypeManager()->getStorage(JavaScriptComponent::ENTITY_TYPE_ID)->resetCache();
    $component = JavaScriptComponent::load('legacy_meta_enum');
    \assert($component instanceof JavaScriptComponent);
    return $component->toArray()['props']['discount']['meta:enum'];
  }

}
