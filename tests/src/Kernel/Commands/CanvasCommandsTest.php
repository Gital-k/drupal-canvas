<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\ComponentIncompatibilityReasonRepository;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Drush\Commands\CanvasCommands;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Canvas Drush commands.
 *
 * @legacy-covers \Drupal\canvas\Drush\Commands\CanvasCommands
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class CanvasCommandsTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  /**
   * The commands object under test.
   */
  private CanvasCommands $commands;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The ComponentAudit service queries content entity tables, so the Page
    // entity schema must be installed.
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->generateComponentConfig();
    $this->commands = new CanvasCommands(
      $this->container->get(EntityTypeManagerInterface::class),
      $this->container->get(ComponentAudit::class),
      $this->container->get(ComponentIncompatibilityReasonRepository::class),
      $this->container->get(ComponentSourceManager::class),
    );
  }

  /**
   * Tests the canvas:component:list command.
   */
  public function testComponentList(): void {
    $result = $this->commands->componentList();
    self::assertInstanceOf(RowsOfFields::class, $result);
    $rows = $result->getArrayCopy();
    self::assertNotEmpty($rows, 'Component list should not be empty.');

    // Every row must contain the expected keys.
    $expected_keys = ['id', 'label', 'source', 'status', 'versions', 'usage'];
    foreach ($rows as $row) {
      foreach ($expected_keys as $key) {
        self::assertArrayHasKey($key, $row);
      }
    }

    // Find the Druplicon SDC component (provided by the test module).
    $druplicon_rows = \array_filter($rows, static fn(array $row): bool => $row['id'] === 'sdc.canvas_test_sdc.druplicon');
    self::assertCount(1, $druplicon_rows, 'Druplicon component should appear exactly once.');
    $druplicon = \reset($druplicon_rows);
    self::assertSame('Druplicon', $druplicon['label']);
    self::assertSame('Enabled', $druplicon['status']);
    self::assertIsInt($druplicon['versions']);
    self::assertGreaterThanOrEqual(1, $druplicon['versions']);
    self::assertIsInt($druplicon['usage']);
  }

  /**
   * Tests the canvas:component:status command reports real incompatibilities.
   */
  public function testComponentStatus(): void {
    $result = $this->commands->componentStatus();
    self::assertInstanceOf(RowsOfFields::class, $result);
    $rows = $result->getArrayCopy();

    // The test SDC module has some components that genuinely do not meet
    // requirements (e.g. missing example values, unsupported prop shapes).
    // Verify the output structure for each row.
    foreach ($rows as $row) {
      self::assertArrayHasKey('id', $row);
      self::assertArrayHasKey('reasons', $row);
      self::assertNotEmpty($row['reasons'], 'Each status row should have at least one reason.');
    }
  }

  /**
   * Tests the canvas:component:status command with manually stored reasons.
   */
  public function testComponentStatusWithStoredReasons(): void {
    $reason_repository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
    \assert($reason_repository instanceof ComponentIncompatibilityReasonRepository);

    // Store incompatibility reasons for a non-existent component (simulating
    // a component that was discovered but does not have a Component config
    // entity — these should show up in the status output).
    $reason_repository->storeReasons('sdc', 'sdc.fictional.broken_component', ['Missing template file']);

    $result = $this->commands->componentStatus();
    self::assertInstanceOf(RowsOfFields::class, $result);
    $rows = $result->getArrayCopy();
    self::assertNotEmpty($rows, 'Should have at least one incompatibility reason.');

    $broken_rows = \array_filter($rows, static fn(array $row): bool => $row['id'] === 'sdc.fictional.broken_component');
    self::assertCount(1, $broken_rows);
    $broken = \reset($broken_rows);
    self::assertStringContainsString('Missing template file', $broken['reasons']);
  }

  /**
   * Tests the canvas:component:status command filters out disabled components.
   */
  public function testComponentStatusFiltersDisabled(): void {
    // Disable a real component and add incompatibility reasons for it.
    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    $component->disable()->save();

    $reason_repository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
    \assert($reason_repository instanceof ComponentIncompatibilityReasonRepository);
    $reason_repository->storeReasons('sdc', 'sdc.canvas_test_sdc.druplicon', ['Disabled by test']);

    $result = $this->commands->componentStatus();
    $rows = $result->getArrayCopy();

    // Disabled components with reasons should be filtered out.
    $druplicon_rows = \array_filter($rows, static fn(array $row): bool => $row['id'] === 'sdc.canvas_test_sdc.druplicon');
    self::assertEmpty($druplicon_rows, 'Disabled components should not appear in status output.');
  }

  /**
   * Tests the canvas:component:generate command.
   */
  public function testComponentGenerate(): void {
    // Delete a component to verify generate recreates it.
    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    $component->delete();
    self::assertNull(Component::load('sdc.canvas_test_sdc.druplicon'), 'Component should be deleted.');

    // Run generate — this should not throw and should recreate the component.
    $this->commands->componentGenerate();

    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component, 'Component should be recreated by generate command.');
    self::assertTrue($component->status(), 'Regenerated component should be enabled.');
  }

  /**
   * Tests the canvas:component:generate command with source filter.
   */
  public function testComponentGenerateWithSourceFilter(): void {
    // Delete a component.
    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    $component->delete();

    // Generate with a non-matching source — the component should NOT be
    // recreated.
    $this->commands->componentGenerate(['source' => 'nonexistent_source']);
    self::assertNull(Component::load('sdc.canvas_test_sdc.druplicon'), 'Component should not be recreated with wrong source filter.');

    // Generate with the correct source — the component should be recreated.
    $this->commands->componentGenerate(['source' => 'sdc']);
    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component, 'Component should be recreated with correct source filter.');
  }

}
