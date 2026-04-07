<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads SDC component YAML schemas and builds alias/enum maps.
 *
 * Discovers all *.component.yml files under the active default theme's
 * components directory, parses each schema, and produces maps consumed by
 * DirectEditMatcher:
 *
 * - Prop alias map: {sdc_name => {alias => prop_name}}
 * - Enum value map: {sdc_name => {prop_name => {alias => canonical_value}}}
 *
 * All maps are cached with the 'canvas_ai' cache tag and 'config:system.theme'
 * so that switching the default theme automatically rebuilds the maps on the
 * next cache clear.
 *
 * @internal
 *   This service is for internal use by canvas_ai only. It may change without
 *   notice between minor releases.
 */
final class ComponentSchemaLoader implements ComponentSchemaLoaderInterface {

  /**
   * Cache ID for the prop alias map.
   */
  private const CACHE_CID_ALIASES = 'canvas_ai:prop_aliases';

  /**
   * Cache ID for the enum value map.
   */
  private const CACHE_CID_ENUMS = 'canvas_ai:enum_values';

  /**
   * Cache ID for the reverse enum index.
   */
  private const CACHE_CID_REVERSE_ENUM = 'canvas_ai:reverse_enum_index';

  /**
   * Cache ID for the boolean props map.
   */
  private const CACHE_CID_BOOLEAN_PROPS = 'canvas_ai:boolean_props';

  /**
   * Cache ID for the enum ordinals map.
   */
  private const CACHE_CID_ENUM_ORDINALS = 'canvas_ai:enum_ordinals';

  /**
   * Cache ID for the integer enum values map.
   */
  private const CACHE_CID_INTEGER_ENUMS = 'canvas_ai:integer_enums';

  /**
   * Cache ID for the reverse alias index.
   */
  private const CACHE_CID_REVERSE_ALIAS = 'canvas_ai:reverse_alias_index';

  /**
   * Cache tag used to invalidate all maps together.
   */
  private const CACHE_TAG = 'canvas_ai';

  /**
   * Props where "enable" means FALSE (inverted boolean semantics).
   */
  private const INVERTED_BOOLEAN_PROPS = [
    'disabled' => TRUE,
    'overlap_navbar' => TRUE,
  ];

  /**
   * Boolean props that are NOT show/hide toggles.
   *
   * These control semantics other than visibility (e.g., alignment direction,
   * layout reversal) and should not be exposed to the BooleanToggleResolver.
   */
  private const NON_TOGGLE_BOOLEAN_PROPS = [
    'align' => TRUE,
    'reverse' => TRUE,
    'flip' => TRUE,
  ];

  /**
   * Size-category props where the first enum value is the largest (descending).
   */
  private const DESCENDING_ORDINAL_PROPS = [
    'text_size',
    'icon_size',
    'size',
    'tile_size',
    'image_size',
  ];

  /**
   * Cached prop alias map: {sdc_name => {alias => prop_name}}.
   *
   * @var array<string, array<string, string>>|null
   */
  private ?array $propAliases = NULL;

  /**
   * Cached enum value map: {sdc_name => {prop_name => {alias => value}}}.
   *
   * @var array<string, array<string, array<string, string>>>|null
   */
  private ?array $enumValues = NULL;

  /**
   * Cached reverse enum index: {sdc_name => {normalized_value => [prop, ...]}}.
   *
   * @var array<string, array<string, list<string>>>|null
   */
  private ?array $reverseEnumIndex = NULL;

  /**
   * Cached boolean props: {sdc_name => {prop => {aliases => [], inverted => bool}}}.
   *
   * @var array<string, array<string, array{aliases: list<string>, inverted: bool}>>|null
   */
  private ?array $booleanProps = NULL;

  /**
   * Cached enum ordinals: {sdc_name => {prop => {values => [], direction => string}}}.
   *
   * @var array<string, array<string, array{values: list<string>, direction: string}>>|null
   */
  private ?array $enumOrdinals = NULL;

