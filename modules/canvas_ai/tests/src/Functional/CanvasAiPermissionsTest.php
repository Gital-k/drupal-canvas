<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests Canvas AI permissions and access control.
 *
 * @group canvas_ai
 */
final class CanvasAiPermissionsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_ai',
  ];

  /**
   * Tests access control for Canvas AI settings forms.
   */
  public function testCanvasAiSettingsAccess(): void {
    // Create a user without the 'administer Drupal Canvas AI' permission.
    $user_without_permission = $this->drupalCreateUser([
      'use Drupal Canvas AI',
    ]);

    // Create a user with the 'administer Drupal Canvas AI' permission.
    $user_with_admin_permission = $this->drupalCreateUser([
      'administer Drupal Canvas AI',
    ]);

    // Test access denied for user without admin permission.
    $this->drupalLogin($user_without_permission);
    $this->drupalGet('admin/config/system/canvas-ai-settings');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('admin/config/system/canvas-ai-component-description-settings');
    $this->assertSession()->statusCodeEquals(403);

    // Test access granted for user with admin permission.
    $this->drupalLogin($user_with_admin_permission);
    $this->drupalGet('admin/config/system/canvas-ai-settings');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('admin/config/system/canvas-ai-component-description-settings');
    $this->assertSession()->statusCodeEquals(200);
  }

}

