<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\GlobalImports;
use Drupal\canvas\ComponentSource\UrlRewriteInterface;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Component source based on Canvas JavaScript Component config entities.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Code Components'),
  supportsImplicitInputs: FALSE,
  discovery: JsComponentDiscovery::class,
  updater: GeneratedFieldExplicitInputUxComponentInstanceUpdater::class,
  inputs_config_schema_generator: GeneratedFieldExplicitInputUxComponentInstanceInputsConfigSchemaGenerator::class,
  // @see \Drupal\canvas\EntityHandlers\JavascriptComponentStorage::doPostSave()
  discoveryCacheTags: ['config:js_component_list'],
)]
final class JsComponent extends GeneratedFieldExplicitInputUxComponentSourceBase implements UrlRewriteInterface {

  public const SOURCE_PLUGIN_ID = 'js';
  public const AUTO_SAVE_VERSION = 'auto_save';

  public const EXAMPLE_VIDEO_HORIZONTAL = '/ui/assets/videos/mountain_wide.mp4';
  public const EXAMPLE_VIDEO_VERTICAL = '/ui/assets/videos/bird_vertical.mp4';

  protected ExtensionPathResolver $extensionPathResolver;
  protected AutoSaveManager $autoSaveManager;
  protected FileUrlGeneratorInterface $fileUrlGenerator;
  protected ?JavaScriptComponent $jsComponent = NULL;
  protected GlobalImports $globalImports;
  private ?array $autoSavePropFieldDefinitions = NULL;
  private ?array $publishedPropFieldDefinitions = NULL;
  private bool $autoSaveApplied = FALSE;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->extensionPathResolver = $container->get(ExtensionPathResolver::class);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    $instance->fileUrlGenerator = $container->get(FileUrlGeneratorInterface::class);
    $instance->globalImports = $container->get(GlobalImports::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    // Code components are powered by config entities. Config entities'
    // dependencies SHOULD make breaking such components impossible. But it is
    // possible to bypass both `delete` access checks and config system
    // integrity checks, so perform the necessary confirmation.
    $js_component_storage = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
    \assert($js_component_storage instanceof ConfigEntityStorageInterface);
    return $js_component_storage->load($this->getSourceSpecificComponentId()) === NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getComponentPlugin(): ComponentPlugin {
    if ($this->componentPlugin === NULL) {
      // Statically cache the loaded plugin.
      $this->componentPlugin = JsComponentDiscovery::buildEphemeralSdcPluginInstance($this->getJavaScriptComponent(), $this->configuration['prop_field_definitions']);
    }
    return $this->componentPlugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'local_source_id' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    // This component source doesn't use plugin classes.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getJavaScriptComponent(): JavaScriptComponent {
    if ($this->jsComponent === NULL) {
      $js_component_storage = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
      \assert($js_component_storage instanceof ConfigEntityStorageInterface);
      $id = $this->getSourceSpecificComponentId();
      $js_component = $js_component_storage->load($id);
      if (!$js_component instanceof JavaScriptComponent) {
        throw new ComponentNotFoundException(\sprintf('The JavaScript Component with ID `%s` does not exist.', $id));
      }
      $this->jsComponent = $js_component;
    }
    return $this->jsComponent;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = parent::calculateDependencies();
    // @todo Add the global asset library in https://www.drupal.org/project/canvas/issues/3499933.
    $dependencies['config'][] = $this->getJavaScriptComponent()->getConfigDependencyName();
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    try {
      $js_component = $this->getJavaScriptComponent();
      return new TranslatableMarkup('Code component: %name', [
        '%name' => $js_component->label(),
      ]);
    }
    catch (\Exception) {
      return new TranslatableMarkup('Invalid/broken code component');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE): array {
    $component = $this->getJavaScriptComponent();

    // Rendering will validate the props against the version that is published,
    // but on preview the auto-saved version will be used for those components
    // whose implementation lives in config entities (e.g. for JS Components,
    // but not SDCs). This means that required props might be mismatched, so we
    // need to ensure there are explicit inputs for the set of props that are
    // required in any of those.
    if ($isPreview) {
      $published_required_props = $this->getDefaultExplicitInput(only_required: TRUE);
      \assert(Inspector::assertAllHaveKey($published_required_props, 'value'));
      $published_required_props = \array_map(fn(array $prop_source) => new EvaluationResult($prop_source['value']), $published_required_props);
      [$published_required_props, $published_required_props_cacheability] = self::getResolvedPropsAndCacheability(\array_intersect_key($inputs[self::EXPLICIT_INPUT_NAME] ?? [], $published_required_props));
    }

    $autoSave = $this->loadAutoSaveEntity($component, $isPreview);
    $component_url = $component->getComponentUrl($this->fileUrlGenerator, $isPreview);

    $build = [];
    $base_path = \base_path();
    $build['#attached']['library'][] = $component->getAssetLibrary($isPreview);

    $canvas_path = $this->extensionPathResolver->getPath('module', 'canvas');
    // Build base import map.
    $import_maps = $this->globalImports->getImportMap();
    // For scoped dependencies we don't need cache-busting query strings, as
    // those are already busted by its content-dependent filename: when the
    // code component changes, so does the filename.
    // @see \Drupal\canvas\Entity\CanvasAssetLibraryTrait::getJsPath()
    $scoped_map = $this->getScopedDependencies($component, $autoSave, $isPreview);
    $import_maps[ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS] = \array_merge($import_maps[ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS], $scoped_map);

    $build['#attached']['library'] = \array_merge($build['#attached']['library'], $this->getDependencyLibraries($component, $autoSave, $isPreview));

    if (\count($build['#attached']['library']) === 0) {
      unset($build['#attached']['library']);
    }

    $global_brand_kit = $this->getActiveGlobalBrandKit($isPreview);
    // Resource hints.
    $resource_hints = [
      'preact/signals' => \sprintf('%s%s/packages/astro-hydration/dist/signals.module.js', $base_path, $canvas_path),
      '@/lib/preload-helper' => \sprintf('%s%s/packages/astro-hydration/dist/preload-helper.js', $base_path, $canvas_path),
    ];
    foreach ($resource_hints as $url) {
      $build['#attached']['html_head_link'][] = [
        [
          'rel' => 'modulepreload',
          'fetchpriority' => 'high',
          'href' => $url . '?' . $this->globalImports->getQueryString(),
        ],
      ];
    }
    if ($global_brand_kit instanceof BrandKit) {
      foreach ($this->getFontPreloadLinks($global_brand_kit) as $font_preload) {
        $build['#attached']['html_head_link'][] = [
          $font_preload,
        ];
      }
    }
    if ($isPreview && !$autoSave->isEmpty()) {
      \assert($autoSave->entity instanceof JavaScriptComponent);
      $component = $autoSave->entity;
    }
    if ($isPreview) {
      $build['#cache']['tags'][] = AutoSaveManager::CACHE_TAG;
      // Always attach the draft asset library when loading the preview: avoid
      // race conditions; let the controller handle it for us.
      // @see \Drupal\canvas\Controller\ApiConfigAutoSaveControllers::getCss()
      $build['#attached']['library'][] = 'canvas/asset_library.' . AssetLibrary::GLOBAL_ID . '.draft';
      $build['#attached']['library'][] = 'canvas/brand_kit.' . BrandKit::GLOBAL_ID . '.draft';
    }
    else {
      $build['#attached']['library'][] = 'canvas/asset_library.' . AssetLibrary::GLOBAL_ID;
      $build['#attached']['library'][] = 'canvas/brand_kit.' . BrandKit::GLOBAL_ID;
    }

    $valid_props = $component->getProps() ?? [];

    [$props, $props_cacheability] = self::getResolvedPropsAndCacheability(\array_intersect_key($inputs[self::EXPLICIT_INPUT_NAME] ?? [], $valid_props));

    // Explicit inputs for required props for both the auto-saved version and
    // the live versions, including cacheability.
    if ($isPreview) {
      $props += $published_required_props;
      $props_cacheability->merge($published_required_props_cacheability);
    }

    // Match SDC's developer-only validation of props.
    // @see \Drupal\Core\Template\ComponentsTwigExtension::validateProps()
    // Any InvalidComponentException propagates to RenderSafeComponentContainer
    // which in preview mode renders an empty container (no error text) so the
    // canvas-island stays visible while the user corrects the value.
    // @see \Drupal\canvas\Element\RenderSafeComponentContainer::handleComponentException()
    // @see https://www.drupal.org/project/canvas/issues/3583639
    \assert($this->componentValidator->validateProps($props, $this->getComponentPlugin()));
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($component)
      ->addCacheableDependency($props_cacheability)
      ->applyTo($build);

    return $build + [
      '#type' => 'astro_island',
      '#uuid' => $componentUuid,
      '#import_maps' => $import_maps,
      '#name' => $component->label(),
      '#component_url' => $component_url,
      '#props' => $props + [
        'canvas_uuid' => $componentUuid,
        'canvas_slot_ids' => \array_keys($slot_definitions),
        'canvas_is_preview' => $isPreview,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    $build['#slots'] = $slots;
  }

  private function getActiveGlobalBrandKit(bool $isPreview): ?BrandKit {
    $brand_kit_storage = $this->entityTypeManager->getStorage(BrandKit::ENTITY_TYPE_ID);
    \assert($brand_kit_storage instanceof ConfigEntityStorageInterface);
    $brand_kit = $brand_kit_storage->load(BrandKit::GLOBAL_ID);
    if (!$brand_kit instanceof BrandKit) {
      return NULL;
    }

    if (!$isPreview) {
      return $brand_kit;
    }

    $auto_save = $this->autoSaveManager->getAutoSaveEntity($brand_kit);
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof BrandKit);
      return $auto_save->entity;
    }

    return $brand_kit;
  }

  /**
   * @param \Drupal\canvas\Entity\BrandKit $brand_kit
   *
   * @return list<array{
   *   rel: string,
   *   as: string,
   *   type: string,
   *   href: string,
   *   crossorigin: string
   *   }>
   */
  private function getFontPreloadLinks(BrandKit $brand_kit): array {
    $links = [];
    foreach ($brand_kit->getFonts() as $font) {
      $href = $this->fileUrlGenerator->generateString($font['uri']);
      $links[$href] = [
        'rel' => 'preload',
        'as' => 'font',
        'type' => self::getFontMimeType($font['format']),
        'href' => $href,
        'crossorigin' => 'anonymous',
      ];
    }

    return array_values($links);
  }

  private static function getFontMimeType(string $format): string {
    return match ($format) {
      'woff2' => 'font/woff2',
      'woff' => 'font/woff',
      'ttf' => 'font/ttf',
      'otf' => 'font/otf',
      default => 'font/woff2',
    };
  }

  /**
   * Returns the source label for this component.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The source label.
   */
  protected function getSourceLabel(): TranslatableMarkup {
    return $this->t('Code component');
  }

  /**
   * @todo Remove in clean-up follow-up; minimize non-essential changes.
   */
  public static function componentIdFromJavascriptComponentId(string $javaScriptComponentId): string {
    return JsComponentDiscovery::getComponentConfigEntityId($javaScriptComponentId);
  }

  /**
   * {@inheritdoc}
   */
  public function rewriteExampleUrl(string $url): GeneratedUrl {
    // Allow any fully qualified URL.
    $parsed_url = parse_url($url);
    \assert(\is_array($parsed_url));
    if (array_intersect_key($parsed_url, array_flip(['scheme', 'host']))) {
      return (new GeneratedUrl())->setGeneratedUrl($url);
    }

    // Allow the example URL to be one of the hardcoded relative URLs, and
    // rewrite them to operational root-relative URLs.
    // Only allow precise matches for both DX and security reasons.
    $example_videos = [
      self::EXAMPLE_VIDEO_HORIZONTAL,
      self::EXAMPLE_VIDEO_VERTICAL,
    ];
    if (\in_array($url, $example_videos, TRUE)) {
      $file_path = $this->extensionPathResolver->getPath('module', 'canvas') . $url;
      return Url::fromUri('base:/' . $file_path)->toString(TRUE);
    }

    throw new \InvalidArgumentException('Default images for Javascript Components must be a fully-qualified URL with both scheme and host.');
  }

  private function getScopedDependencies(JavaScriptComponent $component, AutoSaveEntity $autoSave, bool $isPreview, array $seen = []): array {
    $scoped_dependencies = [];
    $component_url = $component->getComponentUrl($this->fileUrlGenerator, $isPreview);
    foreach ($component->getComponentDependencies($autoSave, $isPreview) as $js_component_dependency_name => $js_component_dependency) {
      if (\in_array($js_component_dependency_name, $seen, TRUE)) {
        // Recursion or already processed by another dependency.
        continue;
      }
      $seen[] = $js_component_dependency_name;
      \assert($js_component_dependency instanceof JavaScriptComponent);
      $dependencyAutoSave = $this->loadAutoSaveEntity($js_component_dependency, $isPreview);
      $dependency_component_url = $js_component_dependency->getComponentUrl($this->fileUrlGenerator, $isPreview);
      $scoped_dependencies[$component_url]["@/components/{$js_component_dependency_name}"] = $js_component_dependency->getComponentUrl($this->fileUrlGenerator, $isPreview);
      $scoped_dependencies = array_merge($scoped_dependencies, $this->getScopedDependencies($js_component_dependency, $dependencyAutoSave, $isPreview, $seen));
      if (isset($scoped_dependencies[$dependency_component_url])) {
        // The dependencies of my dependencies are also my dependencies, so says
        // the logic.
        $scoped_dependencies[$component_url] = array_merge($scoped_dependencies[$component_url], $scoped_dependencies[$dependency_component_url]);
      }
    }
    return $scoped_dependencies;
  }

  private function getDependencyLibraries(JavaScriptComponent $component, AutoSaveEntity $autoSave, bool $isPreview, array $seen = []): array {
    $libraries = [];
    foreach ($component->getComponentDependencies($autoSave, $isPreview) as $js_component_dependency_name => $js_component_dependency) {
      if (\in_array($js_component_dependency_name, $seen, TRUE)) {
        // Recursion or already processed by another dependency.
        continue;
      }
      $seen[] = $js_component_dependency_name;
      \assert($js_component_dependency instanceof JavaScriptComponent);
      $dependencyAutoSave = $this->loadAutoSaveEntity($js_component_dependency, $isPreview);
      $libraries[] = $js_component_dependency->getAssetLibrary($isPreview);
      $libraries = array_merge($libraries, $this->getDependencyLibraries($js_component_dependency, $dependencyAutoSave, $isPreview, $seen));
    }
    return $libraries;
  }

  /**
   * Applies auto-save state to this plugin instance, if available.
   *
   * Updates $this->jsComponent, resets derived caches, and recomputes
   * prop_field_definitions from the auto-saved JavaScriptComponent.
   * Called lazily on first form-related method invocation; no-ops thereafter.
   */
  private function ensureAutoSaveVersionExists(): void {
    if ($this->autoSaveApplied) {
      return;
    }
    $this->autoSaveApplied = TRUE;

    try {
      $published = $this->getJavaScriptComponent();
    }
    catch (ComponentNotFoundException) {
      // The underlying JsComponent entity no longer exists (broken component).
      return;
    }
    $autoSave = $this->autoSaveManager->getAutoSaveEntity($published);
    if ($autoSave->isEmpty()) {
      return;
    }
    \assert($autoSave->entity instanceof JavaScriptComponent);

    // Swap to auto-save entity and reset all derived caches.
    $this->jsComponent = $autoSave->entity;
    $this->resetCachedPropState();

    // Recompute prop_field_definitions from the auto-saved component.
    try {
      $ephemeralSdc = JsComponentDiscovery::buildEphemeralSdcPluginInstance($autoSave->entity);
      $this->autoSavePropFieldDefinitions = self::getPropsForComponentPlugin($ephemeralSdc);
    }
    catch (\Exception) {
      // Invalid auto-save schema: fall back to published version.
      $this->jsComponent = $published;
      $this->resetCachedPropState();
      return;
    }

    // Update plugin configuration so parent::clientModelToInput() uses
    // auto-save prop definitions.
    $this->publishedPropFieldDefinitions = $this->configuration['prop_field_definitions'];
    $this->configuration['prop_field_definitions'] = $this->autoSavePropFieldDefinitions;

    // Also inject the auto_save version into the currently cached Component
    // entity so code paths that load it via Component::load() see it.
    $componentId = JsComponentDiscovery::getComponentConfigEntityId($this->getSourceSpecificComponentId());
    $component = Component::load($componentId);
    if ($component instanceof Component) {
      $this->injectAutoSaveVersion($component);
    }
  }

  /**
   * Injects the 'auto_save' version into a Component entity.
   *
   * Makes loadVersion('auto_save'), getVersions(), and
   * getSettings('auto_save') all work. Uses the public get/set API to modify
   * the protected versioned_properties without changing the Component or
   * VersionedConfigEntityBase classes.
   */
  private function injectAutoSaveVersion(Component $component): void {
    if ($this->autoSavePropFieldDefinitions === NULL) {
      return;
    }
    // If auto_save is already the active version (set by hook_entity_load),
    // nothing more to do.
    if ($component->getActiveVersion() === self::AUTO_SAVE_VERSION) {
      return;
    }
    // Otherwise inject it as the active version.
    $component->createVersion(self::AUTO_SAVE_VERSION);
    $component->setSettings(['prop_field_definitions' => $this->autoSavePropFieldDefinitions]);
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    $this->ensureAutoSaveVersionExists();
    return parent::clientModelToInput($component_instance_uuid, $component, $client_model, $host_entity, $violations);
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    Component $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $this->ensureAutoSaveVersionExists();
    if ($this->autoSavePropFieldDefinitions !== NULL) {
      $settings['prop_field_definitions'] = $this->autoSavePropFieldDefinitions;
      // For new props that exist in auto-save but not in the published version
      // and were not sent by the client: pre-populate with default values so
      // the form renders with example values rather than empty fields.
      foreach ($this->autoSavePropFieldDefinitions as $prop_name => $def) {
        if (!\array_key_exists($prop_name, $inputValues)
          && !\array_key_exists($prop_name, $this->publishedPropFieldDefinitions ?? [])
          && $def['default_value'] !== NULL
          && $def['default_value'] !== []) {
          $inputValues[$prop_name] = $def['default_value'];
        }
      }
    }
    return parent::buildComponentInstanceForm($form, $form_state, $component, $component_instance_uuid, $inputValues, $entity, $settings);
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    $this->ensureAutoSaveVersionExists();
    return parent::getExplicitInput($uuid, $item, $host_entity);
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    $this->ensureAutoSaveVersionExists();
    return parent::inputToClientModel($explicit_input);
  }

  /**
   * {@inheritdoc}
   */
  public function optimizeExplicitInput(array $values): array {
    $this->ensureAutoSaveVersionExists();
    return parent::optimizeExplicitInput($values);
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $this->ensureAutoSaveVersionExists();
    return parent::validateComponentInput($inputValues, $component_instance_uuid, $entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(Component $component): array {
    $this->ensureAutoSaveVersionExists();
    // When auto_save is the active version (set by hook_entity_load),
    // parent::getClientSideInfo naturally reads auto_save settings and
    // normalizeForClientSide() naturally returns version: 'auto_save'.
    return parent::getClientSideInfo($component);
  }

  public function setJavaScriptComponent(?JavaScriptComponent $jsComponent): static {
    $this->jsComponent = $jsComponent;
    return $this;
  }

  private function loadAutoSaveEntity(EntityInterface $entity, bool $isPreview): AutoSaveEntity {
    // If we are not previewing then we never need to load the auto-save data.
    return $isPreview ? $this->autoSaveManager->getAutoSaveEntity($entity) : AutoSaveEntity::empty();
  }

}
