<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the canvas_form entity type is installed via canvas_update_11201.
 *
 * @legacy-covers \canvas_update_11201
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class CanvasFormEntityTypeInstallUpdateTest extends CanvasUpdatePathTestBase {

  /**
   * Re-enable entity schema check; this test verifies canvas_update_11201.
   *
   * Verifying no pending entity updates remain after the hook runs is the
   * point of this test.
   *
   * @var bool
   * @phpstan-ignore shipmonk.deadProperty.neverRead
   */
  protected $checkEntityFieldDefinitionUpdates = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
  }

  /**
   * Tests the canvas_form entity type is installed after the update runs.
   */
  public function testCanvasFormEntityTypeIsInstalled(): void {
    $this->runUpdates();

    $entity_type = \Drupal::entityTypeManager()->getDefinition('canvas_form', FALSE);
    $this->assertNotNull($entity_type, 'The canvas_form entity type is defined.');

    $needs_updates = \Drupal::entityDefinitionUpdateManager()->needsUpdates();
    $this->assertFalse($needs_updates, 'No pending entity schema updates remain after running update hooks.');
  }

}
