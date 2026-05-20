<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Config\CodeComponentSyncMode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests `canvas.settings` is populated with a default sync mode on update.
 *
 * @legacy-covers \canvas_update_11201
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class CanvasSettingsCodeComponentSyncModeUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
  }

  /**
   * Pre-update sites with no canvas.settings get the default sync mode.
   */
  public function testDefaultSyncModeWrittenWhenMissing(): void {
    self::assertNull(\Drupal::config('canvas.settings')->get('code_components.sync_mode'));

    $this->runUpdates();

    self::assertSame(
      CodeComponentSyncMode::PreserveProduction->value,
      \Drupal::config('canvas.settings')->get('code_components.sync_mode'),
    );
  }

  /**
   * Pre-update sites that already set a sync mode keep it.
   */
  public function testExistingSyncModeNotOverwritten(): void {
    \Drupal::configFactory()
      ->getEditable('canvas.settings')
      ->set('code_components.sync_mode', CodeComponentSyncMode::Cli->value)
      ->save();

    $this->runUpdates();

    self::assertSame(
      CodeComponentSyncMode::Cli->value,
      \Drupal::config('canvas.settings')->get('code_components.sync_mode'),
    );
  }

}