  /**
   * Cached integer enum values: {sdc_name => {prop_name => [int, ...]}}.
   *
   * @var array<string, array<string, list<int>>>|null
   */
  private ?array $integerEnums = NULL;

  /**
   * Cached reverse alias index: {sdc_name => {alias => [prop_name, ...]}}.
   *
   * @var array<string, array<string, list<string>>>|null
   */
  private ?array $reverseAliasIndex = NULL;

  /**
   * Constructs a ComponentSchemaLoader.
   *
   * @param \Drupal\Core\Extension\ThemeExtensionList $themeList
   *   The theme extension list, used to resolve the theme path.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler, used to discover the active default theme.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache backend.
   * @param \Psr\Log\LoggerInterface $logger
   *   The canvas_ai logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read optional enum value aliases from
   *   canvas_ai.direct_edit.settings.
   */
  public function __construct(
    private readonly ThemeExtensionList $themeList,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getPropAliases(string $componentName): array {
    $this->ensureLoaded();
    return $this->propAliases[$componentName] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getEnumValues(string $propName, string $componentName): ?array {
    $this->ensureLoaded();
    return $this->enumValues[$componentName][$propName] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedComponents(): array {
    $this->ensureLoaded();
    return array_keys($this->propAliases ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function getReverseEnumIndex(string $componentName): array {
    $this->ensureLoaded();
    return $this->reverseEnumIndex[$componentName] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getReverseAliasIndex(string $componentName): array {
    $this->ensureLoaded();
    return $this->reverseAliasIndex[$componentName] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getBooleanProps(string $componentName): array {
    $this->ensureLoaded();
    return $this->booleanProps[$componentName] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getEnumOrdinals(string $componentName): array {
    $this->ensureLoaded();
    return $this->enumOrdinals[$componentName] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getIntegerEnumValues(string $propName, string $componentName): ?array {
    $this->ensureLoaded();
    return $this->integerEnums[$componentName][$propName] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getOrthogonalityReport(): array {
    $this->ensureLoaded();
    $report = [];

    foreach ($this->reverseEnumIndex ?? [] as $sdcName => $valueMap) {
      $collisions = [];
      foreach ($valueMap as $value => $props) {
        if (count($props) > 1) {
          $collisions[] = ['value' => $value, 'props' => $props];
        }
      }
      $report[$sdcName] = [
        'orthogonal' => empty($collisions),
        'collisions' => $collisions,
      ];
    }

    return $report;
  }

  /**
   * Ensures the alias and enum maps are loaded (from cache or built fresh).
   */
  private function ensureLoaded(): void {
    if ($this->propAliases !== NULL) {
      return;
    }

    $cachedAliases = $this->cache->get(self::CACHE_CID_ALIASES);
    $cachedEnums = $this->cache->get(self::CACHE_CID_ENUMS);
    $cachedReverseEnum = $this->cache->get(self::CACHE_CID_REVERSE_ENUM);
    $cachedBooleanProps = $this->cache->get(self::CACHE_CID_BOOLEAN_PROPS);
    $cachedEnumOrdinals = $this->cache->get(self::CACHE_CID_ENUM_ORDINALS);
    $cachedIntegerEnums = $this->cache->get(self::CACHE_CID_INTEGER_ENUMS);
    $cachedReverseAlias = $this->cache->get(self::CACHE_CID_REVERSE_ALIAS);

    if ($cachedAliases !== FALSE && $cachedEnums !== FALSE
      && $cachedReverseEnum !== FALSE && $cachedBooleanProps !== FALSE
      && $cachedEnumOrdinals !== FALSE && $cachedIntegerEnums !== FALSE
      && $cachedReverseAlias !== FALSE) {
      $this->propAliases = $cachedAliases->data;
      $this->enumValues = $cachedEnums->data;
      $this->reverseEnumIndex = $cachedReverseEnum->data;
      $this->booleanProps = $cachedBooleanProps->data;
      $this->enumOrdinals = $cachedEnumOrdinals->data;
      $this->integerEnums = $cachedIntegerEnums->data;
      $this->reverseAliasIndex = $cachedReverseAlias->data;
      return;
    }

    $this->buildMaps();

    $cacheSets = [
      self::CACHE_CID_ALIASES => $this->propAliases,
      self::CACHE_CID_ENUMS => $this->enumValues,
      self::CACHE_CID_REVERSE_ENUM => $this->reverseEnumIndex,
      self::CACHE_CID_BOOLEAN_PROPS => $this->booleanProps,
      self::CACHE_CID_ENUM_ORDINALS => $this->enumOrdinals,
      self::CACHE_CID_INTEGER_ENUMS => $this->integerEnums,
      self::CACHE_CID_REVERSE_ALIAS => $this->reverseAliasIndex,
    ];
    foreach ($cacheSets as $cid => $data) {
      $this->cache->set(
        $cid,
        $data,
        CacheBackendInterface::CACHE_PERMANENT,
        [self::CACHE_TAG, 'config:system.theme'],
      );
    }
  }

  /**
   * Builds the prop alias and enum maps from all discovered component YAMLs.
   */
  private function buildMaps(): void {
    $this->propAliases = [];
    $this->enumValues = [];
    $this->reverseEnumIndex = [];
    $this->booleanProps = [];
    $this->enumOrdinals = [];
    $this->integerEnums = [];
    $this->reverseAliasIndex = [];

    $defaultThemeName = $this->themeHandler->getDefault();
    $themePath = $this->resolveThemePath($defaultThemeName);
    if ($themePath === NULL) {
      $this->logger->warning('ComponentSchemaLoader: default theme @theme not found; alias map will be empty.', [
        '@theme' => $defaultThemeName,
      ]);
      return;
    }

    $componentsDir = $themePath . '/components';
    if (!is_dir($componentsDir)) {
      $this->logger->warning('ComponentSchemaLoader: components directory not found at @path.', [
        '@path' => $componentsDir,
      ]);
      return;
    }

    $yamlFiles = glob($componentsDir . '/*/*.component.yml') ?: [];
    foreach ($yamlFiles as $file) {
      $this->processComponentFile($file, $defaultThemeName);
    }
  }

  /**
   * Resolves the absolute filesystem path of the given theme.
   *
   * @param string $themeName
   *   The machine name of the theme.
   *
   * @return string|null
   *   Absolute path, or NULL if the theme is not installed.
   */
  private function resolveThemePath(string $themeName): ?string {
    try {
      $theme = $this->themeList->get($themeName);
      $relativePath = $theme->getPath();
      // getPath() returns a path relative to the Drupal root (DRUPAL_ROOT).
      return DRUPAL_ROOT . '/' . $relativePath;
    }
    catch (\Exception $e) {
      $this->logger->warning('ComponentSchemaLoader: could not resolve theme @theme path: @msg', [
        '@theme' => $themeName,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Parses one component YAML file and populates the alias/enum maps.
   *
   * @param string $file
   *   Absolute path to the *.component.yml file.
   * @param string $defaultThemeName
   *   The default theme machine name, used to derive the SDC name.
   */
  private function processComponentFile(string $file, string $defaultThemeName): void {
    try {
      $schema = Yaml::parseFile($file);
    }
    catch (\Exception $e) {
      $this->logger->warning('ComponentSchemaLoader: failed to parse @file: @msg', [
        '@file' => $file,
        '@msg' => $e->getMessage(),
      ]);
      return;
    }

    if (!is_array($schema)) {
      return;
    }

    // Derive the SDC name from the directory name.
    // File: .../components/heading/heading.component.yml becomes
    // sdc.<default_theme>.heading.
    $componentDir = basename(dirname($file));
    $sdcName = 'sdc.' . $defaultThemeName . '.' . $componentDir;

    $properties = $schema['props']['properties'] ?? [];
    if (empty($properties) || !is_array($properties)) {
      return;
    }

    $aliases = [];
    $enumMap = [];
    $reverseEnum = [];
    $boolProps = [];
    $ordinals = [];
    $intEnums = [];

    foreach ($properties as $propName => $propDef) {
      if (!is_array($propDef)) {
        continue;
      }

      // Generate natural language aliases from the prop name.
      $generatedAliases = $this->generateAliases($propName);
      foreach ($generatedAliases as $alias) {
        // Do not overwrite an alias already assigned to another prop.
        if (!isset($aliases[$alias])) {
          $aliases[$alias] = $propName;
        }
      }

      // Detect boolean props (skip non-toggle booleans like align/reverse).
      $propType = $propDef['type'] ?? NULL;
      if ($propType === 'boolean' && !isset(self::NON_TOGGLE_BOOLEAN_PROPS[$propName])) {
        $boolProps[$propName] = [
          'aliases' => array_values($generatedAliases),
          'inverted' => isset(self::INVERTED_BOOLEAN_PROPS[$propName]),
        ];
      }

      // Build enum map for props with enum constraints.
      if (!isset($propDef['enum']) || !is_array($propDef['enum'])) {
        continue;
      }

      $enumValues = $propDef['enum'];

      // Integer/number-typed enums (e.g., heading level [1,2,3,4,5,6]) are
      // stored separately for numeric resolution via getIntegerEnumValues().
      // String-typed enums with numeric-looking values (e.g., columns
      // ["1","2","3","4"] or spacing ["0","8","16","32"]) are kept in the
      // string enum map.
      if ($propType === 'integer' || $propType === 'number') {
        $intValues = array_values(array_filter($enumValues, 'is_int'));
        if (!empty($intValues)) {
          $intEnums[$propName] = $intValues;
        }
        continue;
      }

      $metaEnum = $propDef['meta:enum'] ?? [];
      $propEnumMap = $this->buildEnumAliases($enumValues, is_array($metaEnum) ? $metaEnum : []);
      if (!empty($propEnumMap)) {
        $enumMap[$propName] = $propEnumMap;
      }

      // Build reverse enum index: normalized_value => [prop_name, ...].
      foreach ($enumValues as $value) {
        if (!is_string($value)) {
          continue;
        }
        $normalized = mb_strtolower($value);
        $reverseEnum[$normalized][] = $propName;
      }

      // Build enum ordinals: ordered values with direction metadata.
      $stringValues = array_values(array_filter($enumValues, 'is_string'));
      if (!empty($stringValues)) {
        $direction = in_array($propName, self::DESCENDING_ORDINAL_PROPS, TRUE)
          ? 'descending'
          : 'ascending';
        $ordinals[$propName] = [
          'values' => $stringValues,
          'direction' => $direction,
        ];
      }
    }

    if (!empty($aliases)) {
      $this->propAliases[$sdcName] = $aliases;
    }
    if (!empty($enumMap)) {
      $this->enumValues[$sdcName] = $enumMap;
    }

    // De-duplicate reverse enum index prop lists.
    if (!empty($reverseEnum)) {
      foreach ($reverseEnum as $value => $props) {
        $reverseEnum[$value] = array_values(array_unique($props));
      }
      $this->reverseEnumIndex[$sdcName] = $reverseEnum;
    }

    // Build reverse alias index: alias => [prop_name, ...].
    // Includes natural aliases (e.g. blue→primary) not just raw enum values.
    // Skips aliases already in the raw reverse enum index.
    $reverseAlias = [];
    foreach ($enumMap as $propName => $aliasMap) {
      foreach (array_keys($aliasMap) as $alias) {
        // Skip aliases already covered by the raw reverse enum index.
        if (isset($reverseEnum[$alias])) {
          continue;
        }
        $reverseAlias[$alias][] = $propName;
      }
    }
    if (!empty($reverseAlias)) {
      foreach ($reverseAlias as $alias => $props) {
        $reverseAlias[$alias] = array_values(array_unique($props));
      }
      $this->reverseAliasIndex[$sdcName] = $reverseAlias;
    }

    if (!empty($boolProps)) {
      $this->booleanProps[$sdcName] = $boolProps;
    }
    if (!empty($ordinals)) {
      $this->enumOrdinals[$sdcName] = $ordinals;
    }
    if (!empty($intEnums)) {
      $this->integerEnums[$sdcName] = $intEnums;
    }
  }

  /**
   * Generates natural language aliases from a prop name.
   *
   * Rules:
   * - The prop name itself is always an alias.
   * - Words split by underscore are aliased individually if they are
   *   meaningful (length > 2).
   * - The human-readable version with spaces is always added.
   *
   * @param string $propName
   *   The canonical prop name (snake_case).
   *
   * @return string[]
   *   List of unique lowercase aliases including the prop name itself.
   */
  private function generateAliases(string $propName): array {
    $aliases = [$propName];
    $words = explode('_', $propName);

    // Algorithmic: individual words longer than 2 chars.
    foreach ($words as $word) {
      if (mb_strlen($word) > 2 && $word !== $propName) {
        $aliases[] = $word;
      }
    }
    // Add the human-readable version with spaces.
    $spaced = str_replace('_', ' ', $propName);
    if ($spaced !== $propName) {
      $aliases[] = $spaced;
    }

    return array_values(array_unique($aliases));
  }

  /**
   * Builds the enum alias map for a single prop.
   *
   * Uses meta:enum labels (lowercased) as additional aliases alongside the
   * raw enum values. Also adds common natural language aliases for known
   * value patterns.
   *
   * @param array<mixed> $enumValues
   *   The raw enum values from the YAML schema.
   * @param array<string, string> $metaEnum
   *   The meta:enum map (value => label).
   *
   * @return array<string, string>
   *   Map of alias => canonical_value.
   */
  private function buildEnumAliases(array $enumValues, array $metaEnum): array {
    $map = [];

    foreach ($enumValues as $value) {
      if (!is_string($value)) {
        continue;
      }
      $normalized = mb_strtolower($value);
      $map[$normalized] = $value;

      // Add meta:enum label as an alias.
      if (isset($metaEnum[$value])) {
        $labelAlias = mb_strtolower((string) $metaEnum[$value]);
        if ($labelAlias !== $normalized) {
          $map[$labelAlias] = $value;
        }
      }

      // Add common natural language aliases for known value patterns.
      $naturalAliases = $this->getNaturalAliasesForEnumValue($value);
      foreach ($naturalAliases as $alias) {
        if (!isset($map[$alias])) {
          $map[$alias] = $value;
        }
      }
    }

    return $map;
  }

  /**
   * Returns natural language aliases for a known enum value.
   *
   * Reads aliases from canvas_ai.direct_edit.settings config
   * (enum_value_aliases). Falls back to algorithmic derivation for values
   * not in config: splits hyphenated values into words and generates size
   * abbreviations.
   *
   * @param string $value
   *   The canonical enum value.
   *
   * @return string[]
   *   Additional aliases that map to this value.
   */
  private function getNaturalAliasesForEnumValue(string $value): array {
    $config = $this->configFactory->get('canvas_ai.direct_edit.settings');
    $configAliases = $config->get('enum_value_aliases') ?? [];

    if (isset($configAliases[$value])) {
      return $configAliases[$value];
    }

    // Algorithmic fallback: derive aliases from the value string itself.
    $aliases = [];

    // Hyphenated values get their parts as aliases
    // (e.g., "extra-large" → "extra large").
    if (str_contains($value, '-')) {
      $aliases[] = str_replace('-', ' ', $value);
      $parts = explode('-', $value);
      // Last segment as standalone
      // (e.g., "heading-responsive-4xl" → "4xl").
      $lastPart = end($parts);
      if (strlen($lastPart) <= 4) {
        $aliases[] = $lastPart;
      }
    }

    return $aliases;
  }

}
