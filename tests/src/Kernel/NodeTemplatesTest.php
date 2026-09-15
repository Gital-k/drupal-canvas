<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests Node Templates.
 *
 * @legacy-covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class NodeTemplatesTest extends CanvasKernelTestBase {

  use SingleDirectoryComponentTreeTestTrait;
  use GenerateComponentConfigTrait;
  use ContentTypeCreationTrait;
  use CanvasFieldCreationTrait;
  use NodeCreationTrait;
  use CrawlerTrait;
  use UserCreationTrait;

  /**
   * @see core.services.yml
   */
  private const REQUIRED_CACHE_CONTEXTS = [
    'languages:language_interface',
    'theme',
    'user.permissions',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'canvas_test_rendering',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node', 'filter']);
    $this->installConfig(['canvas']);
    $this->createContentType(['type' => 'article']);
    $this->generateComponentConfig();
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => [
        'filter_html' => [
          'module' => 'filter',
          'status' => TRUE,
          'weight' => 10,
          'settings' => [
            'allowed_html' => '<p>',
          ],
        ],
      ],
    ])->save();
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  #[TestWith([
    TRUE,
    'full',
    TRUE,
    [
      // Components in the component tree.
      'config:canvas.component.sdc.canvas_test_sdc.my-hero',
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // Cacheability of resolved props.
      'node:1',
      'config:filter.format.basic_html',
    ],
  ])]
  #[TestWith([
    TRUE,
    'card',
    TRUE,
    [
      // Components in the component tree.
      'config:canvas.component.sdc.canvas_test_sdc.my-hero',
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // Cacheability of resolved props.
      'node:1',
      'config:filter.format.basic_html',
    ],
  ])]
  #[TestWith([
    FALSE,
    'full',
    FALSE,
    [
      // Components in the component tree — minus the ones whose props failed to
      // resolve because they were inaccessible: EntityFieldPropSources populated by
      // the host entity.
      'config:canvas.component.sdc.canvas_test_sdc.my-hero',
      // @todo Stop expecting this cache tag in https://www.drupal.org/i/3559820
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // @see \Drupal\node\NodeAccessControlHandler::checkViewAccess()
      'node:1',
    ],
  ])]
  public function testOptContentTypeIntoCanvas(bool $node_is_published, string $view_mode, bool $expected_entity_data_is_accessible, array $expected_node_component_tree_cache_tags): void {
    // Create an alternate view mode, so we can test that content templates work
    // for more than just the full view mode.
    EntityViewMode::create([
      'id' => 'node.card',
      'label' => 'Card',
      'targetEntityType' => 'node',
    ])->save();

    ContentTemplate::create([
      'id' => "node.article.$view_mode",
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => $view_mode,
      'component_tree' => [
        // A static marker so we can easily tell if we're rendering with Canvas,
        // but simultaneously tests all currently supported dynamic ways of
        // populating props.
        [
          'uuid' => 'e1f6fbca-e331-4506-9dba-5734194c1e59',
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'component_version' => 'a681ae184a8f6b7f',
          'inputs' => [
            // Tests static prop source end-to-end.
            // @see \Drupal\canvas\PropSource\StaticPropSource
            'heading' => 'Canvas is large and in charge!',
            // Tests adapted entity field prop source end-to-end.
            // @see \Drupal\canvas\PropSource\EntityFieldPropSource::__construct(adapter)
            'subheading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝created␞␟value',
              'adapter' => 'unix_to_date',
            ],
            // Tests entity field prop source end-to-end.
            // @see \Drupal\canvas\PropSource\EntityFieldPropSource
            'cta1' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            // Tests host entity URL prop source end-to-end.
            // @see \Drupal\canvas\PropSource\HostEntityUrlPropSource
            'cta1href' => [
              'sourceType' => PropSource::HostEntityUrl->value,
            ],
          ],
        ],
        // The node body, which needs to be using a entity field prop source
        // because all content templates require at least one entity field prop
        // source.
        [
          'uuid' => '6cf8297a-fc60-4019-be81-c336fd828c39',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝body␞␟processed',
            ],
          ],
        ],
      ],
    ])->save();
    $body = <<<HTML
