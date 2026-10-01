<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\EntityHandlers\ContentTemplateListBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Canvas page and content template listings in the CMS back end.
 *
 * @legacy-covers \Drupal\canvas\Entity\Page
 */
#[CoversClass(ContentTemplateListBuilder::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class CanvasPageListTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'system',
    // Ensures local tasks display.
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('local_actions_block');
  }

  /**
   * Tests the admin page.
   */
  public function testCanvasContentListPage(): void {
    $page = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'path' => ['alias' => '/test-page'],
    ]);
    $page->save();

    $account = $this->drupalCreateUser([
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      Page::DELETE_PERMISSION,
      // For other collections to ensure local tasks show.
      'access content overview',
    ]);
    $this->assertInstanceOf(AccountInterface::class, $account);
    $this->drupalLogin($account);
    $this->drupalGet(Url::fromRoute('entity.canvas_page.collection'));

    $assert = $this->assertSession();

    $assert->linkByHrefExists(Url::fromRoute('system.admin_content')->toString());
    $assert->linkByHrefExists(Url::fromRoute('entity.canvas_page.collection')->toString());

    $assert->linkByHrefExists('/admin/content/pages/add?token=');

    $assert->linkByHrefExists($page->toUrl('canonical')->toString());
    $assert->linkByHrefExists($page->toUrl('edit-form')->toString());
    $assert->linkByHrefExists($page->toUrl('delete-form')->toString());

    // Without the content template permission, the Templates tab is absent.
    $assert->linkByHrefNotExists(Url::fromRoute('entity.content_template.collection')->toString());
  }

  /**
   * Tests the content template listing next to the page listing.
   */
  public function testContentTemplateListPage(): void {
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => TRUE,
    ])->save();
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'teaser',
      'component_tree' => [],
      'status' => FALSE,
    ])->save();

    $collection_url = Url::fromRoute('entity.content_template.collection');
    $this->assertSame('/admin/content/templates', $collection_url->toString());

    $account = $this->drupalCreateUser([
      Page::EDIT_PERMISSION,
      // For other collections to ensure local tasks show.
      'access content overview',
    ]);
    $this->assertInstanceOf(AccountInterface::class, $account);
    $this->drupalLogin($account);
    $assert = $this->assertSession();
    $this->drupalGet($collection_url);
    $assert->statusCodeEquals(403);

    $account = $this->drupalCreateUser([
      ContentTemplate::ADMIN_PERMISSION,
      Page::EDIT_PERMISSION,
      'access content overview',
    ]);
    $this->assertInstanceOf(AccountInterface::class, $account);
    $this->drupalLogin($account);

    // The Templates tab is next to the Pages tab.
    $this->drupalGet(Url::fromRoute('entity.canvas_page.collection'));
    $this->clickLink('Templates');
    $assert->statusCodeEquals(200);
    $assert->addressEquals($collection_url);
    $assert->linkByHrefExists(Url::fromRoute('system.admin_content')->toString());
    $assert->linkByHrefExists(Url::fromRoute('entity.canvas_page.collection')->toString());

    $assert->elementTextEquals('css', 'table tbody tr:nth-child(1) td:nth-child(1)', 'Article content items — Full content view');
    $assert->elementTextEquals('css', 'table tbody tr:nth-child(1) td:nth-child(2)', 'Enabled');
    $assert->elementTextEquals('css', 'table tbody tr:nth-child(2) td:nth-child(1)', 'Article content items — Teaser view');
    $assert->elementTextEquals('css', 'table tbody tr:nth-child(2) td:nth-child(2)', 'Disabled');
    $assert->linkByHrefExists(Url::fromUri('base:canvas/template/node/article/full')->toString());
    $assert->linkByHrefExists(Url::fromUri('base:canvas/template/node/article/teaser')->toString());
    $assert->buttonNotExists('Reset');

    $this->submitForm(['search' => 'teaser'], 'Apply');
    $assert->addressEquals($collection_url->setOption('query', ['search' => 'teaser']));
    $assert->elementsCount('css', 'table tbody tr', 1);
    $assert->elementTextEquals('css', 'table tbody tr:nth-child(1) td:nth-child(1)', 'Article content items — Teaser view');
    $assert->fieldValueEquals('search', 'teaser');

    $this->submitForm(['search' => '', 'status' => '1'], 'Apply');
    $assert->elementsCount('css', 'table tbody tr', 1);
    $assert->elementTextEquals('css', 'table tbody tr:nth-child(1) td:nth-child(2)', 'Enabled');

    $this->submitForm(['search' => 'teaser', 'status' => '1'], 'Apply');
    $assert->pageTextContains('No content templates match the filters.');

    $this->submitForm([], 'Reset');
    $assert->addressEquals(Url::fromRoute('entity.content_template.collection'));
    $assert->elementsCount('css', 'table tbody tr', 2);
  }

}
