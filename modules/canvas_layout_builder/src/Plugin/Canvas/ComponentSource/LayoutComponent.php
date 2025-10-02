<?php

declare(strict_types=1);

namespace Drupal\canvas_layout_builder\Plugin\Canvas\ComponentSource;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Component as ComponentEntity;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas_layout_builder\Plugin\LayoutManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines a component source based on layout plugins.
 *
 * @todo Context mappings.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Layout'),
  supportsImplicitInputs: TRUE,
)]
final class LayoutComponent extends ComponentSourceBase implements ContainerFactoryPluginInterface, ComponentSourceWithSlotsInterface {

  use PluginDependencyTrait;

  public const SOURCE_PLUGIN_ID = 'layout';

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly LayoutManager $layoutManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(LayoutManager::class),
    );
  }

  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + ['slots' => $this->getSlotDefinitions()];
  }

  public function getComponentDescription(): TranslatableMarkup {
    $layout = $this->getLayoutPlugin();
    return new TranslatableMarkup('Layout Builder layout: <em>@layout</em>', [
      '@layout' => $layout->getPluginDefinition()->getLabel(),
    ]);
  }

  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    ?Component $component = NULL,
    string $component_instance_uuid = '',
    array $client_model = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $layoutPlugin = $this->getLayoutPlugin();
    if (!$layoutPlugin instanceof PluginFormInterface) {
      return $form;
    }
    if ($client_model) {
      $layoutPlugin->setConfiguration($client_model);
    }
    $form += $layoutPlugin->buildConfigurationForm($form, $form_state);
    return $form;
  }

  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // @todo Implement.
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // @todo Implement.
  }

  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    // @todo Implement.
    return new ConstraintViolationList();
  }

  public function getReferencedPluginClass(): ?string {
    return $this->layoutManager->getDefinition($this->configuration['plugin_id'] ?? $this->configuration['local_source_id'])?->getClass();
  }

  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE,): array {
    $layout = $this->getLayoutPlugin();
    // @todo access.
    // @todo cache.
    $regions = [];
    $layoutRegions = $this->getSlotDefinitions();
    if (\array_key_exists('slots', $inputs)) {
      $layoutRegions = $inputs['slots'];
    }
    foreach ($layoutRegions as $key => $region) {
      $regions[$key] = [];
    }
    $layout->setConfiguration($inputs['settings'] ?? [] + $layout->getConfiguration());
    $content = $layout->build($regions);
    foreach ($inputs['slots'] ?? [] as $key => $slot) {
      if (is_array($slot)) {
        foreach ($slot as $subkey => $component) {
          if (!is_array($component) || !\array_key_exists('component', $component)) {
            continue;
          }
          $componentEntity = Component::load($component['component']);
          if (!$componentEntity instanceof Component) {
            continue;
          }
          $source = $componentEntity->getComponentSource();
          $content[$key][$subkey] = $source->renderComponent($component, $slot_definitions, $componentEntity->uuid(), $isPreview);
        }
      }
    }
    $content['#canvas_slot_ids'] = $layout->getPluginDefinition()->getRegionNames();
    $content['#props'] = [
      'canvas_uuid' => $componentUuid,
      'canvas_slot_ids' => $layout->getPluginDefinition()->getRegionNames(),
      'canvas_is_preview' => $isPreview,
    ];
    if ($isPreview) {
      foreach (Element::children($content) as $child) {
        $startString = '<!-- canvas-slot-start-%s/%s -->';
        if ($content[$child] === []) {
          // If the region is empty, add text after the comment to render
          // the region in the preview.
          $startString .= 'The content of the region';
        }
        array_unshift($content[$child], [
          '#markup' => Markup::create(\sprintf($startString, $componentUuid, $child)),
        ]);
        array_push($content[$child], [
          '#markup' => Markup::create(\sprintf('<!-- canvas-slot-end-%s/%s -->', $componentUuid, $child)),
        ]);
      }
    }
    $content['#settings'] = $inputs['settings'] ?? [];
    $content['#slots'] = $layout->getPluginDefinition()->getRegionNames();
    return $content;
  }

  public function requiresExplicitInput(): bool {
    return !empty($this->getDefaultExplicitInput());
  }

  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    try {
      return $item->getInputs() ?? [];
    }
    catch (MissingComponentInputsException) {
      // There is no input for this component. That should only be the case for
      // layout plugins without any settings.
      assert(!$this->requiresExplicitInput());
      return [];
    }
  }

  public function hydrateComponent(array $explicit_input, array $slot_definitions): array {
    $hydrated = [];
    if ($slots = $this->getSlotDefinitions()) {
      $hydrated['slots'] = array_map(fn($slot) => $slot['examples'][0] ?? '', $slots);
    }
    $hydrated['settings'] = $explicit_input;
    return $hydrated;
  }

  public function inputToClientModel(array $explicit_input): array {
    $model = [
      'resolved' => $explicit_input,
      'source' => [],
    ];
    return $model;
  }

  public function getClientSideInfo(ComponentEntity $component): array {
    return [
      'source' => (string) new TranslatableMarkup('Layout builder layout'),
      'build' => $this->renderComponent([], $component->uuid()),
      'metadata' => ['slots' => $this->getSlotDefinitions()],
      'field_data' => [],
      'dynamic_prop_source_candidates' => [],
      'transforms' => [],
    ];
  }

  public function clientModelToInput(string $component_instance_uuid, ComponentEntity $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    $defaults = $component->get('settings')['default_settings'];
    $input = $client_model['resolved'] ?? [];
    // We don't need to store these as they can be recalculated based on the
    // plugin ID.
    $input += $defaults;
    unset($input['provider'], $input['id']);
    return $input;
  }

  public function checkRequirements(): void {
    // @todo Implement.
  }

  public function calculateDependencies(): array {
    return $this->getPluginDependencies($this->getLayoutPlugin());
  }

  public function getSlotDefinitions(): array {
    $layout = $this->getLayoutPlugin();
    $defs = [];
    foreach ($layout->getPluginDefinition()->getRegionNames() as $region) {
      $defs[$region] = [
        'title' => $region,
        'description' => $region . ' region for the layout.',
        'examples' => ['<div>Test content</div>'],
      ];
    }
    return $defs;
  }

  public function setSlots(array &$build, array $slots): void {
    $build['#slots'] = $slots;
  }

  protected function getLayoutPlugin(): LayoutInterface {
    $layout = $this->layoutManager->createInstance($this->configuration['plugin_id'] ?? $this->configuration['local_source_id']);
    if ($layout instanceof LayoutInterface) {
      return $layout;
    }
    throw new \RuntimeException('Layout plugin not found.');
  }

  public function getDefaultExplicitInput(): array {
    return $this->getLayoutPlugin()->defaultConfiguration();
  }

  public function onDependencyRemoval(array $dependencies): bool {
    return !empty($this->getPluginRemovedDependencies($this->getPluginDependencies($this), $dependencies));
  }

  protected function getExplicitInputDefinitions(): array {
    // @todo Implement.
    return [];
  }

  public function isBroken(): bool {
    // @todo Implement.
    return FALSE;
  }

}
