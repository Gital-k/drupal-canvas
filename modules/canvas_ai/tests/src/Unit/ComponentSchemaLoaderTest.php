<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Unit;

use Drupal\canvas_ai\Service\ComponentSchemaLoader;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the ComponentSchemaLoader service.
 *
 * Uses reflection to invoke processComponentFile on temporary YAML files,
 * bypassing the theme path resolution and glob discovery.
 *
 * @group canvas_ai
 * @coversDefaultClass \Drupal\canvas_ai\Service\ComponentSchemaLoader
 */
#[CoversClass(ComponentSchemaLoader::class)]
#[Group('canvas')]
#[Group('canvas_direct_edit')]
final class ComponentSchemaLoaderTest extends UnitTestCase {

  /**
   * Temporary directory for component YAML fixtures.
   *
   * @var string
   */
  private string $tmpDir;

  /**
   * The mock cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private CacheBackendInterface $cache;

  /**
   * The mock logger.
   *
   * @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->tmpDir = sys_get_temp_dir() . '/canvas_ai_test_' . uniqid();
    mkdir($this->tmpDir, 0777, TRUE);

    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    // Cache always misses so buildMaps() runs each time.
    $this->cache->method('get')->willReturn(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDir($this->tmpDir);
    parent::tearDown();
  }

  /**
   * Recursively removes a directory.
   */
  private function removeDir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    $items = scandir($dir);
    if ($items === FALSE) {
      return;
    }
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;
      is_dir($path) ? $this->removeDir($path) : unlink($path);
    }
    rmdir($dir);
  }

  /**
   * Builds a ComponentSchemaLoader populated from fixture YAML files.
   *
   * @param array<string, array<string, mixed>> $components
   *   Map of component_dir_name => props properties array.
   *
   * @return \Drupal\canvas_ai\Service\ComponentSchemaLoader
   *   The loader instance with maps populated via reflection.
   */
  private function buildLoader(array $components): ComponentSchemaLoader {
    $themeHandler = $this->createMock(ThemeHandlerInterface::class);
    $themeHandler->method('getDefault')->willReturn('byte_theme');
    $themeList = $this->createMock(ThemeExtensionList::class);
    $configObj = $this->createMock(ImmutableConfig::class);
    $configObj->method('get')->willReturnCallback(function ($key) {
      if ($key === 'enum_value_aliases') {
        return [
          'inverted' => ['white', 'light'],
          'primary' => ['blue', 'brand'],
          'secondary' => ['grey', 'gray'],
          'center' => ['centered', 'middle'],
          'left' => ['start'],
          'right' => ['end'],
          'large' => ['big'],
          'small' => ['tiny'],
          'medium' => ['mid'],
        ];
      }
      return NULL;
    });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('canvas_ai.direct_edit.settings')->willReturn($configObj);
    $loader = new ComponentSchemaLoader($themeList, $themeHandler, $this->cache, $this->logger, $configFactory);

    // Create temporary YAML files and invoke processComponentFile via
    // reflection.
    $reflection = new \ReflectionClass($loader);

    // Initialize the internal arrays.
    $arrayProps = [
      'propAliases',
      'enumValues',
      'reverseEnumIndex',
      'booleanProps',
      'enumOrdinals',
      'integerEnums',
      'reverseAliasIndex',
    ];
    foreach ($arrayProps as $prop) {
      $rp = $reflection->getProperty($prop);
      $rp->setValue($loader, []);
    }

    $method = $reflection->getMethod('processComponentFile');

    foreach ($components as $dirName => $properties) {
      $componentDir = $this->tmpDir . '/' . $dirName;
      mkdir($componentDir, 0777, TRUE);

      $yamlData = [
        'name' => $dirName,
        'props' => [
          'properties' => $properties,
        ],
      ];
      $yamlPath = $componentDir . '/' . $dirName . '.component.yml';
      file_put_contents($yamlPath, Yaml::dump($yamlData, 6));

      $method->invoke($loader, $yamlPath, 'byte_theme');
    }

    return $loader;
  }

  /**
   * @covers ::getReverseEnumIndex
   */
  public function testReverseEnumIndexUnambiguous(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'inverted', 'primary'],
        ],
        'align' => [
          'type' => 'string',
          'enum' => ['left', 'center', 'right'],
        ],
      ],
    ]);

    $index = $loader->getReverseEnumIndex('sdc.byte_theme.heading');

    // Each value maps to exactly 1 prop (no collisions).
    $this->assertSame(['text_color'], $index['default']);
    $this->assertSame(['text_color'], $index['inverted']);
    $this->assertSame(['text_color'], $index['primary']);
    $this->assertSame(['align'], $index['left']);
    $this->assertSame(['align'], $index['center']);
    $this->assertSame(['align'], $index['right']);
  }

  /**
   * @covers ::getReverseEnumIndex
   */
  public function testReverseEnumIndexWithCollision(): void {
    $loader = $this->buildLoader([
      'card-icon' => [
        'background_color' => [
          'type' => 'string',
          'enum' => ['default', 'primary', 'secondary'],
        ],
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'primary', 'inverted'],
        ],
      ],
    ]);

    $index = $loader->getReverseEnumIndex('sdc.byte_theme.card-icon');

    // 'primary' maps to both props.
    $this->assertContains('background_color', $index['primary']);
    $this->assertContains('text_color', $index['primary']);
    $this->assertCount(2, $index['primary']);

    // 'default' also collides.
    $this->assertCount(2, $index['default']);

    // 'secondary' is unambiguous.
    $this->assertSame(['background_color'], $index['secondary']);

    // 'inverted' is unambiguous.
    $this->assertSame(['text_color'], $index['inverted']);
  }

  /**
   * @covers ::getReverseEnumIndex
   */
  public function testReverseEnumIndexEmptyForUnknownComponent(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'text_color' => [
          'type' => 'string',
          'enum' => ['default'],
        ],
      ],
    ]);

    $this->assertSame([], $loader->getReverseEnumIndex('sdc.byte_theme.nonexistent'));
  }

  /**
   * @covers ::getBooleanProps
   */
  public function testBooleanPropsDetected(): void {
    $loader = $this->buildLoader([
      'section' => [
        'section_header' => [
          'type' => 'boolean',
        ],
        'section_footer' => [
          'type' => 'boolean',
        ],
        'overlap_navbar' => [
          'type' => 'boolean',
        ],
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'inverted'],
        ],
      ],
    ]);

    $boolProps = $loader->getBooleanProps('sdc.byte_theme.section');

    // section_header and section_footer are non-inverted.
    $this->assertArrayHasKey('section_header', $boolProps);
    $this->assertFalse($boolProps['section_header']['inverted']);
    $this->assertContains('section_header', $boolProps['section_header']['aliases']);

    $this->assertArrayHasKey('section_footer', $boolProps);
    $this->assertFalse($boolProps['section_footer']['inverted']);

    // overlap_navbar is inverted.
    $this->assertArrayHasKey('overlap_navbar', $boolProps);
    $this->assertTrue($boolProps['overlap_navbar']['inverted']);

    // text_color is NOT a boolean prop.
    $this->assertArrayNotHasKey('text_color', $boolProps);
  }

  /**
   * @covers ::getBooleanProps
   */
  public function testBooleanPropsDisabledIsInverted(): void {
    $loader = $this->buildLoader([
      'widget' => [
        'disabled' => [
          'type' => 'boolean',
        ],
        'visible' => [
          'type' => 'boolean',
        ],
      ],
    ]);

    $boolProps = $loader->getBooleanProps('sdc.byte_theme.widget');
    $this->assertTrue($boolProps['disabled']['inverted']);
    $this->assertFalse($boolProps['visible']['inverted']);
  }

  /**
   * @covers ::getBooleanProps
   */
  public function testBooleanPropsEmptyForUnknownComponent(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'text_color' => [
          'type' => 'string',
          'enum' => ['default'],
        ],
      ],
    ]);

    $this->assertSame([], $loader->getBooleanProps('sdc.byte_theme.nonexistent'));
  }

  /**
   * @covers ::getEnumOrdinals
   */
  public function testEnumOrdinalsAscendingDefault(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'inverted', 'primary'],
        ],
        'align' => [
          'type' => 'string',
          'enum' => ['left', 'center', 'right'],
        ],
      ],
    ]);

    $ordinals = $loader->getEnumOrdinals('sdc.byte_theme.heading');

    $this->assertSame(['default', 'inverted', 'primary'], $ordinals['text_color']['values']);
    $this->assertSame('ascending', $ordinals['text_color']['direction']);

    $this->assertSame(['left', 'center', 'right'], $ordinals['align']['values']);
    $this->assertSame('ascending', $ordinals['align']['direction']);
  }

  /**
   * @covers ::getEnumOrdinals
   */
  public function testEnumOrdinalsDescendingForSizeProps(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'text_size' => [
          'type' => 'string',
          'enum' => ['heading-responsive-8xl', 'heading-responsive-7xl', 'heading-responsive-6xl'],
        ],
      ],
    ]);

    $ordinals = $loader->getEnumOrdinals('sdc.byte_theme.heading');

    $this->assertSame(
      ['heading-responsive-8xl', 'heading-responsive-7xl', 'heading-responsive-6xl'],
      $ordinals['text_size']['values']
    );
    $this->assertSame('descending', $ordinals['text_size']['direction']);
  }

  /**
   * @covers ::getEnumOrdinals
   */
  public function testEnumOrdinalsDescendingForAllSizeCategories(): void {
    $loader = $this->buildLoader([
      'icon' => [
        'icon_size' => [
          'type' => 'string',
          'enum' => ['extra-large', 'large', 'medium', 'small'],
        ],
        'size' => [
          'type' => 'string',
          'enum' => ['large', 'medium', 'small'],
        ],
      ],
      'tile' => [
        'tile_size' => [
          'type' => 'string',
          'enum' => ['16/9', '4/3', '1/1'],
        ],
        'image_size' => [
          'type' => 'string',
          'enum' => ['large', 'medium', 'small'],
        ],
      ],
    ]);

    $iconOrdinals = $loader->getEnumOrdinals('sdc.byte_theme.icon');
    $this->assertSame('descending', $iconOrdinals['icon_size']['direction']);
    $this->assertSame('descending', $iconOrdinals['size']['direction']);

    $tileOrdinals = $loader->getEnumOrdinals('sdc.byte_theme.tile');
    $this->assertSame('descending', $tileOrdinals['tile_size']['direction']);
    $this->assertSame('descending', $tileOrdinals['image_size']['direction']);
  }

  /**
   * @covers ::getEnumOrdinals
   */
  public function testEnumOrdinalsSkipsNumericOnlyEnums(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'level' => [
          'type' => 'integer',
          'enum' => [1, 2, 3, 4, 5, 6],
        ],
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'inverted'],
        ],
      ],
    ]);

    $ordinals = $loader->getEnumOrdinals('sdc.byte_theme.heading');

    // Integer-typed enums are skipped (stored separately via
    // getIntegerEnumValues).
    $this->assertArrayNotHasKey('level', $ordinals);
    // String enums are present.
    $this->assertArrayHasKey('text_color', $ordinals);
  }

  /**
   * @covers ::getEnumOrdinals
   */
  public function testEnumOrdinalsEmptyForUnknownComponent(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'text_color' => [
          'type' => 'string',
          'enum' => ['default'],
        ],
      ],
    ]);

    $this->assertSame([], $loader->getEnumOrdinals('sdc.byte_theme.nonexistent'));
  }

  /**
   * @covers ::getOrthogonalityReport
   */
  public function testOrthogonalityReportOrthogonalComponent(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'inverted', 'primary'],
        ],
        'align' => [
          'type' => 'string',
          'enum' => ['left', 'center', 'right'],
        ],
      ],
    ]);

    $report = $loader->getOrthogonalityReport();

    $this->assertArrayHasKey('sdc.byte_theme.heading', $report);
    $this->assertTrue($report['sdc.byte_theme.heading']['orthogonal']);
    $this->assertEmpty($report['sdc.byte_theme.heading']['collisions']);
  }

  /**
   * @covers ::getOrthogonalityReport
   */
  public function testOrthogonalityReportWithCollisions(): void {
    $loader = $this->buildLoader([
      'card-icon' => [
        'background_color' => [
          'type' => 'string',
          'enum' => ['default', 'primary', 'secondary'],
        ],
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'primary', 'inverted'],
        ],
      ],
    ]);

    $report = $loader->getOrthogonalityReport();

    $this->assertArrayHasKey('sdc.byte_theme.card-icon', $report);
    $this->assertFalse($report['sdc.byte_theme.card-icon']['orthogonal']);
    $this->assertNotEmpty($report['sdc.byte_theme.card-icon']['collisions']);

    // Find the collision values.
    $collisionValues = array_column(
      $report['sdc.byte_theme.card-icon']['collisions'],
      'value'
    );
    $this->assertContains('primary', $collisionValues);
    $this->assertContains('default', $collisionValues);
  }

  /**
   * Tests that getBooleanProps includes algorithmic aliases.
   *
   * @covers ::getBooleanProps
   */
  public function testBooleanPropsIncludesAlgorithmicAliases(): void {
    $loader = $this->buildLoader([
      'section' => [
        'section_header' => [
          'type' => 'boolean',
        ],
      ],
    ]);

    $boolProps = $loader->getBooleanProps('sdc.byte_theme.section');
    $aliases = $boolProps['section_header']['aliases'];

    // The prop name itself is always an alias.
    $this->assertContains('section_header', $aliases);
    // Algorithmic derivation: underscore-split words > 2 chars.
    $this->assertContains('section', $aliases);
    $this->assertContains('header', $aliases);
    // Human-readable spaced version.
    $this->assertContains('section header', $aliases);
  }

  /**
   * Tests that non-toggle boolean props (align, reverse, flip) are excluded.
   *
   * @covers ::getBooleanProps
   */
  public function testBooleanPropsExcludesNonToggleProps(): void {
    $loader = $this->buildLoader([
      'footer' => [
        'align' => [
          'type' => 'boolean',
        ],
        'reverse' => [
          'type' => 'boolean',
        ],
        'flip' => [
          'type' => 'boolean',
        ],
        'section_footer' => [
          'type' => 'boolean',
        ],
      ],
    ]);

    $boolProps = $loader->getBooleanProps('sdc.byte_theme.footer');

    // Non-toggle booleans are excluded.
    $this->assertArrayNotHasKey('align', $boolProps);
    $this->assertArrayNotHasKey('reverse', $boolProps);
    $this->assertArrayNotHasKey('flip', $boolProps);

    // True toggles are still included.
    $this->assertArrayHasKey('section_footer', $boolProps);
    $this->assertFalse($boolProps['section_footer']['inverted']);
  }

  /**
   * Tests reverse enum index with section component (3 enum props, collisions).
   *
   * @covers ::getReverseEnumIndex
   */
  public function testReverseEnumIndexSectionComponent(): void {
    $loader = $this->buildLoader([
      'section' => [
        'background_color' => [
          'type' => 'string',
          'enum' => ['default', 'primary', 'secondary', 'accent', 'muted'],
        ],
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'inverted', 'primary'],
        ],
        'columns' => [
          'type' => 'string',
          'enum' => ['1', '2', '3', '4'],
        ],
      ],
    ]);

    $index = $loader->getReverseEnumIndex('sdc.byte_theme.section');

    // 'default' collides between background_color and text_color.
    $this->assertCount(2, $index['default']);
    // 'primary' collides.
    $this->assertCount(2, $index['primary']);
    // 'inverted' is unique to text_color.
    $this->assertSame(['text_color'], $index['inverted']);
    // 'muted' is unique to background_color.
    $this->assertSame(['background_color'], $index['muted']);
    // Columns values are string-typed, so they are included despite looking
    // numeric (the type check replaces is_numeric() on values).
    $this->assertArrayHasKey('1', $index);
    $this->assertSame(['columns'], $index['1']);
  }

  /**
   * Tests that boolean-only components return empty for enum methods.
   *
   * @covers ::getReverseEnumIndex
   * @covers ::getEnumOrdinals
   */
  public function testBooleanOnlyComponentHasNoEnumData(): void {
    $loader = $this->buildLoader([
      'toggle' => [
        'active' => [
          'type' => 'boolean',
        ],
        'disabled' => [
          'type' => 'boolean',
        ],
      ],
    ]);

    $this->assertSame([], $loader->getReverseEnumIndex('sdc.byte_theme.toggle'));
    $this->assertSame([], $loader->getEnumOrdinals('sdc.byte_theme.toggle'));

    $boolProps = $loader->getBooleanProps('sdc.byte_theme.toggle');
    $this->assertCount(2, $boolProps);
    $this->assertTrue($boolProps['disabled']['inverted']);
    $this->assertFalse($boolProps['active']['inverted']);
  }

  /**
   * Tests that numeric-string enums (spacing, columns) are included in maps.
   *
   * Regression: is_numeric() previously excluded string enums with
   * numeric-looking values like ["0", "8", "16", "32"].
   *
   * @covers ::getReverseEnumIndex
   * @covers ::getEnumOrdinals
   */
  public function testNumericStringEnumsIncluded(): void {
    $loader = $this->buildLoader([
      'section' => [
        'columns' => [
          'type' => 'string',
          'enum' => ['1', '2', '3', '4'],
        ],
        'margin_block_start' => [
          'type' => 'string',
          'enum' => ['0', '8', '16', '32', '64'],
        ],
      ],
    ]);

    // String-typed numeric enums should be in the reverse index.
    $index = $loader->getReverseEnumIndex('sdc.byte_theme.section');
    $this->assertArrayHasKey('1', $index);
    $this->assertSame(['columns'], $index['1']);
    $this->assertArrayHasKey('0', $index);
    $this->assertSame(['margin_block_start'], $index['0']);
    $this->assertArrayHasKey('32', $index);
    $this->assertSame(['margin_block_start'], $index['32']);

    // They should also have ordinals.
    $ordinals = $loader->getEnumOrdinals('sdc.byte_theme.section');
    $this->assertArrayHasKey('columns', $ordinals);
    $this->assertSame(['1', '2', '3', '4'], $ordinals['columns']['values']);
    $this->assertArrayHasKey('margin_block_start', $ordinals);
    $this->assertSame(['0', '8', '16', '32', '64'], $ordinals['margin_block_start']['values']);
  }

  /**
   * Tests that the reverse alias index includes natural language aliases.
   *
   * @covers ::getReverseAliasIndex
   */
  public function testReverseAliasIndexIncludesNaturalAliases(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'inverted', 'primary'],
        ],
        'align' => [
          'type' => 'string',
          'enum' => ['left', 'center', 'right'],
        ],
      ],
    ]);

    $aliasIndex = $loader->getReverseAliasIndex('sdc.byte_theme.heading');

    // "blue" is a natural alias for "primary" on text_color (from config).
    $this->assertArrayHasKey('blue', $aliasIndex);
    $this->assertSame(['text_color'], $aliasIndex['blue']);

    // "white" is a natural alias for "inverted" on text_color (from config).
    $this->assertArrayHasKey('white', $aliasIndex);
    $this->assertSame(['text_color'], $aliasIndex['white']);

    // "centered" is a natural alias for "center" on align (from config).
    $this->assertArrayHasKey('centered', $aliasIndex);
    $this->assertSame(['align'], $aliasIndex['centered']);

    // Raw values like "primary" should NOT be in alias index
    // (they are in reverse enum index).
    $this->assertArrayNotHasKey('primary', $aliasIndex);
    $this->assertArrayNotHasKey('center', $aliasIndex);
  }

  /**
   * Tests that integer-typed enums are stored via getIntegerEnumValues.
   *
   * @covers ::getIntegerEnumValues
   */
  public function testIntegerEnumValuesStored(): void {
    $loader = $this->buildLoader([
      'heading' => [
        'level' => [
          'type' => 'integer',
          'enum' => [1, 2, 3, 4, 5, 6],
        ],
        'text_color' => [
          'type' => 'string',
          'enum' => ['default', 'inverted'],
        ],
      ],
    ]);

    // Integer enum values are stored separately.
    $intValues = $loader->getIntegerEnumValues('level', 'sdc.byte_theme.heading');
    $this->assertSame([1, 2, 3, 4, 5, 6], $intValues);

    // String enums return NULL from getIntegerEnumValues.
    $this->assertNull($loader->getIntegerEnumValues('text_color', 'sdc.byte_theme.heading'));

    // Unknown prop returns NULL.
    $this->assertNull($loader->getIntegerEnumValues('nonexistent', 'sdc.byte_theme.heading'));
  }

}
