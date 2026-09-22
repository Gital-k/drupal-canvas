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
use Drush\Commands\DrushCommands;
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
    $druplicon_rows = \array_filter($rows, static fn(array|\ArrayAccess $row): bool => $row['id'] === 'sdc.canvas_test_sdc.druplicon');
    self::assertCount(1, $druplicon_rows, 'Druplicon component should appear exactly once.');
    $druplicon = \reset($druplicon_rows);
    self::assertSame('Druplicon', $druplicon['label']);
    self::assertSame('Enabled', $druplicon['status']);
    self::assertIsInt($druplicon['versions']);
    self::assertGreaterThanOrEqual(1, $druplicon['versions']);
    self::assertIsInt($druplicon['usage']);
  }

  /**
   * Tests status reports every component with a state and a stable structure.
   */
  public function testComponentStatusReportsState(): void {
    [$rows] = $this->statusResult();
    self::assertNotEmpty($rows);

    foreach ($rows as $row) {
      self::assertArrayHasKey('id', $row);
      self::assertArrayHasKey('state', $row);
      self::assertArrayHasKey('reasons', $row);
      self::assertContains($row['state'], ['compatible', 'incompatible', 'disabled']);
    }

    // An enabled, compatible component is reported as compatible with no reason.
    $druplicon = self::rowFor($rows, 'sdc.canvas_test_sdc.druplicon');
    self::assertSame('compatible', $druplicon['state']);
    self::assertSame('', $druplicon['reasons']);
  }

  /**
   * Tests an incompatible component is flagged and forces a non-zero exit.
   */
  public function testComponentStatusReportsIncompatible(): void {
    // A discovered component without a config entity, simulating one that
    // failed requirements during generation.
    $this->reasonRepository()->storeReasons('sdc', 'sdc.fictional.broken_component', ['Missing template file']);

    [$rows, $exit_code] = $this->statusResult();

    $broken = self::rowFor($rows, 'sdc.fictional.broken_component');
    self::assertSame('incompatible', $broken['state']);
    self::assertStringContainsString('Missing template file', $broken['reasons']);
    self::assertSame(DrushCommands::EXIT_FAILURE, $exit_code, 'Incompatibilities must force a non-zero exit.');
  }

  /**
   * Tests disabled components are reported, not silently skipped.
   */
  public function testComponentStatusReportsDisabled(): void {
    // A site owner manually disables an otherwise compatible component.
    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    $component->disable()->save();
    $this->reasonRepository()->storeReasons('sdc', 'sdc.canvas_test_sdc.druplicon', [ComponentIncompatibilityReasonRepository::MANUALLY_DISABLED_REASON]);

    [$rows] = $this->statusResult();
    $druplicon = self::rowFor($rows, 'sdc.canvas_test_sdc.druplicon');
    self::assertSame('disabled', $druplicon['state'], 'Disabled components must appear with the disabled state.');

    // A disabled component is a site-owner decision, not an incompatibility, so
    // a lookup scoped to it exits zero.
    [, $exit_code] = $this->statusResult('sdc.canvas_test_sdc.druplicon');
    self::assertSame(DrushCommands::EXIT_SUCCESS, $exit_code, 'A disabled component is not an incompatibility.');
  }

  /**
   * Tests status reports a single component when given an ID.
   */
  public function testComponentStatusSingleComponent(): void {
    [$rows, $exit_code] = $this->statusResult('sdc.canvas_test_sdc.druplicon');

    self::assertCount(1, $rows);
    self::assertSame('sdc.canvas_test_sdc.druplicon', $rows[0]['id']);
    self::assertSame('compatible', $rows[0]['state']);
    self::assertSame(DrushCommands::EXIT_SUCCESS, $exit_code);
  }

  /**
   * Tests a single incompatible component lookup exits non-zero.
   */
  public function testComponentStatusSingleIncompatible(): void {
    $this->reasonRepository()->storeReasons('sdc', 'sdc.fictional.broken_component', ['Missing template file']);

    [$rows, $exit_code] = $this->statusResult('sdc.fictional.broken_component');

    self::assertCount(1, $rows);
    self::assertSame('incompatible', $rows[0]['state']);
    self::assertSame(DrushCommands::EXIT_FAILURE, $exit_code);
  }

  /**
   * Tests an unknown component ID is rejected.
   */
  public function testComponentStatusUnknownComponent(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown component: sdc.does_not.exist');
    $this->commands->componentStatus('sdc.does_not.exist');
  }

  /**
   * Tests the canvas:component:generate command.
   */
  public function testComponentGenerate(): void {
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Component::ENTITY_TYPE_ID);

    // Delete a component to verify generate recreates it.
    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    $component->delete();
    $deleted = $storage->loadUnchanged('sdc.canvas_test_sdc.druplicon');
    self::assertNull($deleted, 'Component should be deleted.');

    // Run generate — this should not throw and should recreate the component.
    $this->commands->componentGenerate();

    $component = $storage->loadUnchanged('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component, 'Component should be recreated by generate command.');
    self::assertTrue($component->status(), 'Regenerated component should be enabled.');
  }

  /**
   * Tests the canvas:component:generate command with source filter.
   */
  public function testComponentGenerateWithSourceFilter(): void {
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Component::ENTITY_TYPE_ID);

    // Delete a component.
    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    $component->delete();

    // Generate with a non-matching source — the component should NOT be
    // recreated.
    $this->commands->componentGenerate(['source' => 'nonexistent_source']);
    $not_recreated = $storage->loadUnchanged('sdc.canvas_test_sdc.druplicon');
    self::assertNull($not_recreated, 'Component should not be recreated with wrong source filter.');

    // Generate with the correct source — the component should be recreated.
    $this->commands->componentGenerate(['source' => 'sdc']);
    $component = $storage->loadUnchanged('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component, 'Component should be recreated with correct source filter.');
  }

  /**
   * Tests generate clears a stale auto-disable reason when eligible again.
   *
   * The component stays disabled — re-enabling is the site owner's choice.
   */
  public function testComponentGenerateClearsAutoDisabledReason(): void {
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Component::ENTITY_TYPE_ID);
    $reason_repository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
    \assert($reason_repository instanceof ComponentIncompatibilityReasonRepository);

    // Simulate a prior auto-disable: store a requirement-failure reason and
    // disable the entity, as the normal flow does when checkRequirements()
    // throws.
    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    $reason_repository->storeReasons('sdc', 'sdc.canvas_test_sdc.druplicon', ['Prop "logo_url" has contentMediaType which is not supported.']);
    $component->disable()->save();

    // The component meets requirements, so generate must clear its reason.
    $this->commands->componentGenerate();

    self::assertArrayNotHasKey('sdc.canvas_test_sdc.druplicon', $reason_repository->getReasons()['sdc'] ?? []);
    $component = $storage->loadUnchanged('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    self::assertFalse($component->status(), 'Component should stay disabled after its reason is cleared.');
  }

  /**
   * Tests generate preserves a site owner's manual disable.
   */
  public function testComponentGeneratePreservesManualDisable(): void {
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Component::ENTITY_TYPE_ID);
    $reason_repository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
    \assert($reason_repository instanceof ComponentIncompatibilityReasonRepository);

    // Simulate a site owner manually disabling an eligible component.
    $component = Component::load('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    $component->disable()->save();
    $reason_repository->storeReasons('sdc', 'sdc.canvas_test_sdc.druplicon', [ComponentIncompatibilityReasonRepository::MANUALLY_DISABLED_REASON]);

    // Even though the component is eligible, generate must respect the manual
    // disable and leave both the reason and the disabled status intact.
    $this->commands->componentGenerate();

    self::assertSame(
      [ComponentIncompatibilityReasonRepository::MANUALLY_DISABLED_REASON],
      $reason_repository->getReasons()['sdc']['sdc.canvas_test_sdc.druplicon'] ?? []
    );
    $component = $storage->loadUnchanged('sdc.canvas_test_sdc.druplicon');
    self::assertInstanceOf(Component::class, $component);
    self::assertFalse($component->status(), 'Manually disabled component should stay disabled.');
  }

  /**
   * Runs the status command and returns its rows and exit code.
   *
   * @return array{list<array<string, mixed>>, int}
   *   The status rows and the command exit code.
   */
  private function statusResult(?string $component_id = NULL): array {
    $result = $component_id === NULL
      ? $this->commands->componentStatus()
      : $this->commands->componentStatus($component_id);
    $data = $result->getOutputData();
    \assert($data instanceof RowsOfFields);
    return [\array_values($data->getArrayCopy()), $result->getExitCode()];
  }

  /**
   * Returns the single status row for a component ID.
   *
   * @param list<array<string, mixed>> $rows
   *   The status rows.
   * @param string $component_id
   *   The component ID to find.
   *
   * @return array<string, mixed>
   *   The matching row.
   */
  private static function rowFor(array $rows, string $component_id): array {
    $matches = \array_values(\array_filter($rows, static fn(array $row): bool => $row['id'] === $component_id));
    self::assertCount(1, $matches, \sprintf('Expected exactly one row for %s.', $component_id));
    return $matches[0];
  }

  /**
   * Returns the incompatibility reason repository.
   */
  private function reasonRepository(): ComponentIncompatibilityReasonRepository {
    $repository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
    \assert($repository instanceof ComponentIncompatibilityReasonRepository);
    return $repository;
  }

}
