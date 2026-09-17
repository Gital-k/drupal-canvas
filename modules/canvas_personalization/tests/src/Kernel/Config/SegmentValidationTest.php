<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Kernel\Config;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Tests\canvas\Kernel\Config\BetterConfigEntityValidationTestBase;
use Drupal\Tests\canvas\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\canvas\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Segment Validation.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
class SegmentValidationTest extends BetterConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;
  use ContentTypeCreationTrait;
  use CreateTestJsComponentTrait;
  use GenerateComponentConfigTrait;
  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected bool $hasLabel = TRUE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The two only modules Drupal truly requires.
    'system',
    'user',
    // The module being tested.
    'canvas',
    'canvas_personalization',
    // Modules providing used Components (and their ComponentSource plugins).
    'block',
    'sdc_test',
    'canvas_test_sdc',
    // Canvas's dependencies (modules providing field types + widgets).
    'field',
    'file',
    'image',
    'link',
    'media',
    'node',
    'options',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->entity = Segment::create([
      'id' => 'test_segment',
      'label' => 'Test segment',
      'description' => 'Test segment description',
      'status' => TRUE,
      'rules' => [
        'current_theme' => [
          'id' => 'current_theme',
          'theme' => 'stark',
          'negate' => FALSE,
        ],
      ],
    ]);
    $this->entity->save();
    $this->installConfig('user');
  }

  protected static array $propertiesWithOptionalValues = [
    'description',
  ];

  /**
 * Tests calculate dependencies.
 */
  #[DataProvider('providerSegmentsDependencies')]
  public function testCalculateDependencies(array $rules, array $expectedDependencies): void {
    $entity = Segment::create([
      'id' => $this->randomMachineName(),
      'label' => 'Test segment',
      'description' => 'Test segment description',
      'status' => TRUE,
      'rules' => [],
    ]);
    foreach ($rules as $plugin_id => $rule) {
      $entity->addSegmentRule($plugin_id, $rule);
    }
    $entity->save();
    $this->assertSame($expectedDependencies, $entity->getDependencies());
  }

  public static function providerSegmentsDependencies(): \Generator {
    yield 'none' => [
      [],
      [],
    ];
    yield 'a module provided plugin' => [
      [
        'user_role' => [
          'id' => 'user_role',
          'roles' => ['authenticated' => 'authenticated'],
          'negate' => FALSE,
        ],
      ],
      ['module' => ['user']],
    ];
  }

  /**
   * Tests that segment rules are keyed by an allowed condition plugin ID.
   *
   * This validates the raw config data rather than the Segment config entity,
   * because \Drupal\Core\Config\Entity\ConfigEntityBase::toArray() — which
   * every config *entity* validation goes through — instantiates each rule's
   * condition plugin first. A segment naming a plugin that does not exist
   * therefore cannot even be read back as an entity; only the config data
   * remains available to inspect, and that is precisely what config import and
   * config schema checking validate.
   */
  #[DataProvider('providerSegmentRulePluginIds')]
  public function testSegmentRulePluginIds(array $rules, array $expected_messages): void {
    $data = ['rules' => $rules] + $this->entity->toArray();
    $violations = $this->container->get(TypedConfigManagerInterface::class)
      ->createFromNameAndData($this->entity->getConfigDependencyName(), $data)
      ->validate();

    $actual_messages = [];
    foreach ($violations as $violation) {
      $actual_messages[$violation->getPropertyPath()][] = (string) $violation->getMessage();
    }
    $this->assertSame($expected_messages, $actual_messages);
  }

  public static function providerSegmentRulePluginIds(): \Generator {
    yield 'condition plugins allowed for personalization' => [
      [
        'current_theme' => [
          'id' => 'current_theme',
          'theme' => 'stark',
          'negate' => FALSE,
        ],
        'user_role' => [
          'id' => 'user_role',
          'roles' => ['authenticated' => 'authenticated'],
          'negate' => FALSE,
        ],
      ],
      [],
    ];
    // This is the case Drupal core's `PluginExists` constraint would wrongly
    // accept: the condition plugin exists, it is just not one that
    // personalization allows as a segment rule.
    yield 'a condition plugin that exists but is filtered out' => [
      [
        'request_path' => [
          'id' => 'request_path',
          'pages' => '/opted-out/*',
          'negate' => FALSE,
        ],
      ],
      [
        'rules' => ["The 'request_path' plugin does not exist or is not available to the 'canvas_personalization' plugin filter consumer."],
      ],
    ];
    yield 'a condition plugin that does not exist' => [
      [
        'non_existing_plugin' => [
          'id' => 'non_existing_plugin',
          'negate' => FALSE,
        ],
      ],
      [
        'rules' => ["The 'non_existing_plugin' plugin does not exist or is not available to the 'canvas_personalization' plugin filter consumer."],
      ],
    ];
  }

  /**
 * Tests condition plugins.
 */
  #[DataProvider('providerMissingConditions')]
  public function testConditionPlugins(array $rules, string $exceptionMessage): void {
    $this->expectException(PluginNotFoundException::class);
    $this->expectExceptionMessage($exceptionMessage);
    $entity = Segment::create([
      'id' => $this->randomMachineName(),
      'label' => 'Test segment',
      'description' => 'Test segment description',
      'status' => TRUE,
      'rules' => [],
    ]);
    foreach ($rules as $plugin_id => $rule) {
      $entity->addSegmentRule($plugin_id, $rule);
    }
    $entity->save();
  }

  public static function providerMissingConditions(): \Generator {
    yield 'a non-existing plugin' => [
      [
        'non_existing_plugin' => [
          'id' => 'non_existing_plugin',
          'properties' => ['foo', 'bar'],
          'negate' => FALSE,
        ],
      ],
      'The "non_existing_plugin" plugin does not exist.',
    ];
    yield 'an opted-out existing plugin' => [
      [
        'request_path' => [
          'id' => 'request_path',
          'pages' => '/opted-out/*',
          'negate' => FALSE,
        ],
      ],
      'The "request_path" plugin does not exist.',
    ];

  }

}
