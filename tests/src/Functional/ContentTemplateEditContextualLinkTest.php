<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests that content template contextual links appear when templates exist.
 */
#[Group('canvas')]
#[CoversClass(ContentTemplate::class)]
final class ContentTemplateEditContextualLinkTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'contextual',
    'toolbar',
    'node',
    'canvas',
  ];

  /**
   * {@inheritdoc}
   *
   * @var string
   */
  protected $defaultTheme = 'claro';

  /**
   * The content template storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $contentTemplateStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
//    $account = $this->drupalCreateUser([
//      'access content',
//      'create article content',
//      'edit own article content',
//      'access contextual links',
//      'access toolbar',
//    ]);
//    assert($account instanceof AccountInterface);
    $this->drupalLogin($this->rootUser);

    $this->drupalCreateContentType(['type' => 'article']);

    $entityTypeManager = $this->container->get('entity_type.manager');
    $this->contentTemplateStorage = $entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
  }

  /**
   * Tests that contextual link is not available without a template.
   */
  public function testContextualLinkNotAvailableWithoutTemplate(): void {
    // Create an article node.
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'test1',
    ]);
    assert($node instanceof NodeInterface);

    // Visit the node page.
    $html = $this->drupalGet('node/' . $node->id());
    $crawler = new Crawler($html);

    // Find contextual links container for the article.
    $contextualLinks = $crawler->filter('article[data-contextual-id]');

    if ($contextualLinks->count() > 0) {
      // Get the contextual ID.
      $contextualId = $contextualLinks->attr('data-contextual-id');
      $this->assertNotEmpty($contextualId);

      // Fetch the contextual links via the API.
      $contextualLinksHtml = $this->drupalGet('contextual/render', [
        'query' => ['ids' => [$contextualId]],
      ]);

      // Assert that 'Edit content template' link is NOT present.
      $this->assertStringNotContainsString('Edit content template', $contextualLinksHtml);
    }
  }

  /**
   * Tests that contextual link is available when template exists.
   */
  public function testContextualLinkAvailableWithTemplate(): void {
    // Create a content template for article full view mode.
    $this->contentTemplateStorage->create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ])->save();

    // Create an article node.
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'test2',
    ]);
    assert($node instanceof NodeInterface);

    // Visit the node page.
    $html = $this->drupalGet('node/' . $node->id());
    $crawler = new Crawler($html);

    // Find contextual links container for the article.
    $contextualLinks = $crawler->filter('article[data-contextual-id]');
    $this->assertGreaterThan(0, $contextualLinks->count(), 'Article element with contextual links should be present.');

    // Get the contextual ID.
    $contextualId = $contextualLinks->attr('data-contextual-id');
    $this->assertNotEmpty($contextualId);

    // Fetch the contextual links via the API.
    $contextualLinksHtml = $this->drupalGet('contextual/render', [
      'query' => ['ids' => [$contextualId]],
    ]);

    // Assert that 'Edit content template' link IS present.
    $this->assertStringContainsString('Edit content template', $contextualLinksHtml);

    // Parse the contextual links HTML to find the template edit link.
    $contextualCrawler = new Crawler($contextualLinksHtml);
    $templateLink = $contextualCrawler->filter('a:contains("Edit content template")');
    $this->assertGreaterThan(0, $templateLink->count(), 'Edit content template link should be present.');

    // Verify the link points to the correct template edit page.
    $href = $templateLink->attr('href');
    $this->assertNotEmpty($href);
    $this->assertMatchesRegularExpression('/canvas\/template\/node\/article\/full\/\d+/', $href);
  }

}

