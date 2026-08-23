<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies the shared authored metadata fixtures against the published schema.
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
class ComponentMetadataSchemaParityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES;

  /**
   * Tests a shared metadata fixture.
   */
  #[DataProvider('providerFixtures')]
  public function testFixture(string $fixture_path, bool $expected_valid): void {
    $module_path = $this->container->get(ModuleExtensionList::class)->getPath('canvas');
    $schema = json_decode((string) file_get_contents("$module_path/component-metadata.schema.json"), flags: JSON_THROW_ON_ERROR);
    $document = Validator::arrayToObjectRecursive(Yaml::parseFile("$module_path/$fixture_path"));

    $validator = new Validator();
    $validator->validate($document, $schema, Constraint::CHECK_MODE_NORMAL);

    $this->assertSame($expected_valid, $validator->isValid(), json_encode($validator->getErrors(), JSON_PRETTY_PRINT));
  }

  /**
   * Provides shared valid and invalid fixtures.
   */
  public static function providerFixtures(): iterable {
    $module_path = dirname(__DIR__, 4);
    foreach ([TRUE => 'valid', FALSE => 'invalid'] as $expected_valid => $directory) {
      foreach (glob("$module_path/tests/fixtures/component-metadata/$directory/*.yml") ?: [] as $fixture_path) {
        $relative_path = substr($fixture_path, \strlen($module_path) + 1);
        yield "$directory: " . basename($fixture_path) => [$relative_path, (bool) $expected_valid];
      }
    }
  }

}
