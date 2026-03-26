<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Regression test + failing spec for the stale component version crash.
 *
 * The crash (#3571366): a recipe authored on an environment with only one
 * image media type encodes that environment's component version hash in
 * Pattern/PageRegion/ContentTemplate config. When that recipe is applied to a
 * site that was installed fresh with an additional image media type (e.g.
 * Drupal CMS, which ships svg_image), the component on the target site was
 * created with a different hash — the recipe's hash was never valid there —
 * and RecipeConfigInstaller throws a validation error.
 *
 * This class contains two assertions inside the same test method:
 *   1. A stale version triggers a violation (proves the bug exists).
 *   2. A null version triggers no violation (proves the fix works).
 *
 * @see https://www.drupal.org/project/canvas/issues/3571366
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
class StaleComponentVersionTest extends CanvasKernelTestBase {

  use ConstraintViolationsTestTrait;
  use GenerateComponentConfigTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('path_alias');
    $this->installSchema('file', ['file_usage']);
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  /**
   * Reproduces the crash and verifies the fix.
   *
   * Simulates the cross-environment scenario from #3571366:
   * - "Recipe author" environment: only the 'image' media type.
   * - "Target site" environment: 'image' + a second image media type (e.g.
   *   svg_image on Drupal CMS).
   *
   * The component version hash encodes the set of allowed target_bundles for
   * image entity-reference fields. Adding a second image-source media type
   * changes target_bundles, producing a different hash. Because the target
   * site was installed from scratch with both types, its component config
   * entity only ever knew the two-bundle hash — the recipe's one-bundle hash
   * was never valid there.
   *
   */
  public function testPatternComponentVersionBecomesStaleAcrossEnvironments(): void {
    // ── "Recipe author" environment ──────────────────────────────────────────
    // One image media type only. generateComponentConfig() creates the
    // sdc.canvas_test_sdc.image Component config entity from scratch; it will
    // have exactly one version hash that encodes a single target_bundle.
    $this->createMediaType('image', ['id' => 'image']);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['canvas_test_sdc:image']);

    $component_id = 'sdc.canvas_test_sdc.image';
    $component = Component::load($component_id);
    $this->assertNotNull($component, 'Component exists after first generateComponentConfig().');
    $stale_version = $component->getActiveVersion();

    // Delete the component entity so the next generateComponentConfig() call
    // creates it fresh — exactly as it would on a brand-new Drupal CMS
    // installation that always had both image media types installed.
    // (Without the delete, calling generateComponentConfig() again would only
    // *add* a second version via createVersion(), leaving the first version
    // valid. The real-world crash occurs because the target site's component
    // was *never* at the recipe's version.)
    $component->delete();

    // ── "Target site" environment ─────────────────────────────────────────────
    // A second image-source media type is present from the start (e.g. the
    // svg_image type that Drupal CMS adds). generateComponentConfig() creates
    // the component fresh with target_bundles covering both types.
    $this->createMediaType('image', ['id' => 'svg_image']);
    $this->container->get(PropShapeRepositoryInterface::class)->reset();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['canvas_test_sdc:image']);

    $component = Component::load($component_id);
    $this->assertNotNull($component, 'Component exists after second generateComponentConfig().');
    $current_version = $component->getActiveVersion();

    // The hash must differ: adding a second image-source media type changes the
    // target_bundles encoded in prop_field_definitions, which changes the hash.
    $this->assertNotSame(
      $stale_version,
      $current_version,
      'Component version changes when a second image media type is added.',
    );

    // ── Cross-environment failure ─────────────────────────────────────────────
    // Simulate what RecipeConfigInstaller does when it imports a Pattern config
    // that was built on the "one media type" environment: component_version
    // references $stale_version, which the target site's component has never
    // had. This reproduces andyg's crash at RecipeConfigInstaller.php line 70.
    $pattern = Pattern::create([
      'id' => 'stale_version_test',
      'label' => 'Stale version test',
      'component_tree' => [
        [
          'uuid' => '3fa8e6f6-4b5e-4d0b-ae47-78b2e0fcda58',
          'component_id' => $component_id,
          'component_version' => $stale_version,
          'inputs' => [],
        ],
      ],
    ]);

    // ── This is the crash ─────────────────────────────────────────────────────
    // ValidConfigEntityVersionConstraintValidator looks up the component and
    // checks whether $stale_version is in getVersions(). It is not — the
    // component was freshly created with $current_version only — so a violation
    // is added.  RecipeConfigInstaller surfaces this as a fatal exception.
    $violations = self::violationsToArray($pattern->getTypedData()->validate());

    $this->assertArrayHasKey(
      'component_tree.0.component_version',
      $violations,
      'A violation is raised for the stale component_version.',
    );
    $this->assertStringContainsString(
      $stale_version,
      $violations['component_tree.0.component_version'],
      'The violation message identifies the stale version hash.',
    );

    // ── The fix: resolve component_version: null to the active version ────────
    // Recipe authors should not have to know or encode a version hash that is
    // environment-specific. The fix (#3571366) lets them omit the version
    // entirely (null); ComponentTreeConfigEntityBase::preCreate() resolves it
    // to the current active version before the entity is constructed, so stored
    // config is always concrete and ValidConfigEntityVersionConstraint never fires.
    $pattern = Pattern::create([
      'id' => 'null_version_test',
      'label' => 'Null version test',
      'component_tree' => [
        [
          'uuid' => '3fa8e6f6-4b5e-4d0b-ae47-78b2e0fcda58',
          'component_id' => $component_id,
          'component_version' => NULL,
          'inputs' => [],
        ],
      ],
    ]);

    $violations = self::violationsToArray($pattern->getTypedData()->validate());
    self::assertArrayNotHasKey(
      'component_tree.0.component_version',
      $violations,
      'A null component_version must not trigger a version violation.',
    );

  }

}
