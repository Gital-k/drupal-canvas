<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests page region config translation UI with rich text inputs.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_translation')]
final class PageRegionConfigTranslationUiTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_sdc',
    'config_translation',
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
   * ID of the test page region.
   */
  private string $pageRegionId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('fr')->save();

    $this->drupalLogin($this->rootUser);
    $this->pageRegionId = $this->createPageRegionWithRichTextInput();
  }

  /**
   * Verifies the page region translation add form loads for rich text props.
   */
  public function testPageRegionTranslateAddFormLoads(): void {
    $this->drupalGet("/admin/appearance/page-region/{$this->pageRegionId}/translate/fr/add");
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->buttonExists('Save translation');
    $assert_session->elementExists(
      'css',
      'input[type="hidden"][value="canvas_html_block"][name*="[inputs][text][0][format]"]',
    );
  }

  /**
   * Creates a page region with a banner component that has a text_format prop.
   */
  private function createPageRegionWithRichTextInput(): string {
    $regions = PageRegion::createFromBlockLayout($this->defaultTheme);
    $new_region = reset($regions);
    \assert($new_region instanceof PageRegion);

    $entity_type_manager = \Drupal::entityTypeManager();
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $storage = $entity_type_manager->getStorage(PageRegion::ENTITY_TYPE_ID);
    $existing_region = $storage->load($new_region->id());
    $region = $existing_region instanceof PageRegion ? $existing_region : $new_region;

    $banner_component = Component::load('sdc.canvas_test_sdc.banner');
    \assert($banner_component instanceof Component);

    $region->set('component_tree', [[
      'uuid' => '11111111-1111-4111-8111-111111111111',
      'component_id' => 'sdc.canvas_test_sdc.banner',
      'component_version' => $banner_component->getActiveVersion(),
      'inputs' => [
        'heading' => 'Banner heading',
        'text' => [
          'value' => '<p>Banner text</p>',
          'format' => 'canvas_html_block',
        ],
      ],
      'label' => 'Banner',
    ]]);
    $region->enable()->save();

    return $region->id();
  }

}
