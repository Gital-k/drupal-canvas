<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\canvas\ComponentSource\UrlRewriteInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropShape\PropShape;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Prop source that is used to reference default relative URLs.
 *
 * Example links and image URLs should refer to real resources, but the full
 * URL cannot be hardcoded. Component sources that implement UrlRewriteInterface
 * are capable of taking a relative URL and expanding it to an absolute URL
 * that can be used as a default value.
 *
 * @see \Drupal\canvas\ComponentSource\UrlRewriteInterface
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::exampleValueRequiresEntity()
 * @internal
 *
 * @phpstan-import-type DefaultRelativeUrlPropSourceArray from PropSourceBase
 */
final class DefaultRelativeUrlPropSource extends PropSourceBase {

  private readonly UrlRewriteInterface $componentSource;

  public function __construct(
    private readonly mixed $value,
    private readonly array $jsonSchema,
    private readonly string $componentId,
  ) {
    $component = Component::load($componentId);
    \assert($component instanceof Component);
    $componentSource = $component->getComponentSource();
    \assert($componentSource instanceof UrlRewriteInterface);
    $this->componentSource = $componentSource;
  }

  /**
   * {@inheritdoc}
   *
   * @return DefaultRelativeUrlPropSourceArray
   */
  public function toArray(): array {
    return [
      'sourceType' => $this->getSourceType(),
      'value' => $this->value,
      // Store the:
      // - resolved schema, to avoid $refs changing later having an effect
      // - normalized schema, to minimize storage consumption
      // @todo Make this far less clunky 🙈
      'jsonSchema' => PropShape::normalize(
        // First do basic normalization, and resolve.
        PropShape::normalize($this->jsonSchema)->resolvedSchema
      )->schema,
      'componentId' => $this->componentId,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $sdc_prop_source): static {
    // `sourceType = default-relative-url` requires a value and schema to be
    // specified.
    $missing = array_diff(['value', 'jsonSchema', 'componentId'], \array_keys($sdc_prop_source));
    if (!empty($missing)) {
      throw new \LogicException(\sprintf('Missing the keys %s.', implode(',', $missing)));
    }
    \assert(\array_key_exists('value', $sdc_prop_source));
    \assert(\array_key_exists('jsonSchema', $sdc_prop_source));
    \assert(\array_key_exists('componentId', $sdc_prop_source));

    // @todo Make this far less clunky 🙈
    $minimal = PropShape::normalize(
    // First do basic normalization, and resolve.
      PropShape::normalize($sdc_prop_source['jsonSchema'])->resolvedSchema
    )->schema;

    // Data saved before Canvas 1.5.1 kept some extra `$id` entries that the
    // JSON Schema resolver adds. Newer code removes them when saving, so
    // `$minimal` (what we expect) never has them. Remove them here too;
    // otherwise every one of those old values would be rejected below even
    // though it is really fine. Anything else unexpected is still caught below.
    $healed_json_schema = self::stripResolverInjectedIds($sdc_prop_source['jsonSchema']);

    $sorted_healed = $healed_json_schema;
    SortArray::sortByKeyRecursive($sorted_healed);
    SortArray::sortByKeyRecursive($minimal);

    if ($sorted_healed !== $minimal) {
      throw new \LogicException(\sprintf('Extraneous JSON Schema information detected: %s should have been just %s.', json_encode($sorted_healed, JSON_PRETTY_PRINT), json_encode($minimal, JSON_PRETTY_PRINT)));
    }

    return new self(
      $sdc_prop_source['value'],
      $healed_json_schema,
      $sdc_prop_source['componentId'],
    );
  }

  /**
   * Removes the extra `$id` (and older `id`) entries the resolver adds.
   *
   * Works at every level of the schema. Those entries always have a text value
   * (a URI). A real prop that happens to be named `id` instead holds a nested
   * schema (an array), so only removing entries whose value is text leaves such
   * a prop untouched.
   *
   * @see \Drupal\canvas\PropShape\PropShape::normalizePropSchema()
   */
  private static function stripResolverInjectedIds(array $json_schema): array {
    foreach (['$id', 'id'] as $keyword) {
      if (isset($json_schema[$keyword]) && \is_string($json_schema[$keyword])) {
        unset($json_schema[$keyword]);
      }
    }
    foreach ($json_schema as $key => $value) {
      if (\is_array($value)) {
        $json_schema[$key] = self::stripResolverInjectedIds($value);
      }
    }
    return $json_schema;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(?FieldableEntityInterface $host_entity, bool $is_required): EvaluationResult {
    if (\is_string($this->value)) {
      \assert(self::isUrlJsonSchema($this->jsonSchema));
      $generated_url = $this->componentSource->rewriteExampleUrl($this->value);
      return new EvaluationResult(
        $generated_url->getGeneratedUrl(),
        (new CacheableMetadata())
          ->setCacheTags($this->componentSource->getPluginDefinition()['discoveryCacheTags'])
          ->addCacheableDependency($generated_url),
      );
    }

    return new EvaluationResult(
      self::recurse($this->jsonSchema, $this->value, $this->componentSource),
    );
  }

  private static function recurse(array $json_schema, mixed $value, UrlRewriteInterface $component_source): mixed {
    if ($json_schema['type'] === 'array') {
      \assert(array_is_list($value));
      $evaluated = [];
      foreach ($value as $k => $v) {
        $evaluated[$k] = self::recurse($json_schema['items'], $v, $component_source);
      }
      return $evaluated;
    }
    elseif ($json_schema['type'] === 'object') {
      \assert(!array_is_list($value));
      $evaluated = [];
      foreach ($value as $k => $v) {
        $evaluated[$k] = self::recurse($json_schema['properties'][$k], $v, $component_source);
      }
      return $evaluated;
    }
    elseif (\is_string($value) && self::isUrlJsonSchema($json_schema)) {
      $generated_url = $component_source->rewriteExampleUrl($value);
      return new EvaluationResult(
        $generated_url->getGeneratedUrl(),
        (new CacheableMetadata())
          ->setCacheTags($component_source->getPluginDefinition()['discoveryCacheTags'])
          ->addCacheableDependency($generated_url),
      );
    }
    else {
      return $value;
    }
  }

  private static function isUrlJsonSchema(array $property_definition): bool {
    if ($property_definition['type'] !== 'string' || !\array_key_exists('format', $property_definition)) {
      return FALSE;
    }
    return JsonSchemaStringFormat::from($property_definition['format'])->isUriEsque();
  }

  public function asChoice(): string {
    throw new \LogicException();
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    \assert($host_entity === NULL || $host_entity instanceof FieldableEntityInterface);
    // @phpstan-ignore-next-line
    $component_definition = \Drupal::entityTypeManager()->getDefinition(Component::ENTITY_TYPE_ID);
    \assert($component_definition instanceof ConfigEntityTypeInterface);
    $component_prefix = $component_definition->getConfigPrefix();
    return ['config' => ["$component_prefix.$this->componentId"]];
  }

}
