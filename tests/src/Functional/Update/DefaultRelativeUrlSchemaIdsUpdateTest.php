<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Connection;
use Drupal\field\Entity\FieldConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests cleaning up old default-relative-url schemas during update.
 *
 * Canvas 1.5.0 and earlier saved a default-relative-url value's JSON Schema
 * with extra `$id` entries that the schema resolver adds. Since 1.5.1
 * (#3591629) the code that reads it removes those entries, so it no longer
 * matches the old saved copy and the editor fails with a 500. The 0027 updates
 * remove the extra entries from pages, every kind of config that holds a
 * component tree, and auto-save drafts.
 *
 * The dump was saved at Canvas 1.5.0 (updates done through 0019), so no earlier
 * update re-saves the seeded data and quietly cleans it up first — the 0027
 * updates are the only thing that fixes each case.
 *
 * @legacy-covers \canvas_post_update_0027_default_relative_url_schema_ids
 * @legacy-covers \canvas_post_update_0027_pattern_default_relative_url_schema_ids
 * @legacy-covers \canvas_post_update_0027_page_region_default_relative_url_schema_ids
 * @legacy-covers \canvas_post_update_0027_content_template_default_relative_url_schema_ids
 * @legacy-covers \canvas_post_update_0027_component_tree_field_default_value_default_relative_url_schema_ids
 * @legacy-covers \canvas_post_update_0027_auto_save_default_relative_url_schema_ids
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class DefaultRelativeUrlSchemaIdsUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * Part of the `$id` value, picked so JSON slash-escaping cannot hide it.
   */
  private const STALE_MARKER = 'json-schema-definitions:';

  /**
   * A `title` only the stale array (gallery) schema carries.
   *
   * Pre-1.5.1 normalize never recursed into an array's `items`, so old gallery
   * data keeps the SDC titles there too. `$id` healing alone does not remove
   * them, so this proves the array items are fully normalized by the update.
   */
  private const STALE_TITLE_MARKER = 'Alternative text';

  /**
   * The seeded config entities, keyed by class, to check after the update.
   */
  private const CONFIG = [
    Pattern::class => 'stale_default_relative_url',
    PageRegion::class => 'stark.sidebar_first',
    ContentTemplate::class => 'node.article.full',
    FieldConfig::class => 'node.article.field_canvas_demo',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.3.10-with-canvas-1.5.0.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/default_relative_url_schema_ids/default-relative-url-schema-ids-fixture.php';
  }

  /**
   * The extra `$id` entries are removed from pages, config and auto-save.
   */
  public function testDefaultRelativeUrlSchemaIds(): void {
    $connection = \Drupal::database();
    $store = \Drupal::keyValue(AutoSaveManager::AUTO_SAVE_STORE);

    // Before the update: every place the fixture seeded still has the extra
    // `$id` — the page, all four config entities and the auto-save drafts.
    self::assertStringContainsString(self::STALE_MARKER, self::readPageInputs($connection), 'Expected the fixture to seed a stale $id in the page.');
    self::assertStringContainsString(self::STALE_MARKER, self::readConfig($connection), 'Expected the fixture to seed a stale $id in config.');
    self::assertStringContainsString(self::STALE_MARKER, Json::encode($store->getAll()), 'Expected a stale $id in auto-save drafts.');

    // The array (gallery) values also seed a stale `title` inside their `items`.
    self::assertStringContainsString(self::STALE_TITLE_MARKER, self::readPageInputs($connection), 'Expected the fixture to seed a stale array title in the page.');
    self::assertStringContainsString(self::STALE_TITLE_MARKER, self::readConfig($connection), 'Expected the fixture to seed a stale array title in config.');
    self::assertStringContainsString(self::STALE_TITLE_MARKER, Json::encode($store->getAll()), 'Expected a stale array title in auto-save drafts.');

    $this->runUpdates();

    // After the update: no `$id` is left in the page, in any revision. The
    // parent test class also checks the site health report is clean, which only
    // happens once every schema can be read again.
    $after_page = self::readPageInputs($connection);
    self::assertStringNotContainsString(self::STALE_MARKER, $after_page);
    self::assertStringNotContainsString('"$id"', $after_page);
    // The array items were normalized too, so their titles are gone.
    self::assertStringNotContainsString(self::STALE_TITLE_MARKER, $after_page);

    // Every seeded config entity is valid and has no `$id` left. The parent
    // class already checks all config through the health report; check the ones
    // we seeded directly too.
    foreach (self::CONFIG as $class => $id) {
      $entity = $class::load($id);
      self::assertInstanceOf($class, $entity);
      self::assertEntityIsValid($entity);
    }
    self::assertStringNotContainsString(self::STALE_MARKER, self::readConfig($connection));
    self::assertStringNotContainsString(self::STALE_TITLE_MARKER, self::readConfig($connection));

    // The parent class does not fail on bad auto-save drafts, so check those
    // directly. Read them again from a fresh handle.
    $store = \Drupal::keyValue(AutoSaveManager::AUTO_SAVE_STORE);
    self::assertStringNotContainsString(self::STALE_MARKER, Json::encode($store->getAll()));
    self::assertStringNotContainsString(self::STALE_TITLE_MARKER, Json::encode($store->getAll()));
  }

  /**
   * Reads the page's component inputs from both the main and revision tables.
   */
  private static function readPageInputs(Connection $connection): string {
    $all = '';
    foreach (['canvas_page__components', 'canvas_page_revision__components'] as $table) {
      if (!$connection->schema()->tableExists($table)) {
        continue;
      }
      $values = $connection->select($table, 't')->fields('t', ['components_inputs'])->execute()?->fetchCol() ?? [];
      foreach ($values as $value) {
        $all .= (string) $value;
      }
    }
    return $all;
  }

  /**
   * Reads the raw saved `data` of the four seeded config rows.
   */
  private static function readConfig(Connection $connection): string {
    $names = [
      'canvas.pattern.stale_default_relative_url',
      'canvas.page_region.stark.sidebar_first',
      'canvas.content_template.node.article.full',
      'field.field.node.article.field_canvas_demo',
    ];
    $values = $connection->select('config', 'c')->fields('c', ['data'])->condition('name', $names, 'IN')->execute()?->fetchCol() ?? [];
    return \implode('', \array_map('strval', $values));
  }

}
