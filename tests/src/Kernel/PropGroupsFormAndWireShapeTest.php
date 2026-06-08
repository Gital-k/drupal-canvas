<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\Core\Form\FormState;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the propGroups wire shape and server-side form grouping.
 *
 * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getClientSideInfo
 * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::buildComponentInstanceForm
 * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getPropGroups
 * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getPropGroupId
 */
#[Group('canvas')]
final class PropGroupsFormAndWireShapeTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_prop_groups',
  ];

  private const COMPONENT_ID = 'sdc.canvas_test_prop_groups.grouped';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('user');
    $this->generateComponentConfig();
  }

  /**
   * Tests that getClientSideInfo() emits propGroups + per-prop `group`.
   */
  public function testWireShape(): void {
    $component = Component::load(self::COMPONENT_ID);
    $this->assertInstanceOf(ComponentInterface::class, $component);
    $source = $component->getComponentSource();
    $this->assertInstanceOf(GeneratedFieldExplicitInputUxComponentSourceBase::class, $source);

    $info = $source->getClientSideInfo($component);

    // 1) `propGroups` is present at the top level.
    $this->assertArrayHasKey('propGroups', $info);
    $this->assertSame([
      ['id' => 'content', 'title' => 'Content', 'weight' => 0, 'open' => TRUE],
      ['id' => 'styling', 'title' => 'Styling', 'weight' => 10, 'open' => TRUE],
      ['id' => 'advanced', 'title' => 'Advanced', 'weight' => 20, 'open' => FALSE],
    ], $info['propGroups']);

    // 2) Each prop carries its `group` (or NULL for ungrouped) as a sibling
    // of `jsonSchema`.
    $this->assertArrayHasKey('propSources', $info);
    $expected_groups = [
      'text' => 'content',
      'url' => 'content',
      'variant' => 'styling',
      'size' => 'styling',
      'cssClass' => 'advanced',
      'legacyTooltip' => NULL,
    ];
    foreach ($expected_groups as $prop => $expected_group) {
      $this->assertArrayHasKey($prop, $info['propSources'], "propSources contains $prop");
      $this->assertArrayHasKey('group', $info['propSources'][$prop], "propSources[$prop] has 'group' key");
      $this->assertSame($expected_group, $info['propSources'][$prop]['group']);
      // The 'group' key must be a sibling of 'jsonSchema', not nested in it.
      $this->assertArrayHasKey('jsonSchema', $info['propSources'][$prop]);
      $this->assertArrayNotHasKey('group', $info['propSources'][$prop]['jsonSchema']);
    }
  }

  /**
   * Tests that propGroups are sorted by weight, with declaration-order ties.
   */
  public function testPropGroupsSortStability(): void {
    $component = Component::load(self::COMPONENT_ID);
    $this->assertInstanceOf(ComponentInterface::class, $component);
    $source = $component->getComponentSource();
    $this->assertInstanceOf(GeneratedFieldExplicitInputUxComponentSourceBase::class, $source);
    $info = $source->getClientSideInfo($component);
    $this->assertSame(
      ['content', 'styling', 'advanced'],
      \array_column($info['propGroups'], 'id')
    );
  }

  /**
   * Tests buildComponentInstanceForm() wraps grouped props in `details` groups.
   */
  public function testFormBuildAttachesGroups(): void {
    $page = Page::create(['title' => 'For grouping form test']);
    $page->save();

    $component = Component::load(self::COMPONENT_ID);
    $this->assertInstanceOf(ComponentInterface::class, $component);
    $source = $component->getComponentSource();
    $this->assertInstanceOf(GeneratedFieldExplicitInputUxComponentSourceBase::class, $source);

    $form_state = new FormState();
    $form_state->setFormObject(new \Drupal\Tests\system\Functional\Form\StubForm(
      \Drupal\canvas\Form\ComponentInstanceForm::FORM_ID,
      []
    ));
    $form = ['#parents' => []];
    $built = $source->buildComponentInstanceForm(
      $form,
      $form_state,
      $component,
      'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      $source->getDefaultExplicitInput(),
      $page,
      $component->getSettings(),
    );

    // Each declared group becomes a `details` element with the right
    // attributes/weight.
    foreach (['content', 'styling', 'advanced'] as $group_id) {
      $this->assertArrayHasKey($group_id, $built, "form has details element for $group_id");
      $this->assertSame('details', $built[$group_id]['#type']);
      $this->assertArrayHasKey('#attributes', $built[$group_id]);
      $this->assertSame(
        $group_id,
        $built[$group_id]['#attributes']['data-canvas-prop-group']
      );
      // Group containers must have weight ≥ 100 to render below ungrouped props
      // (which sit at natural weight ≤ 0).
      $this->assertGreaterThanOrEqual(100, $built[$group_id]['#weight']);
    }
    $this->assertSame('Content', (string) $built['content']['#title']);
    $this->assertSame('Styling', (string) $built['styling']['#title']);
    $this->assertSame('Advanced', (string) $built['advanced']['#title']);
    $this->assertTrue($built['content']['#open']);
    $this->assertTrue($built['styling']['#open']);
    $this->assertFalse($built['advanced']['#open']);

    // Ordering: weight 0 -> 100, weight 10 -> 110, weight 20 -> 120.
    $this->assertSame(100, $built['content']['#weight']);
    $this->assertSame(110, $built['styling']['#weight']);
    $this->assertSame(120, $built['advanced']['#weight']);

    // Grouped props are physically nested as children of their group's
    // `details` container; ungrouped props remain at the top level.
    $expected_group_ids = [
      'text' => 'content',
      'url' => 'content',
      'variant' => 'styling',
      'size' => 'styling',
      'cssClass' => 'advanced',
    ];
    foreach ($expected_group_ids as $prop => $expected_group) {
      $this->assertArrayNotHasKey($prop, $built, "grouped prop $prop must not appear at top level");
      $this->assertArrayHasKey($prop, $built[$expected_group], "grouped prop $prop must be nested under $expected_group");
    }
    $this->assertArrayHasKey('legacyTooltip', $built);

    // After Drupal sorts children by weight, the ungrouped prop comes first,
    // followed by group containers in weight order. (Element::children with
    // $sort=TRUE returns child keys in the same order Drupal renders them in.)
    $sorted_children = \Drupal\Core\Render\Element::children($built, TRUE);
    $legacy_pos = \array_search('legacyTooltip', $sorted_children, TRUE);
    $content_pos = \array_search('content', $sorted_children, TRUE);
    $styling_pos = \array_search('styling', $sorted_children, TRUE);
    $advanced_pos = \array_search('advanced', $sorted_children, TRUE);
    $this->assertNotFalse($legacy_pos);
    $this->assertNotFalse($content_pos);
    $this->assertNotFalse($styling_pos);
    $this->assertNotFalse($advanced_pos);
    $this->assertLessThan(
      $content_pos,
      $legacy_pos,
      'Ungrouped legacyTooltip must render before all groups (weight 0 < 100).'
    );
    $this->assertLessThan($styling_pos, $content_pos);
    $this->assertLessThan($advanced_pos, $styling_pos);
  }

  /**
   * Confirms the SDC fixture is discoverable under SingleDirectoryComponent.
   */
  public function testFixtureIsDiscoverable(): void {
    $component = Component::load(self::COMPONENT_ID);
    $this->assertInstanceOf(ComponentInterface::class, $component);
    $this->assertSame(
      SingleDirectoryComponent::SOURCE_PLUGIN_ID,
      $component->getComponentSource()->getPluginId(),
    );
  }

}
