<?php

/**
 * @file
 * Adds default-relative-url values that still have the extra `$id` entries.
 *
 * Canvas 1.5.0 and earlier saved a default-relative-url value's JSON Schema
 * with extra `$id` entries the resolver adds. Since 1.5.1 (#3591629) the code
 * that reads it removes those entries, so it no longer matches the old saved
 * copy and refuses to load it. This adds that old, broken state to a page, all
 * four kinds of config that hold a component tree, and two auto-save drafts, on
 * top of the bare Canvas 1.5.0 dump.
 *
 * The dump was saved at 1.5.0 (updates done through 0019), so the earlier
 * updates that could otherwise clean this up as a side effect have already run
 * — which means the 0027 updates are the only thing that fixes each case.
 *
 * @see \Drupal\Tests\canvas\Functional\Update\DefaultRelativeUrlSchemaIdsUpdateTest
 */

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Pattern;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Database;

$connection = Database::getConnection();

$component_id = 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop';
$component_dependency = 'canvas.component.' . $component_id;
$component_version = 'c9543b26fa15297b';

// The schema exactly as it is saved today (see the kernel test
// DefaultRelativeUrlPropSourceTest), but with the extra `$id` entries that old
// pre-1.5.1 data still has. The update turns it back into this same schema
// without the `$id` entries.
$stale_schema = [
  '$id' => 'json-schema-definitions://canvas.module/image',
  'type' => 'object',
  'properties' => [
    'src' => [
      '$id' => 'json-schema-definitions://canvas.module/image-uri',
      'type' => 'string',
      'contentMediaType' => 'image/*',
      'format' => 'uri-reference',
      'x-allowed-schemes' => ['http', 'https'],
    ],
    'alt' => ['type' => 'string'],
    'width' => ['type' => 'integer'],
    'height' => ['type' => 'integer'],
  ],
  'required' => ['src'],
];
$prop_source = [
  'sourceType' => 'default-relative-url',
  'value' => ['src' => 'gracie.jpg', 'alt' => 'A good dog', 'width' => 601, 'height' => 402],
  'jsonSchema' => $stale_schema,
  'componentId' => $component_id,
];
// A component whose `image` prop uses the value above. Pages store `inputs` as
// a JSON string; config trees store it as an array.
$content_inputs = Json::encode(['image' => $prop_source]);
$config_inputs = ['image' => $prop_source];

// Builds one config component tree, keyed by the instance UUID (the shape
// config uses since update 0017).
$config_tree = static fn(string $uuid): array => [
  $uuid => [
    'uuid' => $uuid,
    'component_id' => $component_id,
    'component_version' => $component_version,
    'inputs' => $config_inputs,
  ],
];

// 1. Content: a page with a single image component. It has no block, so no
// other update re-saves it before 0027. A high id keeps it clear of the demo
// pages the dump already ships.
$page_uuid = 'f0a9c0de-0000-4000-8000-00000000e001';
$connection->insert('canvas_page')
  ->fields(['id', 'revision_id', 'uuid', 'langcode'])
  ->values(['id' => '100', 'revision_id' => '100', 'uuid' => $page_uuid, 'langcode' => 'en'])
  ->execute();
$connection->insert('canvas_page_revision')
  ->fields(['id', 'revision_id', 'langcode', 'revision_user', 'revision_created', 'revision_log', 'revision_default'])
  ->values(['id' => '100', 'revision_id' => '100', 'langcode' => 'en', 'revision_user' => NULL, 'revision_created' => '1759961617', 'revision_log' => NULL, 'revision_default' => '1'])
  ->execute();
// The data table has an `owner` column; the revision table does not.
$connection->insert('canvas_page_field_data')
  ->fields(['id', 'revision_id', 'langcode', 'status', 'owner', 'title', 'description', 'created', 'changed', 'image', 'default_langcode', 'revision_translation_affected'])
  ->values(['id' => '100', 'revision_id' => '100', 'langcode' => 'en', 'status' => '1', 'owner' => '1', 'title' => 'Stale page', 'description' => '', 'created' => '1759961617', 'changed' => '1759961617', 'image' => NULL, 'default_langcode' => '1', 'revision_translation_affected' => '1'])
  ->execute();
$connection->insert('canvas_page_field_revision')
  ->fields(['id', 'revision_id', 'langcode', 'status', 'title', 'description', 'created', 'changed', 'image', 'default_langcode', 'revision_translation_affected'])
  ->values(['id' => '100', 'revision_id' => '100', 'langcode' => 'en', 'status' => '1', 'title' => 'Stale page', 'description' => '', 'created' => '1759961617', 'changed' => '1759961617', 'image' => NULL, 'default_langcode' => '1', 'revision_translation_affected' => '1'])
  ->execute();
