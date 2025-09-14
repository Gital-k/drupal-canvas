<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Plugin\Component as SdcPlugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\UrlRewriteInterface;
use Drupal\canvas\Entity\Component as ComponentEntity;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\VersionedConfigEntityBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a generic JavaScript component source.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('JavaScript Components'),
  supportsImplicitInputs: FALSE,
)]
final class JavaScriptComponent extends GeneratedFieldExplicitInputUxComponentSourceBase implements UrlRewriteInterface {

  public const SOURCE_PLUGIN_ID = 'javascript';

  /**
   * Constructs a new JavaScriptComponent.
   *
   * @param array $configuration
   *   Configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param array $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Theme\Component\ComponentValidator $componentValidator
   *   Component validator.
   * @param \Drupal\Core\Field\WidgetPluginManager $fieldWidgetPluginManager
   *   Field widget plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    ComponentValidator $componentValidator,
    WidgetPluginManager $fieldWidgetPluginManager,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    assert(array_key_exists('local_source_id', $configuration));
    // Expect prop_field_definitions to be set by the config entity.
    assert(array_key_exists('prop_field_definitions', $configuration));

    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $componentValidator,
      $fieldWidgetPluginManager,
      $entityTypeManager,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ComponentValidator::class),
      $container->get('plugin.manager.field.widget'),
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
    // Component name (duplicates source_local_id from entity)
      'local_source_id' => NULL,
      // The following will be populated from the Component config entity
      // settings.
    // SDC-style props schema.
      'props' => [],
    // SDC-style slots definition.
      'slots' => [],
    // JavaScript library to load.
      'drupal_library' => NULL,
    // Human-readable label.
      'label' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    // JavaScript components don't have PHP classes.
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Creates a mock SDC plugin for JavaScript components. This mock plugin
   * allows us to reuse the explicit UX field generation logic implemented by
   * the parent GeneratedFieldExplicitInputUxComponentSourceBase class.
   *
   * @todo Remove in https://www.drupal.org/project/canvas/issues/3503038
   */
  public function getSdcPlugin(): ComponentPlugin {
    if ($this->componentPlugin === NULL) {
      $this->componentPlugin = static::createSdcPluginFromConfiguration($this->configuration);
    }
    return $this->componentPlugin;
  }

