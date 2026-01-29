<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;

require_once \dirname(__DIR__, 3) . '/fixtures/update/intermediate_component_dependencies/common-component-tree.php';

/**
 * @covers canvas_post_update_0002_intermediate_component_dependencies_in_patterns()
 * @covers canvas_post_update_0002_intermediate_component_dependencies_in_page_regions()
 * @covers canvas_post_update_0002_intermediate_component_dependencies_in_content_templates()
 * @covers canvas_post_update_0002_intermediate_component_dependencies_in_field_config_component_trees()
 * @group canvas
 */
final class UpdateIntermediateDependenciesUpdateTest extends CanvasUpdatePathTestBase {

  use ComponentTreeItemListInstantiatorTrait;

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/intermediate_component_dependencies/add-regions.php';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/intermediate_component_dependencies/add-patterns.php';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/intermediate_component_dependencies/add-content-template.php';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/intermediate_component_dependencies/change-field_config-default_value.php';
    // Add entities WITHOUT reference field expressions (negative test cases).
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/intermediate_component_dependencies/add-regions-without-ref-expressions.php';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/intermediate_component_dependencies/add-patterns-without-ref-expressions.php';
  }

  /**
   * Tests updated intermediate dependencies.
   */
  public function testUpdatedIntermediateDependencies(): void {
    // Entities WITH reference field expressions that SHOULD be updated.
    $entities_that_should_update = [
      Pattern::ENTITY_TYPE_ID => 'a_pattern_to_be_reused',
      PageRegion::ENTITY_TYPE_ID => 'stark.sidebar_first',
      'field_config' => 'node.article.field_canvas_demo',
      ContentTemplate::ENTITY_TYPE_ID => 'node.article.reverse',
    ];
    // Entities WITHOUT reference field expressions that should NOT be updated.
    $entities_that_should_not_update = [
      Pattern::ENTITY_TYPE_ID => 'pattern_without_ref_expressions',
      PageRegion::ENTITY_TYPE_ID => 'stark.footer',
    ];

    $tracked_dependencies = [];

    // Track dependencies before update for entities that SHOULD update.
    foreach ($entities_that_should_update as $entity_type_id => $entity_id) {
      $before = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($entity_id);
      self::assertNotNull($before, "Entity $entity_type_id:$entity_id should exist before update");
      \assert($before instanceof ConfigEntityInterface);
      $tracked_dependencies['should_update'][$entity_type_id] = $before->getDependencies();
    }

    // Track dependencies before update for entities that should NOT update.
    foreach ($entities_that_should_not_update as $entity_type_id => $entity_id) {
      $before = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($entity_id);
      self::assertNotNull($before, "Entity $entity_type_id:$entity_id should exist before update");
      \assert($before instanceof ConfigEntityInterface);
      $tracked_dependencies['should_not_update'][$entity_type_id] = $before->getDependencies();
    }

    $this->runUpdates();

    $expectations_config_diff = [
      'config' => [],
      'theme' => [],
      'content' => [
        'file:file:f7e35fba-14ba-47f1-b2e1-16d2c6d70dd0',
      ],
      'module' => [],
    ];

    // Verify entities WITH reference expressions gained the intermediate dependency.
    foreach ($entities_that_should_update as $entity_type_id => $entity_id) {
      $after = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($entity_id);
      self::assertInstanceOf(ConfigEntityInterface::class, $after);
      self::assertEntityIsValid($after);
      $after->calculateDependencies();
      $dependencies_after = $after->getDependencies();
      foreach (['config', 'content', 'theme', 'module'] as $dependency_type) {
        self::assertSame(
          $expectations_config_diff[$dependency_type],
          \array_diff(
            $dependencies_after[$dependency_type] ?? [],
            $tracked_dependencies['should_update'][$entity_type_id][$dependency_type] ?? []
          ),
          "Entity $entity_type_id:$entity_id should have gained intermediate dependencies for $dependency_type"
        );
      }
    }

    // Verify entities WITHOUT reference expressions did NOT gain the intermediate dependency.
    foreach ($entities_that_should_not_update as $entity_type_id => $entity_id) {
      $after = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($entity_id);
      self::assertInstanceOf(ConfigEntityInterface::class, $after);
      self::assertEntityIsValid($after);
      $after->calculateDependencies();
      $dependencies_after = $after->getDependencies();
      // Verify the intermediate file dependency was NOT added.
      self::assertNotContains(
        'file:file:f7e35fba-14ba-47f1-b2e1-16d2c6d70dd0',
        $dependencies_after['content'] ?? [],
        "Entity $entity_type_id:$entity_id should NOT have gained the intermediate file dependency"
      );
    }
  }

}
