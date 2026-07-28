<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

// cspell:ignore Bienvenue savoir Découvrez Identité visuelle Charte graphique Bonjour

use Behat\Mink\Element\NodeElement;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Hook\TmgmtHooks;
use Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Traits\ComponentTreeWithAllSymmetricalTranslationEdgeCasesTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\tmgmt\Functional\TmgmtTestTrait;
use Drupal\tmgmt\Entity\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests TMGMT UI translation of a content entity with a component_tree field.
 *
 * Uses Page (not Node) because ComponentTreeFieldProcessor behavior is driven
 * entirely by field type and prop source type — neither varies by entity type.
 * Testing Node would require a ContentTemplate fixture and the extra complexity
 * is orthogonal to what this test covers.
 *
 * Contrast with ComponentTreeFieldSymmetricalTranslationSynchronizerTest, which
 * DOES test both entity types because Canvas' custom synchronizer must handle
 * both and the logic differs per entity type.
 *
 * @see \Drupal\Tests\canvas\Functional\ConfigWithComponentTreeTmgmtUiTest
 * @see \Drupal\Tests\canvas\Kernel\Translation\ComponentTreeFieldSymmetricalTranslationSynchronizerTest
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_translation')]
#[CoversClass(ComponentTreeFieldProcessor::class)]
#[CoversMethod(TmgmtHooks::class, 'fieldInfoAlter')]
class ContentWithComponentTreeTmgmtUiTest extends FunctionalTestBase {

