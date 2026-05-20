<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Config\CodeComponentSyncMode;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\SchemaCheckTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @covers \Drupal\canvas\Form\CanvasSettingsForm
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class CanvasSettingsFormTest extends FunctionalTestBase {

  use SchemaCheckTestTrait;

  protected static $modules = ['canvas'];

  protected $defaultTheme = 'stark';

  public function testFormPersistsCodeComponentSyncMode(): void {
    $admin = $this->drupalCreateUser(['administer code components']);
    \assert($admin instanceof AccountInterface);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/canvas/settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('sync_mode', CodeComponentSyncMode::PreserveProduction->value);

    $this->submitForm(['sync_mode' => CodeComponentSyncMode::Cli->value], 'Save configuration');
    $this->assertSession()->fieldValueEquals('sync_mode', CodeComponentSyncMode::Cli->value);

    $config = $this->config('canvas.settings');
    self::assertSame(CodeComponentSyncMode::Cli->value, $config->get('code_components.sync_mode'));

    $this->assertConfigSchema(
      \Drupal::service('config.typed'),
      'canvas.settings',
      $config->get(),
    );
  }

  public function testUnauthorizedUserCannotAccess(): void {
    $user = $this->drupalCreateUser(['access content']);
    \assert($user instanceof AccountInterface);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/canvas/settings');
    $this->assertSession()->statusCodeEquals(403);
  }

}