foreach (['canvas_page__components', 'canvas_page_revision__components'] as $table) {
  $connection->insert($table)
    ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'langcode', 'delta', 'components_parent_uuid', 'components_slot', 'components_uuid', 'components_component_id', 'components_component_version', 'components_inputs', 'components_label'])
    ->values(['bundle' => 'canvas_page', 'deleted' => '0', 'entity_id' => '100', 'revision_id' => '100', 'langcode' => 'en', 'delta' => '0', 'components_parent_uuid' => NULL, 'components_slot' => NULL, 'components_uuid' => 'f0a9c0de-0000-4000-8000-00000000d001', 'components_component_id' => $component_id, 'components_component_version' => $component_version, 'components_inputs' => $content_inputs, 'components_label' => NULL])
    ->execute();
}

// 2. Config — field default value (covers the `default_value` save path). The
// dump ships field_canvas_demo (via the test_site recipe); read it, add a stale
// component instance, and write it back so the rest stays intact.
$field_name = 'field.field.node.article.field_canvas_demo';
$field_data = unserialize((string) $connection->select('config', 'c')->fields('c', ['data'])->condition('name', $field_name)->execute()->fetchField());
$field_data['dependencies']['config'][] = $component_dependency;
\sort($field_data['dependencies']['config']);
$field_data['default_value'][] = [
  'uuid' => 'f0a9c0de-0000-4000-8000-00000000c003',
  'component_id' => $component_id,
  'component_version' => $component_version,
  'inputs' => $config_inputs,
];
$connection->update('config')->condition('name', $field_name)->fields(['data' => serialize($field_data)])->execute();

// 3-5. Config — Pattern, Page Region and Content Template (all cover the
// setComponentTree save path). Each carries one image component with a stale
// `$id`. Dependencies are recalculated when 0027 re-saves them.
$connection->insert('config')
  ->fields(['collection', 'name', 'data'])
  ->values([
    'collection' => '',
    'name' => 'canvas.pattern.stale_default_relative_url',
    'data' => serialize([
      'uuid' => 'f0a9c0de-0000-4000-8000-00000000c001',
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => ['config' => [$component_dependency]],
      'id' => 'stale_default_relative_url',
      'label' => 'Stale default-relative-url pattern',
      'component_tree' => $config_tree('f0a9c0de-0000-4000-8000-00000000c002'),
    ]),
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.page_region.stark.sidebar_first',
    'data' => serialize([
      'uuid' => 'f0a9c0de-0000-4000-8000-00000000c010',
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => ['config' => [$component_dependency], 'theme' => ['stark']],
      'id' => 'stark.sidebar_first',
      'region' => 'sidebar_first',
      'theme' => 'stark',
      'component_tree' => $config_tree('f0a9c0de-0000-4000-8000-00000000c011'),
    ]),
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.content_template.node.article.full',
    'data' => serialize([
      'uuid' => 'f0a9c0de-0000-4000-8000-00000000c020',
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => ['config' => ['core.entity_view_mode.node.full', $component_dependency]],
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => $config_tree('f0a9c0de-0000-4000-8000-00000000c021'),
      'exposed_slots' => [],
    ]),
  ])
  ->execute();

// 6. Auto-save: drafts live in the `canvas.auto_save` key-value store, which is
// itself a database table, so add them here too. One draft stores `inputs` as a
// JSON string (a page), one as an array (config); the update handles both.
$auto_save_entry = static fn(string $entity_type, string $entity_id, array $data): array => [
  'entity_type' => $entity_type,
  'entity_id' => $entity_id,
  'data' => $data,
  'langcode' => 'en',
  'is_default_translation' => TRUE,
  'label' => $data['label'] ?? $data['title'] ?? 'Stale draft',
  AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY => 'seed',
  'data_hash' => 'seed',
  'client_id' => NULL,
  'owner' => 0,
  'updated' => 1759961617,
];
$connection->insert('key_value')
  ->fields(['collection', 'name', 'value'])
  ->values([
    'collection' => AutoSaveManager::AUTO_SAVE_STORE,
    'name' => 'canvas_page:100:en',
    'value' => serialize($auto_save_entry('canvas_page', '100', [
      'title' => 'Stale content draft',
      'components' => [
        [
          'uuid' => 'f0a9c0de-0000-4000-8000-00000000a001',
          'component_id' => $component_id,
          'component_version' => $component_version,
          'inputs' => $content_inputs,
        ],
      ],
    ])),
  ])
  ->values([
    'collection' => AutoSaveManager::AUTO_SAVE_STORE,
    'name' => Pattern::ENTITY_TYPE_ID . ':stale_default_relative_url',
    'value' => serialize($auto_save_entry(Pattern::ENTITY_TYPE_ID, 'stale_default_relative_url', [
      'id' => 'stale_default_relative_url',
      'label' => 'Stale config draft',
      'component_tree' => [
        [
          'uuid' => 'f0a9c0de-0000-4000-8000-00000000a002',
          'component_id' => $component_id,
          'component_version' => $component_version,
          'inputs' => $config_inputs,
        ],
      ],
    ])),
  ])
  ->execute();