  use ComponentTreeWithAllSymmetricalTranslationEdgeCasesTrait;
  use ConstraintViolationsTestTrait;
  use ContentTranslationTestTrait;
  use DataProviderWithComponentTreeTrait;
  use TmgmtTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_block',
    'canvas_test_sdc',
    'canvas_test_translation',
    'content_translation',
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->rebuildContainer();
    $this->drupalLogin($this->rootUser);
  }

  public function test(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['tmgmt', 'tmgmt_content', 'tmgmt_test']);
    // Rebuild necessary for TMGMT-specific field metadata changes.
    // @see \Drupal\canvas\Hook\TmgmtHooks::fieldInfoAlter()
    $this->rebuildContainer();

    // Enable content translation for canvas_page so the TMGMT content source
    // can create French translations.
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);

    // All cta1/cta1href inputs use static values: Page's component_tree does
    // not support EntityFieldPropSource or HostEntityUrlPropSource.
    $canvas_page = Page::create([
      'title' => 'TMGMT test page',
      'components' => self::populateActiveComponentVersionPlaceholders(
        self::componentTreeItems(
          cta1: 'View here',
          cta1href: ['uri' => 'https://example.com', 'options' => []],
        )
      ),
    ]);
    $violations = $canvas_page->getTypedData()->validate();
    self::assertSame([], self::violationsToArray($violations), 'canvas_page');
    $canvas_page->save();
    $page_id = $canvas_page->id();

    $translator = $this->createTranslator([
      'name' => 'test_tmgmt',
      'label' => 'Test TMGMT',
      // @see \Drupal\tmgmt_test\Plugin\tmgmt\Translator\TestTranslator
      'plugin' => 'test_translator',
      'remote_languages_mappings' => [],
      'settings' => ['key' => 'test', 'another_key' => 'test'],
    ]);
    $job = $this->createJob('en', 'fr', $this->rootUser->id(), ['translator' => $translator->id()]);
    $job_item = $job->addItem('content', Page::ENTITY_TYPE_ID, (string) $page_id);

    $job->setState(Job::STATE_ACTIVE);
    $translator->getPlugin()->requestTranslation($job);

    $this->drupalGet('admin/tmgmt/items/' . $job_item->id());
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    // Assert source texts visible for all edge cases.
    // Multiple-cardinality: each tag shown individually.
    $assert->pageTextContains('baz');
    $assert->pageTextContains('bar');
    $assert->pageTextContains('foo');
    // my-hero plain string props.
    $assert->pageTextContains('Welcome to Canvas');
    $assert->pageTextContains('View here');
    $assert->pageTextContains('https://example.com');
    $assert->pageTextContains('Learn more');
    // my-cta plain prose + URI-esque.
    $assert->pageTextContains('Press');
    $assert->pageTextContains('https://www.drupal.org');
    // Banner plain prose + rich prose.
    $assert->pageTextContains('A heading element! :)');
    $assert->pageTextContains('In a curious work, published in');
    // Block `label`s and the validatable block's `name` are empty in the source
    // language but still offered for translation: the internal ∅ sentinel is
    // never shown, an explanatory message replaces the source textarea.
    $assert->pageTextContains('This field is empty in the source language.');

    // Assert translations generated by test_translator (prefix "fr: ").
    $assert->pageTextContains('fr: baz');
    $assert->pageTextContains('fr: bar');
    $assert->pageTextContains('fr: foo');
    $assert->pageTextContains('fr: Welcome to Canvas');
    $assert->pageTextContains('fr: View here');
    $assert->pageTextContains('fr: https://example.com');
    $assert->pageTextContains('fr: Learn more');
    $assert->pageTextContains('fr: Press');
    $assert->pageTextContains('fr: https://www.drupal.org');
    $assert->pageTextContains('fr: A heading element! :)');

    // Explicitly assert all the form fields that exist per component instance,
    // to prove the generated UI matches expectations.
    self::assertSame([
      self::UUID_TAGS => [
        'components|0|tags|0[translation][value]',
        'components|0|tags|0[translation][canvas_fallback]',
        'components|0|tags|1[translation][value]',
        'components|0|tags|1[translation][canvas_fallback]',
        'components|0|tags|2[translation][value]',
        'components|0|tags|2[translation][canvas_fallback]',
      ],
      // @todo Consider respecting the defined order of props; the config_translation UI does support that.
      self::UUID_MY_HERO => [
        // SDC props populated by StaticPropSources are translatable. Each
        // plain-text row also carries a "Fallback to default translation"
        // checkbox.
        // @see \Drupal\canvas\Hook\TmgmtHooks::formTmgmtJobItemEditFormAlter()
        'components|1|heading[translation][value]',
        'components|1|heading[translation][canvas_fallback]',
        'components|1|cta1[translation][value]',
        'components|1|cta1[translation][canvas_fallback]',
        'components|1|cta2[translation][value]',
        'components|1|cta2[translation][canvas_fallback]',

        // Optional prop NOT populated in default is translatable: a translation
        // may opt to populate it even when the default translation leaves it
        // empty.
        // @see \Drupal\canvas\Tmgmt\ComponentInputsConfigProcessor::extractTranslatablesIncludingEmpty()
        'components|1|subheading[translation][value]',
        'components|1|subheading[translation][canvas_fallback]',

        'components|1|cta1href|uri[translation][value]',
        'components|1|cta1href|uri[translation][canvas_fallback]',
      ],
      self::UUID_MY_CTA => [
        'components|2|text[translation][value]',
        'components|2|text[translation][canvas_fallback]',
        'components|2|href|uri[translation][value]',
        'components|2|href|uri[translation][canvas_fallback]',
      ],
      self::UUID_BANNER => [
        'components|3|heading[translation][value]',
        'components|3|heading[translation][canvas_fallback]',
        // Rich text (text_format) rows keep TMGMT's structure and have no
        // fallback checkbox (yet).
        'components|3|text|value[translation][value]',
        // Text format is present to load CKEditor 5, but is immutable because
        // it is an `input[type=hidden]`. See the next assertion.
        'components|3|text|value[translation][format]',
      ],
      // Branding block: only `label` is translatable.
      self::UUID_BRANDING => [
        'components|4|label[translation][value]',
        'components|4|label[translation][canvas_fallback]',
      ],
      // Translatability test block: only `label` and the deeply nested `bar`
      // are translatable.
      self::UUID_BLOCK_DEEP_TRANSLATABLE => [
        'components|5|label[translation][value]',
        'components|5|label[translation][canvas_fallback]',
        'components|5|deeply_nested_translatable|0|bar[translation][value]',
        'components|5|deeply_nested_translatable|0|bar[translation][canvas_fallback]',
      ],
      // Block whose translatable `name` is empty in the source language: it is
      // still offered for translation (its source column shows a message),
      // alongside the populated `label`.
      self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT => [
        'components|6|label[translation][value]',
        'components|6|label[translation][canvas_fallback]',
        'components|6|name[translation][value]',
        'components|6|name[translation][canvas_fallback]',
      ],
      // untranslatable-prop-shapes component: no prop shape is translatable, so
      // none is offered for translation. `date` (datetime field, date-only),
      // `email`, `integer` and `boolean` are all excluded.
      self::UUID_UNTRANSLATABLE_PROP_SHAPES => [],
    ], $this->getTmgmtFormElementsForComponentInstances());
    // The "format" input exists (to load CKEditor) but is hidden, so it cannot
    // be changed.
    $assert->elementExists(
      'css',
      'input[type="hidden"][name="components|' . self::COMPONENT_DELTA[self::UUID_BANNER] . '|text|value[translation][format]"]',
    );

    // Note: all other translatables get a `fr: ` prefix by the test translator.
    $deltaHero = self::COMPONENT_DELTA[self::UUID_MY_HERO];
    $deltaCta = self::COMPONENT_DELTA[self::UUID_MY_CTA];
    $deltaBranding = self::COMPONENT_DELTA[self::UUID_BRANDING];
    $deltaDeepBlock = self::COMPONENT_DELTA[self::UUID_BLOCK_DEEP_TRANSLATABLE];
    $deltaEmptyBlock = self::COMPONENT_DELTA[self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT];
    $this->submitForm([
      "components|$deltaHero|subheading[translation][value]" => 'Découvrez Canvas',
      "components|$deltaHero|heading[translation][value]" => 'Bienvenue à Canvas',
      "components|$deltaHero|cta2[translation][value]" => 'En savoir plus',
      // Override auto-prefixed URI values to keep them valid.
      "components|$deltaHero|cta1href|uri[translation][value]" => 'https://fr.example.com',
      "components|$deltaCta|href|uri[translation][value]" => 'https://fr.drupal.org',
      // Each block's `label` is empty in the source language; translate it
      // explicitly so the test translator does not map `∅` to `fr: ∅`.
      "components|$deltaBranding|label[translation][value]" => 'Identité visuelle',
      "components|$deltaDeepBlock|label[translation][value]" => 'fr: Canvas Test Block for testing input translatability',
      "components|$deltaEmptyBlock|label[translation][value]" => 'fr: Test block with settings',
      // The block's `name` is also empty in the source language.
      "components|$deltaEmptyBlock|name[translation][value]" => 'Charte graphique',
    ], 'Save as completed');
    $assert->pageTextContains(\sprintf('The translation for %s has been accepted', $canvas_page->label()));

    $canvas_page = Page::load($page_id);
    self::assertNotNull($canvas_page);
    $fr_page = $canvas_page->getTranslation('fr');
    $fr_tree = $fr_page->getComponentTree();

    $expected_inputs = self::expectedTranslatedInputs(
      my_hero_cta1: 'fr: View here',
      my_hero_cta1href: ['uri' => 'https://fr.example.com', 'options' => []],
      expect_overrides_only: FALSE,
    );
    foreach ($expected_inputs as $uuid => $inputs) {
      $fr_item = $fr_tree->getComponentTreeItemByUuid($uuid);
      self::assertNotNull($fr_item, "Component $uuid missing from translated tree");
      $fr_inputs = $fr_item->getInputs();
      self::assertSame($inputs, $fr_inputs);
    }
  }

  /**
   * Tests empty-field translation semantics on the TMGMT review form.
   *
   * The ∅ sentinel is internal only and must never be visible to translators.
   * Each component_tree row gets a "Fallback to default translation" checkbox:
   *
   * AC1: Source has value "Welcome", fallback checked → the default
   *      translation's value ("Welcome") is rendered; ∅ must never be stored.
   * AC2: Source has value, real translation provided → translated value
   *      stored.
   * AC3: Source is empty, fallback checked → field renders the source value
   *      (also empty); ∅ must never be stored in the translation inputs.
   * AC4: Source is empty, translator provides "Bonjour" → "Bonjour" is stored
   *      and renders; source field remains empty.
   * AC5: Source has value "Sub", fallback unchecked, translation left blank →
   *      an explicitly empty string is stored (NOT the source value).
   */
  public function testEmptySetPlaceholderBehavior(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['tmgmt', 'tmgmt_content', 'tmgmt_test']);
    $this->rebuildContainer();

    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);

    $uuid_hero = 'ac1ac2ac-0000-4000-8000-000000000001';
    $uuid_block = 'ac3ac4ac-0000-4000-8000-000000000002';

    $canvas_page = Page::create([
      'title' => 'Empty-set AC test page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => $uuid_hero,
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'heading' => 'Welcome',
            // AC5: non-empty source that will be explicitly emptied in French.
            'subheading' => 'Sub',
            'cta1href' => ['uri' => 'https://example.com', 'options' => []],
            'cta2' => 'Learn more',
          ],
        ],
        [
          'uuid' => $uuid_block,
          'component_id' => 'block.canvas_test_block_input_validatable',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'label' => '',
            'label_display' => '0',
            'name' => '',
          ],
        ],
      ]),
    ]);
    $violations = $canvas_page->getTypedData()->validate();
    self::assertSame([], self::violationsToArray($violations));
    $canvas_page->save();
    $page_id = $canvas_page->id();

    $translator = $this->createTranslator([
      'name' => 'test_tmgmt_ac',
      'label' => 'Test TMGMT AC',
      'plugin' => 'test_translator',
      'remote_languages_mappings' => [],
      'settings' => ['key' => 'test', 'another_key' => 'test'],
    ]);
    $job = $this->createJob('en', 'fr', $this->rootUser->id(), ['translator' => $translator->id()]);
    $job_item = $job->addItem('content', Page::ENTITY_TYPE_ID, (string) $page_id);
    $job->setState(Job::STATE_ACTIVE);
    $translator->getPlugin()->requestTranslation($job);

    $this->drupalGet('admin/tmgmt/items/' . $job_item->id());
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    $hero_delta = 0;
    $block_delta = 1;

    // The ∅ sentinel must never be visible in the source column. Empty source
    // inputs (label, name) show an explanatory message instead of a textarea,
    // confirming they are still offered for translation.
    // (The translation column may contain ∅ here because tmgmt_test's dummy
    // translator prefixes the raw source text; real stored sentinels are
    // blanked by tmgmtDataItemTextOutputAlter and never shown.)
    $page = $this->getSession()->getPage();
    $source_cells = $page->findAll('css', 'td.tmgmt-ui-data-item-source');
    self::assertNotEmpty($source_cells);
    foreach ($source_cells as $source_cell) {
      self::assertStringNotContainsString('∅', $source_cell->getText());
    }
    $assert->pageTextContains('This field is empty in the source language.');

    // Every component_tree row has a fallback checkbox inside the translation
    // column, defaulting to unchecked.
    $assert->fieldExists("components|{$hero_delta}|heading[translation][canvas_fallback]");
    $assert->checkboxNotChecked("components|{$hero_delta}|heading[translation][canvas_fallback]");
    $assert->fieldExists("components|{$block_delta}|label[translation][canvas_fallback]");
    $assert->checkboxNotChecked("components|{$block_delta}|label[translation][canvas_fallback]");

    $translations = [
      // AC1: fallback checked — the default translation's value is rendered.
      "components|{$hero_delta}|heading[translation][canvas_fallback]" => TRUE,
      "components|{$hero_delta}|heading[translation][value]" => '',
      // AC2: real French translation for a non-empty source field.
      "components|{$hero_delta}|cta2[translation][value]" => 'En savoir plus',
      // AC5: fallback unchecked, blank — an explicitly empty translation.
      "components|{$hero_delta}|subheading[translation][canvas_fallback]" => FALSE,
      "components|{$hero_delta}|subheading[translation][value]" => '',
      // Override auto-prefixed URI to keep it valid.
      "components|{$hero_delta}|cta1href|uri[translation][value]" => 'https://fr.example.com',
      // AC3: label source is empty; fallback checked — must stay empty and ∅
      // must never be stored.
      "components|{$block_delta}|label[translation][canvas_fallback]" => TRUE,
      "components|{$block_delta}|label[translation][value]" => '',
      // AC4: name source is empty; provide a real French value.
      "components|{$block_delta}|name[translation][canvas_fallback]" => FALSE,
      "components|{$block_delta}|name[translation][value]" => 'Bonjour',
    ];

    // The fallback choice must survive a plain "Save" and a fresh page load:
    // checked rows store the internal sentinel, and the checkbox re-checks
    // from it; unchecked rows must stay unchecked.
    $this->submitForm($translations, 'Save');
    $this->drupalGet('admin/tmgmt/items/' . $job_item->id());
    $assert->checkboxChecked("components|{$hero_delta}|heading[translation][canvas_fallback]");
    $assert->checkboxChecked("components|{$block_delta}|label[translation][canvas_fallback]");
    $assert->checkboxNotChecked("components|{$hero_delta}|subheading[translation][canvas_fallback]");
    $assert->checkboxNotChecked("components|{$block_delta}|name[translation][canvas_fallback]");
    // The sentinel stored by the fallback conversion is never rendered.
    $assert->fieldValueEquals("components|{$hero_delta}|heading[translation][value]", '');

    // Validate must behave consistently with "Save as completed": no
    // component_tree empty-field error for blank (explicitly empty) or
    // fallback rows.
    $this->submitForm($translations, 'Validate');
    $assert->pageTextNotContains('The field is empty.');
    $assert->pageTextContains('Validation completed successfully.');
    // The masking of empty translations for TMGMT's validation must never
    // leak the sentinel into the re-rendered textareas.
    $assert->fieldValueEquals("components|{$hero_delta}|subheading[translation][value]", '');

    $this->submitForm($translations, 'Save as completed');
    $assert->pageTextContains(\sprintf('The translation for %s has been accepted', $canvas_page->label()));

    $canvas_page = Page::load($page_id);
    self::assertNotNull($canvas_page);
    $fr_page = $canvas_page->getTranslation('fr');
    $fr_tree = $fr_page->getComponentTree();

    $hero_item = $fr_tree->getComponentTreeItemByUuid($uuid_hero);
    self::assertNotNull($hero_item);
    $hero_inputs = $hero_item->getInputs();

    // AC1: fallback checked → the input key was dropped and refilled from the
    // default translation, so "Welcome" is rendered in French.
    self::assertSame('Welcome', $hero_inputs['heading'] ?? NULL,
      'AC1: Fallback must render the default translation value.'
    );

    // AC2: real translation stored verbatim.
    self::assertSame('En savoir plus', $hero_inputs['cta2'],
      'AC2: Provided translation must be stored verbatim.'
    );

    // AC5: fallback unchecked, blank translation → explicitly empty string is
    // stored, NOT the source value ("Sub").
    self::assertSame('', $hero_inputs['subheading'] ?? NULL,
      'AC5: A blank non-fallback translation must be stored as an explicitly empty string.'
    );

    $block_item = $fr_tree->getComponentTreeItemByUuid($uuid_block);
    self::assertNotNull($block_item);
    $block_inputs = $block_item->getInputs();

    // AC3: label source was empty and fallback was checked → falls back to
    // the source value (empty string); ∅ never stored.
    self::assertSame('', $block_inputs['label'] ?? NULL,
      'AC3: Empty-source field with fallback checked must render the source value (empty string).'
    );
    foreach ($hero_inputs + $block_inputs as $key => $value) {
      self::assertNotSame(
        '∅',
        $value,
        "The ∅ sentinel must never appear as a stored input value (key: $key)."
      );
    }

    // AC4: real French value stored for an empty-source field.
    self::assertSame('Bonjour', $block_inputs['name'],
      'AC4: A real translation for an empty-source field must be stored.'
    );
  }

  private function getTmgmtFormElementsForComponentInstances(): array {
    $page = $this->getSession()->getPage();

    $form_field_names = [];
    foreach (self::COMPONENT_DELTA as $component_instance_uuid => $delta) {
      $form_field_names[$component_instance_uuid] = \array_map(
        fn(NodeElement $n) => $n->getAttribute('name'),
        $page->findAll('css', "[name*='components|$delta'][name*='[translation]']"),
      );
    }

    return $form_field_names;
  }

}
