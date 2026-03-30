<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies that ContentTemplate language overrides render at prefixed URLs.
 *
 * This functional test complements the kernel-level test by going through
 * the full HTTP request stack — routing, URL-based language negotiation,
 * config factory override resolution, entity rendering — and asserting that
 * the correct component tree is rendered in the response body for each
 * language-prefixed URL.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ContentTemplateConfigTranslationFunctionalTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'language',
    'config_translation',
    'content_translation',
    'locale',
    'canvas',
    'canvas_dev_translation',
    'canvas_test_sdc',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The node whose URL is used to trigger ContentTemplate rendering.
   *
   * @var \Drupal\node\NodeInterface
   */
  private $node;

  /**
   * The config override factory service.
   *
   * @var \Drupal\language\Config\LanguageConfigFactoryOverride
   */
  private $overrideFactory;

  /**
   * The config name for the ContentTemplate under test.
   */
  private const CONFIG_NAME = 'canvas.content_template.node.article.full';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Add French as a second content language.
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Enable URL-based (path prefix) language negotiation so that /fr/node/N
    // resolves to French.
    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->save();
    \Drupal::service('router.builder')->rebuild();

    // Create the article content type and enable content translation on it,
    // so that language-prefixed node URLs resolve to the node.
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    \Drupal::service('content_translation.manager')
      ->setEnabled('node', 'article', TRUE);
    \Drupal::entityTypeManager()->clearCachedDefinitions();

    // Create a published English article.
    $this->node = $this->drupalCreateNode([
      'type'     => 'article',
      'title'    => 'EN article',
      'langcode' => 'en',
      'status'   => 1,
    ]);

    $this->overrideFactory = $this->container->get('language.config_factory_override');
  }
  
  /**
   * Tests that a French config override is rendered at the /fr node URL.
   *
   * This is the primary proof-of-concept test: once the implementation in
   * canvas_dev_translation correctly marks component_tree as translatable and
   * the config factory override is applied during request handling, the French
   * component tree must appear at /fr/node/{id} and the English component tree
   * must appear at /node/{id}.
   */
  public function testFrenchOverrideIsRenderedAtFrenchUrl(): void {
    $this->createAndSaveTemplate($this->enTree());
    $this->saveFrOverride($this->frTree());

    // English URL → English sentinel.
    $this->drupalGet('node/' . $this->node->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->enSentinel());
    $this->assertSession()->pageTextNotContains($this->frSentinel());

    // French URL → French sentinel.
    $this->drupalGet('fr/node/' . $this->node->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->frSentinel());
    $this->assertSession()->pageTextNotContains($this->enSentinel());
  }

  /**
   * Tests that deleting a French override falls to the default component tree.
   *
   * After the override is deleted the French URL must render the same default
   * (English) component tree that /node/{id} renders.
   */
  public function testDeletingFrenchOverrideFallsBackToDefault(): void {
    $this->createAndSaveTemplate($this->enTree());
    $override = $this->saveFrOverride($this->frTree());

    // Confirm override is initially applied.
    $this->drupalGet('fr/node/' . $this->node->id());
    $this->assertSession()->pageTextContains($this->frSentinel());

    // Delete the override.
    $override->delete();

    // French URL must now fall back to the default (EN) tree.
    $this->drupalGet('fr/node/' . $this->node->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->enSentinel());
    $this->assertSession()->pageTextNotContains($this->frSentinel());
  }

  /**
   * Tests that EN, FR and DE overrides are fully independent of each other.
   *
   * Changing one language's component tree must not bleed into another
   * language's rendering.
   */
  public function testMultipleLanguageOverridesAreIndependent(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    $this->createAndSaveTemplate($this->enTree());
    $this->saveFrOverride($this->frTree());
    $this->overrideFactory
      ->getOverride('de', self::CONFIG_NAME)
      ->setData(['component_tree' => $this->buildTree('DE sentinel text')])
      ->save();

    // EN URL → EN tree only.
    $this->drupalGet('node/' . $this->node->id());
    $this->assertSession()->pageTextContains($this->enSentinel());
    $this->assertSession()->pageTextNotContains($this->frSentinel());
    $this->assertSession()->pageTextNotContains('DE sentinel text');

    // FR URL → FR tree only.
    $this->drupalGet('fr/node/' . $this->node->id());
    $this->assertSession()->pageTextContains($this->frSentinel());
    $this->assertSession()->pageTextNotContains($this->enSentinel());
    $this->assertSession()->pageTextNotContains('DE sentinel text');

    // DE URL → DE tree only.
    $this->drupalGet('de/node/' . $this->node->id());
    $this->assertSession()->pageTextContains('DE sentinel text');
    $this->assertSession()->pageTextNotContains($this->enSentinel());
    $this->assertSession()->pageTextNotContains($this->frSentinel());
  }

  /**
   * Tests that updating a French override is reflected at the French URL.
   *
   * The first override write, and a subsequent in-place update, must each
   * produce their respective sentinel in the response — no stale rendering
   * from a previous override.
   */
  public function testUpdatingFrenchOverrideIsReflected(): void {
    $this->createAndSaveTemplate($this->enTree());
    $this->saveFrOverride($this->frTree());

    $this->drupalGet('fr/node/' . $this->node->id());
    $this->assertSession()->pageTextContains($this->frSentinel());

    // Write a new FR override with a different sentinel.
    $this->overrideFactory
      ->getOverride('fr', self::CONFIG_NAME)
      ->setData(['component_tree' => $this->buildTree('FR updated sentinel')])
      ->save();

    $this->drupalGet('fr/node/' . $this->node->id());
    $this->assertSession()->pageTextContains('FR updated sentinel');
    $this->assertSession()->pageTextNotContains($this->frSentinel());
    $this->assertSession()->pageTextNotContains($this->enSentinel());
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Creates (or reloads) the node.article.full ContentTemplate and saves it.
   *
   * @param list<array<string, mixed>> $componentTree
   *   The component tree to store in the ContentTemplate entity.
   *
   * @return \Drupal\canvas\Entity\ContentTemplate
   *   The saved ContentTemplate entity.
   */
  private function createAndSaveTemplate(array $componentTree): ContentTemplate {
    $storage  = $this->container->get('entity_type.manager')
      ->getStorage('content_template');
    $template = $storage->load('node.article.full');
    if ($template === NULL) {
      $template = ContentTemplate::create([
        'id'                            => 'node.article.full',
        'content_entity_type_id'        => 'node',
        'content_entity_type_bundle'    => 'article',
        'content_entity_type_view_mode' => 'full',
        'component_tree'                => $componentTree,
      ]);
    }
    else {
      assert($template instanceof ContentTemplate);
      $template->set('component_tree', $componentTree);
    }
    $template->setStatus(TRUE)->save();
    return $template;
  }

  /**
   * Saves a French config language override.
   *
   * This creates or updates the override and returns it so callers can delete
   * it if needed.
   *
   * @param list<array<string, mixed>> $componentTree
   *   The component tree to store in the French override.
   *
   * @return \Drupal\language\Config\LanguageConfigOverride
   *   The saved French language config override entity.
   */
  private function saveFrOverride(array $componentTree): LanguageConfigOverride {
    /** @var \Drupal\language\Config\LanguageConfigOverride $override */
    $override = $this->overrideFactory
      ->getOverride('fr', self::CONFIG_NAME)
      ->setData(['component_tree' => $componentTree]);
    $override->save();
    return $override;
  }

  /**
   * Builds a minimal component tree whose rendered output contains $sentinel.
   *
   * The tree structure mirrors what ContentTemplateConfigTranslationTest uses
   * so the two test files stay aligned. Adjust the component ID and prop key
   * to match whatever canvas_test_sdc exposes.
   *
   * @param string $sentinel
   *   A unique string that must appear in the rendered HTML of this tree.
   *
   * @return list<array<string, mixed>>
   *   The component tree array ready for saving in a ContentTemplate.
   */
  private function buildTree(string $sentinel): array {
    return [
      [
        'uuid'              => \Drupal::service('uuid')->generate(),
        'component_id'      => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs'            => [
          'heading' => $sentinel,
        ],
      ],
    ];
  }

  /**
   * Returns the English sentinel string.
   */
  private function enSentinel(): string {
    return 'EN sentinel text';
  }

  /**
   * Returns the French sentinel string.
   */
  private function frSentinel(): string {
    return 'FR sentinel text';
  }

  /**
   * Returns the English component tree.
   *
   * @return list<array<string, mixed>>
   *   The component tree array for English.
   */
  private function enTree(): array {
    return $this->buildTree($this->enSentinel());
  }

  /**
   * Returns the French component tree.
   *
   * @return list<array<string, mixed>>
   *   The component tree array for French.
   */
  private function frTree(): array {
    return $this->buildTree($this->frSentinel());
  }

}