<p>Hey this is allowed</p>
<script>alert('hi mum')</script>
HTML;

    $node = $this->createNode([
      'type' => 'article',
      'title' => 'This is a node whose structured data is rendered using a Canvas content template!',
      'created' => 1764872657,
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
      'status' => $node_is_published,
      'uid' => 1,
    ]);
    self::assertSame($node_is_published, $node->isPublished());
    self::assertFalse($node->isNew());
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);
    $build = $viewBuilder->view($node, $view_mode);
    $crawler = $this->crawlerForRenderArray($build);
    // The content type has not been opted into Canvas, so it should not be using
    // Canvas for rendering.
    self::assertCount(0, $crawler->filter('h1.my-hero__heading:contains("Canvas is large and in charge!")'));
    self::assertCount(0, $crawler->filter('div.my-hero__container > p.my-hero__subheading:contains("2025-12-04")'));
    self::assertCount(0, $crawler->filter(\sprintf('div.my-hero__container > div.my-hero__actions > a[href="%s/node/1"]:contains("%s")', $GLOBALS['base_url'], $node->getTitle())));
    self::assertCount(1, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));
    self::assertEqualsCanonicalizing([
      'config:filter.format.basic_html',
      'user:1',
      'user_view',
      // TRICKY: this cache tag is present because the config entity does exist,
      // but is disabled. It was assessed whether it should be used, hence its
      // cache tag is present.
      "config:canvas.content_template.node.article.$view_mode",
      // The auto-save cache tag is NOT present because we're not on a preview
      // route. It's only added on preview routes to avoid invalidating all
      // rendered nodes on the live site when auto-saves change.
    ], $build['#cache']['tags']);
    self::assertEqualsCanonicalizing([
      ...self::REQUIRED_CACHE_CONTEXTS,
      'timezone',
      'route.name.is_canvas_editor_ui',
    ], $build['#cache']['contexts']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame([
      'entity_view',
      'node',
      (string) $node->id(),
      $view_mode,
      'without-canvas',
    ], $build['#cache']['keys']);

    // Confirm although we've opted in the status of the template is false so
    // will not be used.
    $template = ContentTemplate::load("node.article.$view_mode");

    // ContentTemplate component trees with prop sources that need a host
    // entity cannot resolve inputs without one: inputs_resolved returns NULL.
    \assert($template instanceof ContentTemplate);
    $no_hosted_entity_component_tree = $template->getComponentTree()->get(0);
    \assert($no_hosted_entity_component_tree instanceof ComponentTreeItem);
    self::assertNull($no_hosted_entity_component_tree->get('inputs_resolved')->getValue());
    // With a host entity, inputs_resolved returns the resolved values.
    $hosted_entity_component_tree = $template->getComponentTree($node)->get(0);
    \assert($hosted_entity_component_tree instanceof ComponentTreeItem);
    $resolved = $hosted_entity_component_tree->get('inputs_resolved')->getValue();
    self::assertIsArray($resolved);
    self::assertSame('Canvas is large and in charge!', $resolved['heading']);
    self::assertFalse($template->status());
    self::assertCount(0, $crawler->filter('h1.my-hero__heading:contains("Canvas is large and in charge!")'));
    self::assertCount(0, $crawler->filter('div.my-hero__container > p.my-hero__subheading:contains("2025-12-04")'));
    self::assertCount(0, $crawler->filter(\sprintf('div.my-hero__container > div.my-hero__actions > a[href="%s/node/1"]:contains("%s")', $GLOBALS['base_url'], $node->getTitle())));
    self::assertCount(1, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));

    // Updated the status of the template to true.
    $template->setStatus(TRUE)->save();

    // Reload the node now that the field definitions have changed.
    self::assertNotNull($node->id());
    $node = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node')->loadUnchanged($node->id());
    \assert($node instanceof NodeInterface);
    // Set up a logger so we can tell if
    // canvas_test_rendering_entity_display_build_alter() gets invoked.
    $logger = new TestLogger();
    $this->container->get(LoggerChannelFactoryInterface::class)
      ->get('canvas_test')
      ->addLogger($logger);
    $build = $viewBuilder->view($node, $view_mode);
    $crawler = $this->crawlerForRenderArray($build);
    $html = $crawler->html();

    self::assertTrue($template->status());
    self::assertStringContainsString('Canvas is large and in charge!', $html);
    self::assertCount(1, $crawler->filter('h1.my-hero__heading:contains("Canvas is large and in charge!")'));
    self::assertCount($expected_entity_data_is_accessible ? 1 : 0, $crawler->filter('div.my-hero__container > p.my-hero__subheading:contains("2025-12-04")'));
    self::assertCount($expected_entity_data_is_accessible ? 1 : 0, $crawler->filter(\sprintf('div.my-hero__container > div.my-hero__actions > a[href="%s/node/1"]:contains("%s")', $GLOBALS['base_url'], $node->getTitle())));
    self::assertCount($expected_entity_data_is_accessible ? 1 : 0, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));
    // Note: AutoSaveManager::CACHE_TAG is NOT present because we're not on a
    // preview route. It's only added on preview routes to avoid invalidating
    // all rendered nodes on the live site when auto-saves change.
    // @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::getBuildDefaults()
    self::assertEqualsCanonicalizing([
      "config:canvas.content_template.node.article.$view_mode",
      ...$expected_node_component_tree_cache_tags,
    ], $build['#cache']['tags']);
    self::assertEqualsCanonicalizing([
      ...self::REQUIRED_CACHE_CONTEXTS,
      'url.site',
      'route.name.is_canvas_editor_ui',
    ], $build['#cache']['contexts']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame([
      'entity_view',
      'node',
      (string) $node->id(),
      $view_mode,
      'with-canvas',
    ], $build['#cache']['keys']);

    // Confirm that hook_entity_display_build_alter() was not invoked.
    // @see canvas_test_rendering_entity_display_build_alter()
    $this->assertFalse($logger->hasRecordThatContains("hook_entity_display_build_alter for node {$node->id()} in full view mode"));

    $output = $viewBuilder->view($node, 'teaser');
    $crawler = $this->crawlerForRenderArray($output);
    // Confirm that the template is NOT used when viewing the node as a teaser,
    // even though the content type is opted into Canvas.
    self::assertCount(0, $crawler->filter(\sprintf('a[href="%s/node/1"]:contains("Canvas is large and in charge!")', $GLOBALS['base_url'])));
    // TRICKY: note that entity access is NOT checked by the EntityViewBuilder,
    // that is up to the caller! The above is specifically testing Canvas
    // ContentTemplates' render arrays. Those are populated by field properties
    // on the host entity, which is why for ContentTemplates, this test can
    // expect access to be denied when needed.
    self::assertCount(1, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));
    $this->assertTrue($logger->hasRecordThatContains("hook_entity_display_build_alter for node {$node->id()} in teaser view mode"));
  }

  /**
   * Tests exposed slots are filled by entity.
   *
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::build
   * @legacy-covers \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
   */
  public function testExposedSlotsAreFilledByEntity(): void {
    $this->createComponentTreeField('node', 'article', 'field_component_tree');

    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        // A simple SDC that will show the node's title, and has a slot
        // we can expose.
        [
          'uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'exposed_slots' => [
        'custom_content' => [
          'component_uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'slot_name' => 'the_body',
          'label' => 'Custom content area',
        ],
      ],
    ])->setStatus(TRUE)->save();

    // Create an article that fills in the template's exposed slot.
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'The Real Deal',
      'field_component_tree' => [
        [
          'uuid' => '6ea0de84-858a-4f00-9ef5-de02525c8865',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => "Now we're cooking with gas!",
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          // The entity references the exposed slot by its machine name, and
          // stores no parent UUID: the component instance holding the exposed
          // slot lives in the content template's component tree, not in this
          // one. That is what lets the exposed slot be moved.
          // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::isInExposedSlot()
          'slot' => 'custom_content',
          'parent_uuid' => NULL,
        ],
        // If the entity is targeting a slot that doesn't exist in the template,
        // or is not exposed, it shouldn't be an error.
        // @todo This should actually be purged when the entity is saved, so
        //   implement that in https://www.drupal.org/i/3520517.
        [
          'uuid' => '9a1ec750-e016-44fb-9bd2-9a7acb497bd7',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'slot' => 'ignore_me',
          'parent_uuid' => NULL,
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => "This won't show up.",
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
    ]);
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('h1:contains("The Real Deal")'));
    self::assertCount(1, $crawler->filter('h1:contains("Now we\'re cooking with gas!")'));
    self::assertStringNotContainsString("This won't show up.", $crawler->text());
    // Note: AutoSaveManager::CACHE_TAG is NOT present because we're not on a
    // preview route. It's only added on preview routes to avoid invalidating
    // all rendered nodes on the live site when auto-saves change.
    // @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::getBuildDefaults()
    self::assertEqualsCanonicalizing([
      'config:canvas.content_template.node.article.full',
      // Components in the component tree.
      'config:canvas.component.sdc.canvas_test_sdc.props-slots',
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // Entity field prop sources should propagate the entity's cache tags.
      'node:1',
    ], $build['#cache']['tags']);
    self::assertEqualsCanonicalizing([
      ...self::REQUIRED_CACHE_CONTEXTS,
      'route.name.is_canvas_editor_ui',
    ], $build['#cache']['contexts']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame([
      'entity_view',
      'node',
      '1',
      'full',
      'with-canvas',
    ], $build['#cache']['keys']);

    // Although the node targeting a nonexistent slot doesn't break rendering,
    // it DOES mean the entity isn't valid.
    $violations = $node->validate();
    self::assertCount(1, $violations);
    $violation = $violations->get(0);
    self::assertSame('field_component_tree.1.slot', $violation->getPropertyPath());
    self::assertSame('Invalid component subtree. This component subtree claims to fill an exposed slot that the content template <em class="placeholder">node.article.full</em> does not expose: <em class="placeholder">ignore_me</em>. Exposed slots are: <em class="placeholder">custom_content</em>.', (string) $violation->getMessage());

    // If we delete the field item, all good!
    $node->get('field_component_tree')->removeItem(1);
    self::assertEntityIsValid($node);

    // The point of referencing an exposed slot by machine name: the site
    // builder can move the exposed slot elsewhere in the content template's
    // component tree, and the content filling it keeps rendering. Move
    // `custom_content` from the `the_body` slot of the existing component
    // instance to the `the_footer` slot of a newly added one.
    $template = ContentTemplate::load('node.article.full');
    \assert($template instanceof ContentTemplate);
    $component_tree = $template->get('component_tree');
    \assert(\is_array($component_tree));
    $component_tree[] = [
      'uuid' => 'b0d4bb1d-9c24-4d2a-9e1e-6b6c4a84e0a1',
      'component_id' => 'sdc.canvas_test_sdc.props-slots',
      'component_version' => '0e79e884426a53ae',
      'inputs' => [
        'heading' => [
          'sourceType' => PropSource::EntityField->value,
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
      ],
    ];
    $template
      ->set('component_tree', $component_tree)
      ->set('exposed_slots', [
        'custom_content' => [
          'component_uuid' => 'b0d4bb1d-9c24-4d2a-9e1e-6b6c4a84e0a1',
          'slot_name' => 'the_footer',
          'label' => 'Custom content area',
        ],
      ])
      ->save();

    // The node itself was not touched, yet its component instance now renders
    // inside the moved exposed slot. Before referencing exposed slots by
    // machine name this was impossible: the node stored the UUID and slot name
    // the exposed slot happened to resolve to, so moving it stranded the
    // content.
    self::assertEntityIsValid($node);
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('h1:contains("Now we\'re cooking with gas!")'));
    // It really moved: it now renders in the exposed slot's new home, the
    // `the_footer` slot, and no longer in `the_body`.
    self::assertCount(1, $crawler->filter('div.component--props-slots--footer h1:contains("Now we\'re cooking with gas!")'));
    self::assertCount(0, $crawler->filter('div.component--props-slots--body h1:contains("Now we\'re cooking with gas!")'));
  }

  /**
   * Tests migrating stored UUID + slot pointers to exposed slot machine names.
   *
   * @legacy-covers \Drupal\canvas\CanvasConfigUpdater::rewriteExposedSlotReferenceToMachineName
   * @legacy-covers \canvas_post_update_0031_content_exposed_slot_machine_names
   */
  public function testExposedSlotReferencesAreMigratedToMachineNames(): void {
    $this->createComponentTreeField('node', 'article', 'field_component_tree');

    $exposed_slot_holder_uuid = '2842cc6f-9e2b-42a5-8400-e7d6363e08bf';
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $exposed_slot_holder_uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'exposed_slots' => [
        'custom_content' => [
          'component_uuid' => $exposed_slot_holder_uuid,
          'slot_name' => 'the_body',
          'label' => 'Custom content area',
        ],
      ],
    ])->setStatus(TRUE)->save();

    // A node storing the pre-#3528458 form: the bonsai root names the UUID and
    // slot the exposed slot happened to resolve to.
    $bonsai_root_uuid = '6ea0de84-858a-4f00-9ef5-de02525c8865';
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'The Real Deal',
      'field_component_tree' => [
        [
          'uuid' => $bonsai_root_uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => "Now we're cooking with gas!",
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'slot' => 'the_body',
          'parent_uuid' => $exposed_slot_holder_uuid,
        ],
        // A child of the bonsai root: its parent is in this very tree, so it is
        // an ordinary nested component instance and must not be rewritten.
        [
          'uuid' => '1c1f4d3a-0d2f-4d7f-9a58-4b0e6e0d0a11',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Nested inside the bonsai root.',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'slot' => 'the_body',
          'parent_uuid' => $bonsai_root_uuid,
        ],
        // A pointer no exposed slot resolves: `the_colophon` is a real slot of
        // the template's component instance, but it is not exposed. Rewriting
        // it would move content the content creator never put there.
        [
          'uuid' => '9a1ec750-e016-44fb-9bd2-9a7acb497bd7',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Dangling.',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'slot' => 'the_colophon',
          'parent_uuid' => $exposed_slot_holder_uuid,
        ],
      ],
    ]);

    $template = ContentTemplate::load('node.article.full');
    \assert($template instanceof ContentTemplate);
    $exposed_slots = $template->getExposedSlots();
    $own_uuids = [];
    foreach ($node->get('field_component_tree') as $item) {
      \assert($item instanceof ComponentTreeItem);
      $own_uuids[] = $item->getUuid();
    }

    $changed = [];
    foreach ($node->get('field_component_tree') as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);
      $changed[$delta] = CanvasConfigUpdater::rewriteExposedSlotReferenceToMachineName($item, $exposed_slots, $own_uuids);
    }
    // Only the bonsai root was rewritten.
    self::assertSame([0 => TRUE, 1 => FALSE, 2 => FALSE], $changed);

    $tree = $node->get('field_component_tree');
    \assert($tree instanceof ComponentTreeItemList);
    $bonsai_root = $tree->get(0);
    \assert($bonsai_root instanceof ComponentTreeItem);
    self::assertNull($bonsai_root->getParentUuid());
    self::assertSame('custom_content', $bonsai_root->getSlot());

    // The nested child still points at the bonsai root, in the same slot.
    $nested = $tree->get(1);
    \assert($nested instanceof ComponentTreeItem);
    self::assertSame($bonsai_root_uuid, $nested->getParentUuid());
    self::assertSame('the_body', $nested->getSlot());

    // The unresolvable pointer is left exactly as it was.
    $dangling = $tree->get(2);
    \assert($dangling instanceof ComponentTreeItem);
    self::assertSame($exposed_slot_holder_uuid, $dangling->getParentUuid());
    self::assertSame('the_colophon', $dangling->getSlot());

    // Running the migration again changes nothing: it is idempotent.
    foreach ($node->get('field_component_tree') as $item) {
      \assert($item instanceof ComponentTreeItem);
      self::assertFalse(CanvasConfigUpdater::rewriteExposedSlotReferenceToMachineName($item, $exposed_slots, $own_uuids));
    }

    // The migrated tree is what the renderer now expects: the bonsai root lands
    // in the exposed slot.
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('div.component--props-slots--body h1:contains("Now we\'re cooking with gas!")'));
    self::assertStringNotContainsString('Dangling.', $crawler->text());
  }

}
