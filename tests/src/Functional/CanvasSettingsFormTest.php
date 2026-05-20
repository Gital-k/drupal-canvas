<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @covers \Drupal\canvas\Form\CanvasSettingsForm
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class CanvasSettingsFormTest extends FunctionalTestBase {

  protected static $modules = ['canvas'];

  protected $defaultTheme = 'stark';

  public function testFormPersistsCodeComponentSyncMode(): void {
    $admin = $this->drupalCreateUser(['administer code components']);
    \assert($admin instanceof AccountInterface);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/canvas/settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('sync_mode', 'preserve_production');

    $this->submitForm(['sync_mode' => 'cli'], 'Save configuration');
    $this->assertSession()->fieldValueEquals('sync_mode', 'cli');
    self::assertSame(
      'cli',
      $this->config('canvas.settings')->get('code_components.sync_mode'),
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
