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
    // If prop_field_definitions is empty, generate from props in config
    // entity settings.
    if (empty($configuration['prop_field_definitions']) && !empty($configuration['props'])) {
      // Reuse SDC's prop generation logic.
      // Use the already configured mock SDC plugin for prop generation.
      $plugin = $this->getSdcPlugin();
      $configuration['prop_field_definitions'] = GeneratedFieldExplicitInputUxComponentSourceBase::getPropsForComponentPlugin($plugin);
    }

    assert(array_key_exists('local_source_id', $configuration));
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
      // The Component class expects to create metadata from plugin_definition.
      // We need to structure the plugin_definition to include all metadata.
      $plugin_definition = [
        'id' => 'javascript:' . $this->configuration['local_source_id'],
        'machineName' => $this->configuration['local_source_id'],
        'path' => 'mock-path',
        'name' => $this->configuration['label'],
        'template' => 'mock-template.html.twig',
        'provider' => NULL,
        // Direct SDC props structure.
        'props' => $this->configuration['props'],
        // Direct SDC slots structure.
        'slots' => $this->configuration['slots'],
      ];

      // Component class creates its own metadata from plugin_definition.
      $this->componentPlugin = new SdcPlugin(
        ['app_root' => '', 'enforce_schemas' => FALSE],
        'javascript:' . $this->configuration['local_source_id'],
        $plugin_definition
      );
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
   * This creates and saves test component entities with the given name.
   * All components use the same metadata structure.
   *
   * @param string|null $component_name
   *   Optional component name. Defaults to 'drupal-markup'.
   *
   * @return \Drupal\canvas\Entity\Component
   *   The saved component config entity.
   */
  public static function createTestComponentEntities(?string $component_name = NULL): ComponentEntity {
    $component_name = $component_name ?? 'drupal-markup';
    $version = '1';

    // Settings with 1:1 SDC metadata structure - same for all components.
    $settings = [
      // Props - exactly like SDC.
      'props' => [
        'type' => 'object',
        'required' => ['content'],
        'properties' => [
          'content' => [
            'type' => 'string',
            'format' => 'html',
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
      // Auto-generate prop_field_definitions.
      'prop_field_definitions' => [],
    ];

    // Convert component name to label: 'drupal-markup' -> 'Drupal Markup'.
    $label = str_replace('-', ' ', $component_name);
    $label = ucwords($label);

    $component = ComponentEntity::create([
      'id' => 'javascript.' . $component_name,
      'label' => $label,
      'category' => 'JavaScript Components',
      'source' => self::SOURCE_PLUGIN_ID,
      'provider' => NULL,
      'source_local_id' => $component_name,
      'active_version' => $version,
      'versioned_properties' => [
        $version => ['settings' => $settings],
      ],
      'status' => TRUE,
    ]);

    $component->save();
    return $component;
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
