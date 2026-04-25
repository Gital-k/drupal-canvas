<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin;

use Drupal\Component\Plugin\CategorizingPluginManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\Component\SchemaCompatibilityChecker;
use Drupal\Core\Theme\ComponentNegotiator;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\Core\Theme\ThemeManagerInterface;
use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\SchemaStorage;

/**
 * @todo Remove this once Canvas relies on a Drupal core version that includes https://www.drupal.org/i/3352063.
 * @internal
 */
class ComponentPluginManager extends CoreComponentPluginManager implements CategorizingPluginManagerInterface {

  const MAXIMUM_RECURSION_LEVEL = 10;

  protected static bool $isRecursing = FALSE;

  /**
   * JSON schema storage utility used for resolving references.
   */
  protected SchemaStorage $schemaStorage;

  /**
   * The decorated component plugin manager.
   */
  protected CoreComponentPluginManager $decorated;

  // @phpstan-ignore pluginManagerSetsCacheBackend.missingCacheBackend
  public function __construct(
    ModuleHandlerInterface $module_handler,
    ThemeHandlerInterface $themeHandler,
    CacheBackendInterface $cacheBackend,
    ConfigFactoryInterface $configFactory,
    ThemeManagerInterface $themeManager,
    ComponentNegotiator $componentNegotiator,
    FileSystemInterface $fileSystem,
    SchemaCompatibilityChecker $compatibilityChecker,
    ComponentValidator $componentValidator,
    string $appRoot,
    CoreComponentPluginManager $decorated,
  ) {
    // Store the decorated service to delegate calls.
    $this->decorated = $decorated;
    parent::__construct(
      $module_handler,
      $themeHandler,
      $cacheBackend,
      $configFactory,
      $themeManager,
      $componentNegotiator,
      $fileSystem,
      $compatibilityChecker,
      $componentValidator,
      $appRoot);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    if ($this->decorated->getCachedDefinitions() === NULL) {
      $definitions = parent::getDefinitions();
      $this->decorated->setCachedDefinitions($definitions);
    }
    return $this->decorated->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    if ($this->decorated->getCachedDefinitions() === NULL) {
      $this->getDefinitions();
    }
    return $this->decorated->getDefinition($plugin_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    if ($this->decorated->getCachedDefinitions() === NULL) {
      $this->getDefinitions();
    }
    return $this->decorated->hasDefinition($plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []): Component {
    return $this->decorated->createInstance($plugin_id, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return $this->decorated->getInstance($options);
  }

  /**
   * {@inheritdoc}
   */
  public function find(string $component_id): Component {
    if ($this->decorated->getCachedDefinitions() === NULL) {
      $this->getDefinitions();
    }
    return $this->decorated->find($component_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllComponents(): array {
    if ($this->decorated->getCachedDefinitions() === NULL) {
      $this->getDefinitions();
    }
    return $this->decorated->getAllComponents();
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    parent::clearCachedDefinitions();
    $this->decorated->clearCachedDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function useCaches($use_caches = FALSE): void {
    $this->decorated->useCaches($use_caches);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return $this->decorated->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->decorated->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->decorated->getCacheMaxAge();
  }

  /**
   * {@inheritdoc}
   */
  public function setCacheBackend(CacheBackendInterface $cache_backend, $cache_key, array $cache_tags = []): void {
    $this->decorated->setCacheBackend($cache_backend, $cache_key, $cache_tags);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line missingType.parameter
   */
  public function processDefinition(&$definition, $plugin_id): void {
    $this->decorated->processDefinition($definition, $plugin_id);
    if (isset($definition['props']['properties']) && \is_array($definition['props']['properties']) && !empty($definition['props']['properties'])) {
      $definition['props'] = $this->resolveJsonSchemaReferences($definition['props'], 0);
    }
  }

  /**
   * Resolves schema references recursively.
   *
   * @param array $schema
   *   JSON schema of a component.
   * @param int $depth
   *   Depth index to avoid infinite recursion.
   *
   * @return array
   *   JSON schema of a component, with references resolved.
   */
  public function resolveJsonSchemaReferences(array $schema, int $depth = 0): array {
    $this->schemaStorage ??= new SchemaStorage();

    if ($depth > self::MAXIMUM_RECURSION_LEVEL) {
      return $schema;
    }

    $depth++;

    $schema = BaseConstraint::arrayToObjectRecursive($schema);
    $refSchema = (array) $this->schemaStorage->resolveRefSchema($schema);
    $schema = (array) $schema;
    unset($schema['$ref']);

    // Merge referenced schema into the current schema.
    $schema += $refSchema;

    // Recursively resolve nested objects.
    foreach ($schema as $key => $value) {
      if (\is_object($value)) {
        $schema[$key] = $this->resolveJsonSchemaReferences((array) $value, $depth);
      }
    }

    // It looks heavy as a solution to convert objects to array recursively,
    // but it is exactly the inverse of what
    // BaseConstraint::arrayToObjectRecursive() is doing.
    $json = json_encode($schema);
    \assert(\is_string($json));
    return json_decode($json, TRUE);
  }

}