  /**
   * {@inheritdoc}
   */
  protected function getComponentPlugin(): ComponentPlugin {
    return $this->getSdcPlugin();
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    $component = $this->getComponentPlugin();
    return new TranslatableMarkup('JavaScript component: %name', [
      '%name' => $component->metadata->name,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, string $componentUuid, bool $isPreview = FALSE): array {
    // @todo Implement JavaScript component rendering
    return [
      '#markup' => sprintf(
        '<div>JavaScript Component: %s (rendering not implemented)</div>',
        $this->configuration['local_source_id']
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    $build['#slots'] = $slots;
  }

  /**
   * Create test Component config entities for JavaScript components.
   *
   * This creates and saves test component entities. If a component name
   * is provided, only that component is created. Otherwise, multiple
   * test components may be created.
   *
   * @param string|null $component_name
   *   Optional component name. If provided, only creates that component.
   */
  public static function createTestComponentEntities(?string $component_name = NULL): void {
    $component_name = $component_name ?? 'drupal-markup';
    // Convert component name to label: 'drupal-markup' -> 'Drupal Markup'.
    $label = str_replace('-', ' ', $component_name);
    $label = ucwords($label);

    // Settings with 1:1 SDC metadata structure - same for all components.
    $settings = [
      'label' => $label,
      'local_source_id' => $component_name,
      // Props - exactly like SDC.
      'props' => [
        'type' => 'object',
        'required' => ['content'],
        'properties' => [
          'content' => [
            'type' => 'string',
            'title' => 'Markup',
            'examples' => ['<p>Watch him as he goes!</p>'],
          ],
          'class' => [
            'type' => 'string',
            'title' => 'CSS Classes',
            'examples' => ['text-bold'],
          ],
        ],
      ],
      // Slots - empty for this example.
      'slots' => [],
      // JavaScript-specific.
      'drupal_library' => 'canvas/nuxt_components',
    ];

    $component = ComponentEntity::create([
      'id' => 'javascript.' . $component_name,
      'label' => $label,
      'category' => 'JavaScript Components',
      'source' => self::SOURCE_PLUGIN_ID,
      'provider' => NULL,
      'source_local_id' => $component_name,
      'active_version' => 'v1',
      'versioned_properties' => [
        // The 'active' version is required by VersionedConfigEntityBase.
        VersionedConfigEntityBase::ACTIVE_VERSION => ['settings' => $settings],
      ],
      'status' => TRUE,
    ]);

    static::ensurePropFieldDefinitions($component);
    $component->save();
  }

  /**
   * Ensures all props have field definitions for a component entity.
   *
   * Generates missing field definitions from the props schema using SDC's
   * prop generation logic and updates the component entity's settings.
   *
   * @param \Drupal\canvas\Entity\ComponentInterface $component
   *   The component entity to update.
   */
  public static function ensurePropFieldDefinitions(ComponentInterface $component): void {
    // Load the active version to work with the settings.
    $version = $component->getActiveVersion();
    $component->loadVersion($version);

    $settings = $component->getSettings();

    if (!empty($settings['props']['properties'])) {
      // Initialize prop_field_definitions if not set.
      if (!isset($settings['prop_field_definitions'])) {
        $settings['prop_field_definitions'] = [];
      }

      // Check if any prop is missing its field definition.
      $prop_names = array_keys($settings['props']['properties']);
      $missing_props = array_diff($prop_names, array_keys($settings['prop_field_definitions']));

      if (!empty($missing_props)) {
        // Create mock SDC plugin and generate field definitions.
        $sdc_plugin = static::createSdcPluginFromConfiguration([
          'local_source_id' => $component->get('source_local_id'),
          'label' => $settings['label'] ?? $component->label(),
          'props' => $settings['props'],
          'slots' => $settings['slots'] ?? [],
        ]);
        $generated_definitions = self::getPropsForComponentPlugin($sdc_plugin);

        foreach ($missing_props as $prop_name) {
          if (isset($generated_definitions[$prop_name])) {
            $settings['prop_field_definitions'][$prop_name] = $generated_definitions[$prop_name];
          }
        }
        $component->setSettings($settings);
      }
    }
  }

  /**
   * Creates a mock SDC plugin from configuration.
   *
   * This static helper creates a mock SDC plugin that can be used to generate
   * prop field definitions without needing to instantiate the full class.
   *
   * @param array $configuration
   *   Configuration array that must contain:
   *   - local_source_id: The component's local source ID.
   *   - label: The component's human-readable label.
   *   - props: SDC-style props schema array.
   *   - slots: SDC-style slots definition array.
   *
   * @return \Drupal\Core\Plugin\Component
   *   A mock SDC plugin configured with the given settings.
   */
  protected static function createSdcPluginFromConfiguration(array $configuration): ComponentPlugin {
    // The Component class expects to create metadata from plugin_definition.
    // We need to structure the plugin_definition to include all metadata.
    $plugin_definition = [
      'id' => 'javascript:' . $configuration['local_source_id'],
      'machineName' => $configuration['local_source_id'],
      'path' => 'mock-path',
      'name' => $configuration['label'],
      'template' => 'mock-template.html.twig',
      'provider' => NULL,
      // Direct SDC props structure.
      'props' => $configuration['props'],
      // Direct SDC slots structure.
      'slots' => $configuration['slots'],
    ];

    // Component class creates its own metadata from plugin_definition.
    return new SdcPlugin(
      ['app_root' => '', 'enforce_schemas' => FALSE],
      'javascript:' . $configuration['local_source_id'],
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getSourceLabel(): TranslatableMarkup {
    return $this->t('JavaScript component');
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    // JavaScript components requirements.
    if (empty($this->configuration['local_source_id'])) {
      throw new ComponentDoesNotMeetRequirementsException(['Component name is required']);
    }
    if (empty($this->configuration['drupal_library'])) {
      throw new ComponentDoesNotMeetRequirementsException(['Drupal library is required']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rewriteExampleUrl(string $url): string {
    // For JavaScript components, just return the URL as-is
    // since there's no physical template path to resolve.
    return $url;
  }

}
