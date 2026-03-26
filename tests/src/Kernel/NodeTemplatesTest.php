<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;
use ColinODell\PsrTestLogger\TestLogger;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\filter\Entity\FilterFormat;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
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
   * Tests Node Templates.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder
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
    'language',
    'locale',
    'config_translation',
    'canvas_dev_translation',
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
    $this->installEntitySchema('configurable_language');
    $this->installConfig(['node', 'filter', 'language']);
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
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Tests opting a content type into Canvas rendering.
   *
   * @param bool $node_is_published
   *   Whether the node should be published.
   * @param string $view_mode
   *   The view mode to render the node in.
   * @param bool $expected_entity_data_is_accessible
   *   Whether entity field data is expected to be accessible.
   * @param list<string> $expected_node_component_tree_cache_tags
   *   Expected cache tags from the component tree render.
   */
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
      // resolve because they were inaccessible: EntityFieldPropSources
      // populated by the host entity.
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
    // The content type has not been opted into Canvas, so it should not be
    // using Canvas for rendering.
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
    $node = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node')->loadUnchanged((int) $node->id());
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
          'component_version' => '85a5c0c7dd53e0bb',
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
          'slot' => 'the_body',
          'parent_uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
        ],
        // If the entity is targeting a slot that doesn't exist in the template,
        // or is not exposed, it shouldn't be an error.
        // @todo This should actually be purged when the entity is saved, so
        //   implement that in https://www.drupal.org/i/3520517.
        [
          'uuid' => '9a1ec750-e016-44fb-9bd2-9a7acb497bd7',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'slot' => 'ignore_me',
          'parent_uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
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
    self::assertSame('Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc.canvas_test_sdc.props-slots</em>: <em class="placeholder">ignore_me</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.', (string) $violation->getMessage());

    // If we delete the field item, all good!
    $node->get('field_component_tree')->removeItem(1);
    self::assertEntityIsValid($node);
  }

  /**
   * Tests that translated content templates render correctly per language.
   *
   * Verifies that:
   * 1. A French config language override of a content template's component_tree
   *    is respected when the view builder renders in French.
   * 2. The English default component_tree is rendered when the active language
   *    is English.
   * 3. Both rendered builds have the languages:language_interface cache context
   *    so that Drupal's page cache correctly varies the response per language.
   *
   * Only static prop sources are used so that every observable difference
   * between the two renders comes exclusively from the translated config,
   * with no interference from entity-field resolution or access checks.
   *
   * These scenarios are split into two test methods rather than one method
   * that switches language mid-test. Switching config override language inside
   * a single kernel test requires invalidating the config factory cache, the
   * entity storage cache, and the render cache before the second render.
   * Splitting into two methods avoids that ceremony: each method sets the
   * language before anything is loaded or cached, so no mid-test flushing
   * is needed.
   *
   * @see self::testContentTemplateRendersEnglishByDefault()
   * @see self::testContentTemplateRendersFrenchWhenOverrideLanguageIsFrench()
   */
  public function testContentTemplateRendersEnglishByDefault(): void {
    $this->doSetUpTranslatedTemplate();

    $node = $this->createNode(['type' => 'article', 'title' => 'Translation test node', 'status' => TRUE, 'uid' => 1]);
    self::assertFalse($node->isNew());

    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);

    $build = $viewBuilder->view($node, 'full');
    $crawler = $this->crawlerForRenderArray($build);

    self::assertCount(
      1,
      $crawler->filter('h1:contains("Canvas is large and in charge!")'),
      'English heading must be present when the active language is English.',
    );
    self::assertCount(
      0,
      $crawler->filter('h1:contains("Canvas est grand et aux commandes !")'),
      'French heading must not appear when the active language is English.',
    );
    // This cache context is what allows Drupal's page cache to store separate
    // responses per language. Its presence here is the contract this test
    // enforces.
    self::assertContains(
      'languages:language_interface',
      $build['#cache']['contexts'],
      'Build must declare languages:language_interface so the page cache varies by language.',
    );
  }

  /**
   * French override: switch the config override language to French.
   *
   * This ensures the French component_tree is used when rendering.
   */
  public function testContentTemplateRendersFrenchWhenOverrideLanguageIsFrench(): void {
    $this->doSetUpTranslatedTemplate();

    // Switch to French before anything is loaded or rendered. Because nothing
    // has been cached yet in this test method, no cache flushing is needed.
    $languageManager = $this->container->get('language_manager');
    $french = $languageManager->getLanguage('fr');
    self::assertNotNull($french, 'French language must be installed.');
    $languageManager->setConfigOverrideLanguage($french);

    $node = $this->createNode(['type' => 'article', 'title' => 'Translation test node', 'status' => TRUE, 'uid' => 1]);
    self::assertFalse($node->isNew());

    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);

    $build = $viewBuilder->view($node, 'full');
    $crawler = $this->crawlerForRenderArray($build);

    self::assertCount(
      1,
      $crawler->filter('h1:contains("Canvas est grand et aux commandes !")'),
      'French heading must be present when the config override language is French.',
    );
    self::assertCount(
      0,
      $crawler->filter('h1:contains("Canvas is large and in charge!")'),
      'English heading must not appear when the config override language is French.',
    );
    self::assertContains(
      'languages:language_interface',
      $build['#cache']['contexts'],
      'French build must also declare languages:language_interface as a cache context.',
    );
  }

  /**
   * Set up the English default content template and its French config override.
   *
   * Extracted into a helper so both translation test methods share identical
   * fixture setup without duplication.
   */
  private function doSetUpTranslatedTemplate(): void {
    // props-no-slots is used instead of my-hero because it only requires a
    // single `heading` prop. my-hero has several required props (cta1href,
    // cta1, subheading) whose absence would cause the config schema checker to
    // throw before the test reaches its assertions.
    ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'b1e991f726a2a266',
          'inputs' => [
            'heading' => 'Canvas is large and in charge!',
          ],
        ],
      ],
    ])->setStatus(TRUE)->save();

    // Store a French config language override. This is the same mechanism that
    // Drupal's config_translation UI uses when a site builder translates a
    // content template: it writes a language-specific override file on top of
    // the default config rather than duplicating the entire config entity.
    $this->container->get(LanguageConfigFactoryOverrideInterface::class)
      ->getOverride('fr', 'canvas.content_template.node.article.full')
      ->set('component_tree', [
        [
          'uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'b1e991f726a2a266',
          'inputs' => [
            'heading' => 'Canvas est grand et aux commandes !',
          ],
        ],
      ])
      ->save();
  }

}
