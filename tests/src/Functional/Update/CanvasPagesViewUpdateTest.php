<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\views\Entity\View;

/**
 * Tests canvas_pages view update to use publication state computed field.
 *
 * @covers canvas_post_update_0011_update_pages_view()
 * @group canvas
 */
final class CanvasPagesViewUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/views_view_canvas_pages/canvas-pages-before-publication-state.php';
  }

  /**
   * Tests canvas_pages view update.
   */
  public function testCanvasPagesViewUpdate(): void {
    $view = View::load('canvas_pages');
    $this->assertNotNull($view, 'View should exist before update.');

    $config_before = \Drupal::config('views.view.canvas_pages');

    $filters_before = $config_before->get('display.default.display_options.filters');
    $this->assertArrayHasKey('status', $filters_before, 'View should have status filter before update.');
    $this->assertSame('boolean', $filters_before['status']['plugin_id'], 'Filter should use boolean plugin before update.');
    $this->assertSame('status', $filters_before['status']['field'], 'Filter field should be status before update.');
    $this->assertSame('=', $filters_before['status']['operator'], 'Filter operator should be = before update.');
    $this->assertSame('1', $filters_before['status']['value'], 'Filter value should be 1 before update.');
    $this->assertSame('Published', $filters_before['status']['expose']['label'], 'Filter label should be Published before update.');
    $this->assertSame('status', $filters_before['status']['expose']['identifier'], 'Filter identifier should be status before update.');
    $this->assertTrue($filters_before['status']['expose']['required'], 'Filter should be required before update.');
    $this->assertTrue($filters_before['status']['is_grouped'], 'Filter should be grouped before update.');
    $this->assertArrayNotHasKey('publication_state_computed', $filters_before, 'View should not have publication_state_computed filter before update.');

    $page_fields_before = $config_before->get('display.page_1.display_options.fields');
    $this->assertArrayHasKey('status', $page_fields_before, 'Page display should have status field before update.');
    $this->assertSame('field', $page_fields_before['status']['plugin_id'], 'Page field should use field plugin before update.');
    $this->assertSame('Status', $page_fields_before['status']['label'], 'Page field label should be Status before update.');
    $this->assertSame('boolean', $page_fields_before['status']['type'], 'Page field type should be boolean before update.');
    $this->assertArrayNotHasKey('publication_state_computed', $page_fields_before, 'Page display should not have publication_state_computed field before update.');

    $this->runUpdates();

    $view_after = View::load('canvas_pages');
    $this->assertNotNull($view_after, 'View should still exist after update.');

    $config_after = \Drupal::config('views.view.canvas_pages');

    $filters = $config_after->get('display.default.display_options.filters');
    $this->assertArrayHasKey('publication_state_computed', $filters, 'View should have publication_state_computed filter after update.');
    $this->assertArrayNotHasKey('status', $filters, 'View should not have status filter after update.');
    $this->assertSame('canvas_publication_state_filter', $filters['publication_state_computed']['plugin_id'], 'Filter should use canvas_publication_state_filter plugin after update.');
    $this->assertSame('publication_state_computed', $filters['publication_state_computed']['field'], 'Filter field should be publication_state_computed after update.');
    $this->assertSame('in', $filters['publication_state_computed']['operator'], 'Filter operator should be in after update.');
    $this->assertSame([], $filters['publication_state_computed']['value'], 'Filter value should be empty array after update.');
    $this->assertSame('Publication State', $filters['publication_state_computed']['expose']['label'], 'Filter label should be Publication State after update.');
    $this->assertSame('publication_state_computed', $filters['publication_state_computed']['expose']['identifier'], 'Filter identifier should be publication_state_computed after update.');
    $this->assertFalse($filters['publication_state_computed']['expose']['required'], 'Filter should not be required after update.');
    $this->assertFalse($filters['publication_state_computed']['is_grouped'], 'Filter should not be grouped after update.');

    $page_fields = $config_after->get('display.page_1.display_options.fields');
    $this->assertArrayHasKey('publication_state_computed', $page_fields, 'Page display should have publication_state_computed field after update.');
    $this->assertArrayNotHasKey('status', $page_fields, 'Page display should not have status field after update.');
    $this->assertSame('canvas_publication_state', $page_fields['publication_state_computed']['plugin_id'], 'Page field should use canvas_publication_state plugin after update.');
    $this->assertSame('publication_state_computed', $page_fields['publication_state_computed']['field'], 'Page field should be publication_state_computed after update.');
    $this->assertSame('Publication State', $page_fields['publication_state_computed']['label'], 'Page field label should be Publication State after update.');
    $this->assertArrayNotHasKey('type', $page_fields['publication_state_computed'], 'Page field should not have type after update (uses custom plugin).');
    $this->assertArrayNotHasKey('click_sort_column', $page_fields['publication_state_computed'], 'Page field should not have click_sort_column after update.');
    $this->assertArrayNotHasKey('settings', $page_fields['publication_state_computed'], 'Page field should not have settings after update (uses custom plugin).');
  }

}
