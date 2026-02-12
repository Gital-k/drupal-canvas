<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Entity;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @group canvas
 */
#[RunTestsInSeparateProcesses]
final class PageTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use MediaTypeCreationTrait;
  use PageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
    $this->installPageEntitySchema();
  }

  public function testDefinition(): void {
    $sut = $this->container->get('entity_type.manager')
      ->getDefinition(Page::ENTITY_TYPE_ID);
    self::assertNotNull($sut);
    self::assertEquals(
      [
        'canonical' => '/page/{canvas_page}',
        'delete-form' => '/page/{canvas_page}/delete',
        'edit-form' => '/canvas/editor/canvas_page/{canvas_page}',
        'revision-delete-form' => '/page/{canvas_page}/revisions/{canvas_page_revision}/delete',
        'revision-revert-form' => '/page/{canvas_page}/revisions/{canvas_page_revision}/revert',
        'version-history' => '/page/{canvas_page}/revisions',
        'collection' => '/admin/content/pages',
      ],
      $sut->getLinkTemplates()
    );
  }

  public function testImageFieldDefinition(): void {
    $image_media_type = $this->createMediaType('image');
    // Create a `file` media type to ensure that the field definition is
    // correctly filtered to only allow media types that use `image`.
    $this->createMediaType('file');

    $fields = $this->container->get('entity_field.manager')
      ->getFieldDefinitions(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    self::assertArrayHasKey('image', $fields);
    $field = $fields['image'];
    self::assertEquals([
      'target_type' => 'media',
      'handler' => 'default',
      'handler_settings' => [
        'target_bundles' => [$image_media_type->id()],
      ],
    ], $field->getSettings());
    self::assertEquals([
      'type' => 'media_library_widget',
      'settings' => [
        'media_types' => [],
      ],
    ], $field->getDisplayOptions('form'));

    // Verify adding a new media type causes the base field's settings to be
    // automatically updated.
    $second_image_media_type = $this->createMediaType('image');
    $fields = $this->container->get('entity_field.manager')
      ->getFieldDefinitions(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    self::assertArrayHasKey('image', $fields);
    $field = $fields['image'];
    self::assertEqualsCanonicalizing([
      'target_type' => 'media',
      'handler' => 'default',
      'handler_settings' => [
        'target_bundles' => [$image_media_type->id(), $second_image_media_type->id()],
      ],
    ], $field->getSettings());

  }

  public function testEntity(): void {
    $test_heading_text = $this->randomString();

    $sut = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'path' => ['alias' => '/test-page'],
      'components' => [
        [
          'uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => ['heading' => $test_heading_text],
        ],
        [
          'uuid' => 'af5fc5ab-1457-4258-880f-541a69c0110b',
          'component_id' => 'block.system_branding_block',
          'inputs' => [
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
            'label_display' => '0',
            'label' => '',
          ],
        ],
      ],
    ]);
    self::assertSaveWithoutViolations($sut);
    self::assertEquals('Test page', $sut->label());
    self::assertEquals('This is a test page.', $sut->description->value);
    self::assertEquals('/test-page', $sut->get('path')->first()?->getValue()['alias']);

    $components = $sut->get('components');
    $this->assertInstanceOf(ComponentTreeItemList::class, $components);
    $hydrated_value = \Closure::bind(function () {
      return $this->getHydratedTree();
    }, $components, $components)();
    self::assertEquals(
      [
        ComponentTreeItemList::ROOT_UUID => [
          '09365c2d-1ee1-47fd-b5a3-7e4f34866186' => [
            'component' => 'sdc.canvas_test_sdc.props-slots',
            'props' => [
              'heading' => new EvaluationResult($test_heading_text),
            ],
            'slots' => [
              'the_body' => '<p>Example value for <strong>the_body</strong> slot in <strong>prop-slots</strong> component.</p>',
              'the_footer' => 'Example value for <strong>the_footer</strong>.',
              'the_colophon' => '',
            ],
          ],
          'af5fc5ab-1457-4258-880f-541a69c0110b' => [
            'component' => 'block.system_branding_block',
            'settings' => [
              'use_site_logo' => TRUE,
              'use_site_name' => TRUE,
              'use_site_slogan' => TRUE,
              'label_display' => '0',
              'label' => '',
            ],
          ],
        ],
      ],
      $hydrated_value->getTree(),
    );
    // See \Drupal\Tests\canvas\Kernel\Plugin\Field\FieldType\ComponentTreeItemTest and
    // \Drupal\Tests\canvas\Unit\PropExpressionTest for extended test coverage,
    // which combined with \Drupal\Tests\canvas\Kernel\PropSourceTest::testEntityFieldPropSource,
    // does already prove that this will work correctly for EVERYTHING.
    $dependencies = $components->calculateDependencies();
    $this->assertSame([
      'canvas.component.sdc.canvas_test_sdc.props-slots',
      'canvas.component.block.system_branding_block',
    ], $dependencies['config']);
    $this->assertSame([], $dependencies['content']);
    $this->assertSame([], $dependencies['module']);
    $this->assertSame([], $dependencies['theme']);
  }

  /**
   * Tests that getCacheTags() includes label cache tag for "new" pages.
   *
   * A page is considered "new" when it has the default title ("Untitled page")
   * or a title ending with " (Copy)".
   */
  public function testGetCacheTagsForNewPage(): void {
    $entity_type = $this->container->get('entity_type.manager')
      ->getDefinition(Page::ENTITY_TYPE_ID);
    $default_title = (string) ApiContentControllers::defaultTitle($entity_type);

    // Create a page with the default title (considered "new").
    $page = Page::create(['title' => $default_title]);
    self::assertSaveWithoutViolations($page);

    $cache_tags = $page->getCacheTags();
    $expected_label_tag = AutoSaveManager::getEntityLabelCacheTag($page);

    self::assertContains($expected_label_tag, $cache_tags);
    // Verify the standard entity cache tag is also present.
    self::assertContains('canvas_page:' . $page->id(), $cache_tags);
  }

  /**
   * Tests that getCacheTags() includes label cache tag for duplicated pages.
   *
   * Pages with titles ending in " (Copy)" are considered "new".
   */
  public function testGetCacheTagsForDuplicatedPage(): void {
    $page = Page::create(['title' => 'My Page (Copy)']);
    self::assertSaveWithoutViolations($page);

    $cache_tags = $page->getCacheTags();
    $expected_label_tag = AutoSaveManager::getEntityLabelCacheTag($page);

    self::assertContains($expected_label_tag, $cache_tags);
  }

  /**
   * Tests that getCacheTags() excludes label cache tag for published pages.
   *
   * Pages with custom titles (not default, not ending in " (Copy)") should
   * not include the auto-save label cache tag.
   */
  public function testGetCacheTagsForPublishedPage(): void {
    $page = Page::create(['title' => 'My Custom Page Title']);
    self::assertSaveWithoutViolations($page);

    $cache_tags = $page->getCacheTags();
    $label_tag = AutoSaveManager::getEntityLabelCacheTag($page);

    self::assertNotContains($label_tag, $cache_tags);
    // Verify the standard entity cache tag is still present.
    self::assertContains('canvas_page:' . $page->id(), $cache_tags);
  }

  /**
   * Tests that label() returns auto-save title for "new" pages with auto-save.
   */
  public function testLabelWithAutoSave(): void {
    $entity_type = $this->container->get('entity_type.manager')
      ->getDefinition(Page::ENTITY_TYPE_ID);
    $default_title = (string) ApiContentControllers::defaultTitle($entity_type);

    // Create a page with the default title.
    $page = Page::create(['title' => $default_title]);
    self::assertSaveWithoutViolations($page);

    // Verify the label is the default title before auto-save.
    self::assertEquals($default_title, $page->label());

    // Create an auto-save with a different title.
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    // Clone the page and change its title to simulate an auto-save.
    $auto_save_page = clone $page;
    $auto_save_page->set('title', 'My Draft Title');
    $auto_save_manager->saveEntity($auto_save_page);

    // Reload the page from storage to get a fresh instance.
    $reloaded_page = $this->container->get('entity_type.manager')
      ->getStorage(Page::ENTITY_TYPE_ID)
      ->load($page->id());
    \assert($reloaded_page instanceof Page);

    // The label should now show the auto-save title with " (Draft)" suffix.
    self::assertEquals('My Draft Title (Draft)', $reloaded_page->label());
  }

  /**
   * Tests that label() returns stored title when no auto-save exists.
   */
  public function testLabelWithoutAutoSave(): void {
    $entity_type = $this->container->get('entity_type.manager')
      ->getDefinition(Page::ENTITY_TYPE_ID);
    $default_title = (string) ApiContentControllers::defaultTitle($entity_type);

    // Create a page with the default title.
    $page = Page::create(['title' => $default_title]);
    self::assertSaveWithoutViolations($page);

    // Without an auto-save, the label should be the stored title.
    self::assertEquals($default_title, $page->label());
  }

  /**
   * Tests that label() returns stored title for non-new pages even with auto-save.
   */
  public function testLabelForNonNewPageWithAutoSave(): void {
    // Create a page with a custom title (not considered "new").
    $page = Page::create(['title' => 'Published Page']);
    self::assertSaveWithoutViolations($page);

    // Create an auto-save with a different title.
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    $auto_save_page = clone $page;
    $auto_save_page->set('title', 'Draft Title');
    $auto_save_manager->saveEntity($auto_save_page);

    // Reload the page.
    $reloaded_page = $this->container->get('entity_type.manager')
      ->getStorage(Page::ENTITY_TYPE_ID)
      ->load($page->id());
    \assert($reloaded_page instanceof Page);

    // For non-new pages, the label should be the stored title, not the auto-save.
    self::assertEquals('Published Page', $reloaded_page->label());
  }

}
