<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\SchemaStorage;

/**
 * @todo Remove this once Canvas relies on a Drupal core version that includes https://www.drupal.org/i/3352063.
 * @internal
 *
 * TRICKY: because Drupal\Core\Theme\ComponentPluginManager does not have an
 * interface and all of core expects the concrete implementation, decorating
 * this service is extraordinarily brittle.
 */
class ComponentPluginManager extends CoreComponentPluginManager {

  const MAXIMUM_RECURSION_LEVEL = 10;

  /**
   * JSON schema storage utility used for resolving references.
   */
  protected SchemaStorage $schemaStorage;

  // @phpstan-ignore pluginManagerSetsCacheBackend.missingCacheBackend
  public function __construct(
    // TRICKY: must be protected, not private, for testing purposes.
    // @see \Drupal\Tests\canvas\Kernel\BrokenComponentManager
    protected readonly CoreComponentPluginManager $decorated,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    return $this->decorated->getDefinition($plugin_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return $this->decorated->getDefinitions();
  }

  /**
   * {@inheritdoc}
   *
   * TRICKY: this should be on an interface, but isn't.
   */
  public function find(string $component_id): Component {
    return $this->decorated->find($component_id);
  }

  /**
   * {@inheritdoc}
   *
   * TRICKY: this should be on an interface, but isn't.
   */
  public function getAllComponents(): array {
    return $this->decorated->getAllComponents();
  }

  /**
   * {@inheritdoc}
   *
   * TRICKY: this should be on an interface, but isn't.
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
  public function clearCachedDefinitions(): void {
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
   */
  public function getCategories() {
    return $this->decorated->getCategories();
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line missingType.parameter
   */
  public function getSortedDefinitions(?array $definitions = NULL, $label_key = 'label') {
    return $this->decorated->getSortedDefinitions($definitions, $label_key);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line missingType.parameter
   */
  public function getGroupedDefinitions(?array $definitions = NULL, $label_key = 'label') {
    return $this->decorated->getGroupedDefinitions($definitions, $label_key);
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
