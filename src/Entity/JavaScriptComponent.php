<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\Resource\CanvasResourceLink;
use Drupal\canvas\Resource\CanvasResourceLinkCollection;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\JavascriptComponentStorage;
use Drupal\canvas\EntityHandlers\VisibleWhenDisabledCanvasConfigEntityAccessControlHandler;
use Drupal\canvas\Exception\ConstraintViolationException;
use Symfony\Component\Validator\ConstraintViolation;

#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Code component'),
  label_singular: new TranslatableMarkup('code component'),
  label_plural: new TranslatableMarkup('code components'),
  label_collection: new TranslatableMarkup('Code components'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'storage' => JavascriptComponentStorage::class,
    'access' => VisibleWhenDisabledCanvasConfigEntityAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'machineName',
    'label' => 'name',
    'status' => 'status',
  ],
  config_export: [
    'machineName',
    'name',
    'props',
    'required',
    'slots',
    'js',
    'css',
    'dataDependencies',
  ],
  constraints: [
    'JsComponentHasValidAndSupportedSdcMetadata' => NULL,
  ],
)]
final class JavaScriptComponent extends ConfigEntityBase implements CanvasAssetInterface, FolderItemInterface {

  use CanvasAssetLibraryTrait;
  use ConfigUpdaterAwareEntityTrait;

  public const string ENTITY_TYPE_ID = 'js_component';
  public const string ADMIN_PERMISSION = 'administer code components';
  private const string ASSETS_DIRECTORY = 'assets://astro-island/';

  /**
   * The component machine name.
   */
  protected string $machineName;

  /**
   * The human-readable label of the component.
   */
  protected ?string $name;

  /**
   * The props of the component.
   */
  protected ?array $props = [];

  /**
   * The required props of the component.
   *
   * @var string[]
   */
  protected ?array $required = [];

  /**
   * The slots of the component.
   */
  protected ?array $slots = [];

  /**
   * Data dependencies.
   */
  protected ?array $dataDependencies;

  /**
   * Whether the current HTTP payload included a `folder` key (POST/PATCH).
   *
   * Not config-exported: used only to apply folder membership in ::postSave().
   */
  protected bool $folderMembershipChangeRequested = FALSE;

  /**
   * Parsed folder assignment from the client (POST/PATCH), not config-exported.
   *
   * @var array{mode: 'string', value: ?string}|array{mode: 'map', map: array<string, ?string>}|null
   */
  protected ?array $pendingFolderAssignment = NULL;

  /**
   * Shared instance of the ComponentSource plugin manager, for previews.
   *
   * @see ::buildPreview()
   */
  private static ComponentSourceManager $componentSourceManagerForPreviews;

  /**
   * Shared instance of the AutoSaveManager, for previews.
   *
   * @see ::buildPreview()
   */
  private static AutoSaveManager $autoSaveManagerForPreviews;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return $this->machineName;
  }

  private function getEntityOperations(): CanvasResourceLinkCollection {
    $links = new CanvasResourceLinkCollection([]);
    // Link relation type => route name.
    $possible_operations = [
      CanvasUriDefinitions::LINK_REL_DELETE => ['route_name' => 'canvas.api.config.delete', 'op' => 'delete'],
      // @todo Add a URL template using the CanvasUriDefinitions::LINK_REL_USAGE_DETAILS link relation type
    ];
    foreach ($possible_operations as $link_rel => ['route_name' => $route_name, 'op' => $entity_operation]) {
      $access = $this->access(operation: $entity_operation, return_as_object: TRUE);
      \assert($access instanceof AccessResult);
      if ($access->isAllowed()) {
        $route_params = [
          'canvas_config_entity_type_id' => self::ENTITY_TYPE_ID,
          'canvas_config_entity' => $this->id(),
        ];
        $links = $links->withLink(
          $link_rel,
          new CanvasResourceLink($access, Url::fromRoute($route_name, $route_params), $link_rel)
        );
      }
      else {
        $links->addCacheableDependency($access);
      }
    }
    return $links;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `CodeComponent` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    // TRICKY: config entity properties may allow NULL, but only valid, saved
    // config entities are ever normalized: those that have passed validation
    // against config schema.
    \assert(\is_array($this->js));
    \assert(\is_array($this->css));
    $linkCollection = $this->getEntityOperations();
    $values = [
      'machineName' => $this->id(),
      'name' => (string) $this->label(),
      'status' => $this->status(),
      'props' => $this->props,
      'required' => $this->required,
      'slots' => $this->slots,
      'sourceCodeJs' => $this->js['original'] ?? '',
      'sourceCodeCss' => $this->css['original'] ?? '',
      'compiledJs' => $this->js['compiled'] ?? '',
      'compiledCss' => $this->css['compiled'] ?? '',
      'dataDependencies' => $this->dataDependencies,
      // @see https://jsonapi.org/format/#document-links
      'links' => $linkCollection->asArray(),
    ];
    $folder_value = $this->buildFolderValueForClientSide();
    if ($folder_value !== NULL) {
      $values['folder'] = $folder_value;
    }
    return ClientSideRepresentation::create(
      values: $values,
      preview: $this->buildPreview(),
    )->addCacheableDependency($this)
      ->addCacheableDependency($linkCollection);
  }

  /**
   * Renders a preview of a (saved) code component, regardless of `status`.
   *
   * Reuses the render infrastructure of the JsComponent ComponentSource plugin.
   *
   * @return array
   *   A render array.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
   */
  private function buildPreview(): array {
    // TRICKY: config entity properties may allow NULL, but only valid, saved
    // config entities are ever normalized: those that have passed validation
    // against config schema.
    \assert(\is_array($this->props));
    \assert(\is_array($this->slots));
    \assert(\is_string($this->uuid));

    // If there's an auto-saved version of this code component, load that
    // instead to generate the preview. JsComponent::renderComponent() *already*
    // does this for the auto-saved JS + CSS; this ensures the auto-saved props
    // and slots are used, too.
    self::$autoSaveManagerForPreviews ??= \Drupal::service(AutoSaveManager::class);
    $autoSavedIfAny = self::$autoSaveManagerForPreviews->getAutoSaveEntity($this)->entity;
    \assert($autoSavedIfAny === NULL || $autoSavedIfAny instanceof JavaScriptComponent);

    $existing_component = Component::load(JsComponent::componentIdFromJavascriptComponentId($this->id()));
    $existing_required_prop_definitions = [];
    if ($existing_component) {
      \assert($existing_component instanceof Component);
      $published_settings = $existing_component->getSettings(Component::ACTIVE_VERSION);
      \assert(\array_key_exists('prop_field_definitions', $published_settings));
      $existing_required_prop_definitions = $published_settings['prop_field_definitions'] ?? [];
      $existing_required_prop_definitions = \array_filter($existing_required_prop_definitions, fn(array $prop_field_definition) => $prop_field_definition['required'] === TRUE);
    }

    // Instantiate a minimally viable JsComponent source plugin instance subset.
    // This only needs required props if this component has ever been exposed.
    self::$componentSourceManagerForPreviews ??= \Drupal::service(ComponentSourceManager::class);
    $js_component_source = self::$componentSourceManagerForPreviews->createInstance(JsComponent::SOURCE_PLUGIN_ID, [
      'local_source_id' => $this->id(),
      // 💡This code is instantiating the JS ComponentSource plugin to be able
      // to call its `::renderComponent()` method, to provide an accurate
      // preview of the code component, even if it might never have been exposed
      // (and hence has no `Component` config entity). As rendering triggers
      // validation, if it exists already we need to ensure the required props
      // are present.
      // IOW: the sole purpose here is to render a preview of a code component,
      // so not specifying the field definitions to generate StaticPropSources
      // is fine, unless it was exposed before, where we need to know the
      // required props as validation will happen on preview.
      'prop_field_definitions' => $existing_required_prop_definitions,
    ]);
    \assert($js_component_source instanceof JsComponent);

    // Retrieve all props' example values, prefer auto-saved ones.
    $example_inputs = array_filter(\array_map(
      // Note that an example is optional!
      // @see `type: canvas.json_schema.prop.*`
      fn (array $prop_definition) : null|bool|int|float|string|\Stringable|array => $prop_definition['examples'][0] ?? NULL,
      $autoSavedIfAny->props ?? $this->props,
    ));
    $inputs = [
      JsComponent::EXPLICIT_INPUT_NAME => \array_map(
        fn (bool|int|float|string|\Stringable|array $v) => new EvaluationResult($v),
        $example_inputs,
      ),
    ];
    // If the component was published already, rendering the preview triggers
    // validation of its props. If there were required props that we just
    // deleted, we need to ensure those are present in the inputs to avoid a
    // validation error.
    if ($existing_component) {
      $existing_required_inputs = $existing_component->getComponentSource()
        ->getDefaultExplicitInput(only_required: TRUE);
      foreach ($existing_required_inputs as $prop_name => $prop_source) {
        if (!isset($inputs[JsComponent::EXPLICIT_INPUT_NAME][$prop_name])) {
          \assert(\array_key_exists('value', $prop_source));
          $inputs[JsComponent::EXPLICIT_INPUT_NAME][$prop_name] = new EvaluationResult($prop_source['value']);
        }
      }
    }

    // TRICKY: unlike \Drupal\canvas\Entity\Component::normalizeForClientSide(),
    // this is not getting wrapped in a render-safe container, because the only
    // failure modes for code components at the server-side rendering stage are:
    // 1. the code component's config entity does not exist. But this is a
    //    method on that config entity type, so that cannot happen.
    // 2. the code props' example values may violate the JSON schema for props:
    //    core's `ComponentValidator` would then trigger an exception … except
    //    that JsComponent::renderComponent() does not perform validation.
    // So, no render-safe container is necessary here: the only crash that can
    // happen is on the client side, a validation error because of a missing
    // required prop now removed as described above, or if there's a logic bug.
    $build = $js_component_source->renderComponent(
      inputs: $inputs,
      slot_definitions: $autoSavedIfAny->slots ?? $this->slots,
      componentUuid: $this->uuid,
      isPreview: TRUE,
    );
    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `CodeComponent` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public static function createFromClientSide(array $data): static {
    $entity = static::create(['machineName' => $data['machineName']]);
    $entity->updateFromClientSide($data);
    return $entity;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `CodeComponent` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function updateFromClientSide(array $data): void {
    $this->capturePendingFolderAssignmentFromClientData($data);

    // Normalize props: move enum/meta:enum from array level to items level.
    if (!empty($data['props'])) {
      \assert(\is_array($data['props']));
      $data['props'] = $this->normalizePropsSchema($data['props']);
    }
    foreach (array_intersect_key($data, array_flip([
      'machineName',
      'name',
      'status',
      'required',
      'props',
      'slots',
      'dataDependencies',
    ])) as $key => $value) {
      $this->set($key, $value);
    }

    if (\array_key_exists('sourceCodeCss', $data) || \array_key_exists('compiledCss', $data)) {
      $this->set('css', [
        'original' => $data['sourceCodeCss'] ?? '',
        'compiled' => $data['compiledCss'] ?? '',
      ]);
    }

    $violation_list = new EntityConstraintViolationList($this);
    if (\array_key_exists('sourceCodeJs', $data) || \array_key_exists('compiledJs', $data)) {
      if (!\array_key_exists('importedJsComponents', $data)) {
        $violation_list->add(new ConstraintViolation(
          "The 'importedJsComponents' field is required when 'sourceCodeJs' or 'compiledJs' is provided",
          "The 'importedJsComponents' field is required when 'sourceCodeJs' or 'compiledJs' is provided",
          [],
          NULL,
          "importedJsComponents",
          NULL
        ));
        throw new ConstraintViolationException($violation_list);
      }
      foreach ($data['importedJsComponents'] as $key => $js_component_name) {
        // Test that the importedJsComponents are valid names.
        if (!preg_match('/^[a-z0-9_-]+$/', $js_component_name)) {
          $violation_list->add(new ConstraintViolation(
            "The 'importedJsComponents' contains an invalid component name.",
            "The 'importedJsComponents' contains an invalid component name.",
            [],
            NULL,
            "importedJsComponents",
            NULL
          ));
        }
      }
      if ($violation_list->count() > 0) {
        throw new ConstraintViolationException($violation_list);
      }
      // The client calculates imported JavaScript components dependencies. This
      // value is never returned to the client as it will always recalculate it
      // based off sourceCodeJs.
      $this->addJavaScriptComponentsDependencies($data['importedJsComponents']);
      $this->set('js', [
        'original' => $data['sourceCodeJs'] ?? '',
        'compiled' => $data['compiledJs'] ?? '',
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  /**
   * Code components are not Twig-defined but still aim to match SDC closely.
   *
   * TRICKY: while `props` and `slots` are already individually validated
   * against the JSON schema, the overall structure must also be valid in a way
   * that the SDC's JSON schema does not actually validate: crucial parts are
   * validated only in PHP!
   *
   * @return array{machineName: string, extension_type: string, id: string, provider: string, name: string, props: array, slots?: array, library: array, path: string, template: string}
   *
   * @see core/assets/schemas/v1/metadata-full.schema.json
   * @see \Drupal\Core\Theme\Component\ComponentValidator::validateDefinition()
   * @see \Drupal\Tests\Core\Theme\Component\ComponentValidatorTest::loadComponentDefinitionFromFs()
   */
  public function toSdcDefinition(): array {
    $definition = [
      'machineName' => (string) $this->id(),
      'extension_type' => 'module',
      'id' => 'canvas:' . $this->id(),
      'provider' => 'canvas',
      'name' => (string) $this->label(),
      'props' => [
        'type' => 'object',
        'properties' => $this->props ?? [],
      ],
      // No equivalents exist nor can be generated; specify hard-coded values
      // that allow this to be considered a valid SDC definition.
      'library' => [],
      'path' => '',
      // This needs to be non empty.
      'template' => 'phony',
    ];
    // Slots are optional. Setting the `slots` key to an empty array is invalid.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\JsComponentHasValidAndSupportedSdcMetadataConstraintValidator
    if ($this->slots) {
      foreach ($this->slots as $slot_name => $slot) {
        // Force empty slots to be an object; ComponentValidator casts non-
        // empty arrays to objects, but empty arrays trigger a false positive
        // validation error: "Array value found, but an object is required".
        // @todo Remove this after https://www.drupal.org/project/drupal/issues/3524163 is fixed in core.
        if ($slot === []) {
          $slot = new \stdClass();
        }
        $definition['slots'][$slot_name] = $slot;
      }
    }
    // Required properties are optional. Setting the `props.required` key to an
    // empty array is invalid.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\JsComponentHasValidAndSupportedSdcMetadataConstraintValidator
    if ($this->required) {
      $definition['props']['required'] = $this->required;
    }
    return $definition;
  }

  /**
   * Sets value for props.
   *
   * @param array<string, array{type: string, format?: string, examples?: string|array<string>, title: string}> $props
   *   Value for Props.
   */
  public function setProps(array $props): self {
    $this->props = $props;
    // If a required prop was removed, we need to remove it from the list of
    // required props.
    if (!\is_null($this->required)) {
      $this->required = \array_intersect(\array_keys($props), $this->required);
    }
    return $this;
  }

  /**
   * Gets required props.
   *
   * @return array
   *   Required props.
   */
  public function getRequiredProps(): array {
    return $this->required ?? [];
  }

  /**
   * Gets component props.
   *
   * @return array|null
   *   Component props.
   */
  public function getProps(): ?array {
    return $this->props;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    static::getConfigUpdater()->updateJavaScriptComponent($this);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);

    if ($this->folderMembershipChangeRequested) {
      $this->applyPendingFolderMembership();
      $this->folderMembershipChangeRequested = FALSE;
      $this->pendingFolderAssignment = NULL;
    }

    // The files generated in CanvasAssetStorage::doSave() have a
    // content-dependent hash in their name. This has 2 consequences:
    // 1. Cached responses that referred to an older version, continue to work.
    // 2. New responses must use the newly generated files, which requires the
    //    asset library to point to those new files. Hence the library info must
    //    be recalculated.
    // @see \canvas_library_info_build()
    Cache::invalidateTags(['library_info']);
  }

  /**
   * Builds the `folder` value for API clients (string when both match, else map).
   *
   * @return string|array{library?: string, code?: string}|null
   *   NULL means omit the `folder` key.
   */
  private function buildFolderValueForClientSide(): string|array|null {
    $machine_name = $this->id();
    $code_label = $this->folderLabelForItem($machine_name, self::ENTITY_TYPE_ID);
    $component_id = JsComponent::componentIdFromJavascriptComponentId($machine_name);
    $library_label = NULL;
    if (Component::load($component_id) !== NULL) {
      $library_label = $this->folderLabelForItem($component_id, Component::ENTITY_TYPE_ID);
    }
    if ($code_label === NULL && $library_label === NULL) {
      return NULL;
    }
    if ($library_label !== NULL && $library_label === $code_label) {
      return $code_label;
    }
    $map = [];
    if ($library_label !== NULL) {
      $map['library'] = $library_label;
    }
    if ($code_label !== NULL) {
      $map['code'] = $code_label;
    }
    return $map === [] ? NULL : $map;
  }

  private function folderLabelForItem(string $item_id, string $folder_item_type): ?string {
    $folder = Folder::loadByItemAndConfigEntityTypeId($item_id, $folder_item_type);
    return $folder === NULL ? NULL : (string) $folder->label();
  }

  /**
   * Parses `folder` from decoded JSON and stores it for ::postSave().
   */
  private function capturePendingFolderAssignmentFromClientData(array $data): void {
    $this->folderMembershipChangeRequested = \array_key_exists('folder', $data);
    $this->pendingFolderAssignment = NULL;
    if (!$this->folderMembershipChangeRequested) {
      return;
    }
    $raw = $data['folder'];
    // BC: clients may send `folder: {}` (or `[]`) when they do not intend to
    // change folder placement; treat like a missing key.
    if (\is_array($raw) && $raw === []) {
      $this->folderMembershipChangeRequested = FALSE;
      return;
    }
    if ($raw === NULL || (\is_string($raw) && trim($raw) === '')) {
      $this->pendingFolderAssignment = [
        'mode' => 'string',
        'value' => NULL,
      ];
      return;
    }
    if (\is_string($raw)) {
      $this->pendingFolderAssignment = [
        'mode' => 'string',
        'value' => trim($raw),
      ];
      return;
    }
    if (\is_array($raw)) {
      $map = [];
      foreach (['library', 'code'] as $key) {
        if (!\array_key_exists($key, $raw)) {
          continue;
        }
        $v = $raw[$key];
        if ($v === NULL || (\is_string($v) && trim($v) === '')) {
          $map[$key] = NULL;
        }
        elseif (\is_string($v)) {
          $map[$key] = trim($v);
        }
        else {
          throw new ConstraintViolationException(new EntityConstraintViolationList($this, [
            new ConstraintViolation(
              "The 'folder.$key' field must be a string or null.",
              "The 'folder.$key' field must be a string or null.",
              [],
              '',
              "folder.$key",
              $v,
            ),
          ]));
        }
      }
      if ($map === []) {
        throw new ConstraintViolationException(new EntityConstraintViolationList($this, [
          new ConstraintViolation(
            "The 'folder' object must contain at least one of: library, code.",
            "The 'folder' object must contain at least one of: library, code.",
            [],
            '',
            'folder',
            $raw,
          ),
        ]));
      }
      $component_id = JsComponent::componentIdFromJavascriptComponentId($this->id());
      if (
        \array_key_exists('library', $map)
        && $map['library'] !== NULL
        && Component::load($component_id) === NULL
      ) {
        throw new ConstraintViolationException(new EntityConstraintViolationList($this, [
          new ConstraintViolation(
            'Cannot assign a library folder: this code component is not exposed in the component catalog.',
            'Cannot assign a library folder: this code component is not exposed in the component catalog.',
            [],
            '',
            'folder.library',
            $map['library'],
          ),
        ]));
      }
      $this->pendingFolderAssignment = [
        'mode' => 'map',
        'map' => $map,
      ];
      return;
    }
    throw new ConstraintViolationException(new EntityConstraintViolationList($this, [
      new ConstraintViolation(
        "The 'folder' field must be a string, null, or object.",
        "The 'folder' field must be a string, null, or object.",
        [],
        '',
        'folder',
        $raw,
      ),
    ]));
  }

  /**
   * Applies folder membership for `component` (library) and/or `js_component` (code) folders.
   */
  private function applyPendingFolderMembership(): void {
    if ($this->pendingFolderAssignment === NULL) {
      return;
    }
    $machine_name = $this->id();
    $component_id = JsComponent::componentIdFromJavascriptComponentId($machine_name);
    $exposed = Component::load($component_id) !== NULL;
    $plan = $this->pendingFolderAssignment;
    if (($plan['mode'] ?? '') === 'string') {
      $value = $plan['value'];
      $this->setItemFolderMembership($machine_name, self::ENTITY_TYPE_ID, $value);
      if ($exposed) {
        $this->setItemFolderMembership($component_id, Component::ENTITY_TYPE_ID, $value);
      }
      return;
    }
    if (($plan['mode'] ?? '') === 'map') {
      foreach ($plan['map'] as $context => $folder_name) {
        if ($context === 'code') {
          $this->setItemFolderMembership($machine_name, self::ENTITY_TYPE_ID, $folder_name);
        }
        elseif ($context === 'library' && $exposed) {
          $this->setItemFolderMembership($component_id, Component::ENTITY_TYPE_ID, $folder_name);
        }
      }
    }
  }

  /**
   * Places a folder item into a folder of the given config-entity type, or removes it.
   */
  private function setItemFolderMembership(string $item_id, string $folder_item_type, ?string $folder_name): void {
    $current = Folder::loadByItemAndConfigEntityTypeId($item_id, $folder_item_type);
    if ($current !== NULL) {
      $current->removeItem($item_id)->save();
    }
    if ($folder_name !== NULL && $folder_name !== '') {
      $target = Folder::loadByNameAndConfigEntityTypeId($folder_name, $folder_item_type);
      if ($target === NULL) {
        $target = Folder::create([
          'name' => $folder_name,
          'configEntityTypeId' => $folder_item_type,
          'weight' => 0,
          'items' => [],
        ]);
        $target->save();
      }
      $items = $target->get('items') ?? [];
      if (!\in_array($item_id, $items, TRUE)) {
        $target->addItems([$item_id])->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): static {
    parent::calculateDependencies();

    // Every entity field expression declared under
    // `dataDependencies.entityFields` references field/bundle/module config
    // that must be tracked by Drupal's config dependency manager, so that
    // cascading deletes, config export/import order and cache invalidation
    // work correctly.
    foreach ($this->dataDependencies['entityFields'] ?? [] as $expressions) {
      foreach ($expressions as $expression_string) {
        $expression = StructuredDataPropExpression::fromString($expression_string);
        $this->addDependencies($expression->calculateDependencies());
      }
    }

    return $this;
  }

  /**
   * Add the imported Javascript components as enforced dependencies.
   *
   * Enforced dependencies are not reset during dependency calculation.
   *
   * @param array<string> $imported_js_components
   *   The names of the JavaScript components to add as dependencies.
   *
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *   Thrown if any of the JavaScript components do not exist.
   *
   * @see \Drupal\Core\Config\Entity\ConfigEntityBase::calculateDependencies
   */
  protected function addJavaScriptComponentsDependencies(array $imported_js_components): void {
    $violation_list = new EntityConstraintViolationList($this);
    foreach ($imported_js_components as $key => $js_component_name) {
      $js_component = JavaScriptComponent::load($js_component_name);
      if (!$js_component) {
        $violation_list->add(new ConstraintViolation(
          "The JavaScript component with the machine name '$js_component_name' does not exist.",
          "The JavaScript component with the machine name '$js_component_name' does not exist.",
          [],
          NULL,
          "importedJsComponents.$key",
          $js_component_name
        ));
      }
    }
    if ($violation_list->count() > 0) {
      throw new ConstraintViolationException($violation_list);
    }
    $imported_js_component_dependency_names = array_values(\array_map(
      fn(string $component_name) => $this->getConfigPrefix() . ".$component_name",
      $imported_js_components
    ));
    $this->dependencies['enforced']['config'] ??= [];
    // Remove all the current JavaScript component enforced dependencies.
    $this->dependencies['enforced']['config'] = array_filter(
      $this->dependencies['enforced']['config'],
      fn(string $dependency) => !str_starts_with($dependency, $this->getConfigPrefix())
    );
    $this->dependencies['enforced']['config'] = array_unique(array_merge(
      $this->dependencies['enforced']['config'],
      $imported_js_component_dependency_names
    ));
    if (empty($this->dependencies['enforced']['config'])) {
      unset($this->dependencies['enforced']['config']);
    }
    if (empty($this->dependencies['enforced'])) {
      unset($this->dependencies['enforced']);
    }
  }

  protected function getConfigPrefix(): string {
    $entity_type = $this->getEntityType();
    \assert($entity_type instanceof ConfigEntityTypeInterface);
    return $entity_type->getConfigPrefix();
  }

  public function getComponentUrl(FileUrlGeneratorInterface $generator, bool $isPreview): string {
    if (!$isPreview) {
      return $generator->generateString($this->getJsPath());
    }
    return Url::fromRoute('canvas.api.config.auto-save.get.js', [
      'canvas_config_entity_type_id' => self::ENTITY_TYPE_ID,
      'canvas_config_entity' => $this->id(),
    ])->toString();
  }

  /**
   * {@inheritdoc}
   */
  public function getAssetLibrary(bool $isPreview): string {
    // Inside the Canvas UI, always load the draft even if there isn't one. Let
    // the controller logic automatically serve the non-draft assets when a
    // draft disappears. This is necessary to allow for asset library
    // dependencies, and avoids race conditions.
    // @see \Drupal\canvas\Hook\LibraryHooks::libraryInfoBuild()
    // @see \Drupal\canvas\Controller\ApiConfigAutoSaveControllers::getCss()
    // @see \Drupal\canvas\Controller\ApiConfigAutoSaveControllers::getJs()
    return 'canvas/astro_island.' . $this->id() . ($isPreview ? '.draft' : '');
  }

  private static function shouldLoadAssetFromAutoSave(AutoSaveEntity $autoSave, bool $isPreview) : bool {
    return $isPreview && !$autoSave->isEmpty();
  }

  public function getComponentDependencies(AutoSaveEntity $autoSave, bool $isPreview): array {
    $instance = $this;
    if (self::shouldLoadAssetFromAutoSave($autoSave, $isPreview)) {
      \assert($autoSave->entity instanceof self);
      $instance = $autoSave->entity;
    }

    $js_dependencies = \array_filter(
      $instance->getDependencies()['config'] ?? [],
      static fn(string $dependency) => \str_starts_with($dependency, $instance->getConfigPrefix())
    );
    $js_component_ids = \array_map(fn($dependency) => mb_substr($dependency, mb_strlen($this->getConfigPrefix()) + 1), $js_dependencies);
    return self::loadMultiple($js_component_ids);
  }

  public function getCacheTags() {
    $cache_tags = parent::getCacheTags();
    if ($dependencies = $this->getDependencies()) {
      $cache_tags = array_merge($cache_tags, \array_map(fn($dependency) => "config:$dependency", $dependencies['config'] ?? []));
    }
    return \array_values($cache_tags);
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\canvas\Hook\ComponentSourceHooks::jsSettingsAlter()
   */
  public function getAssetLibraryDependencies(): array {
    return \array_map(static fn (string $dependency): string => \sprintf('canvas/canvasData.%s', $dependency), $this->dataDependencies['drupalSettings'] ?? []);
  }

  /**
   * Normalizes the props schema by moving enum/meta:enum from array to items.
   *
   * For array types, enum and meta:enum should be defined on the items schema,
   * not on the array itself. This method ensures compatibility with the
   * canvas.json_schema.yml config schema.
   *
   * @param array $props
   *   The props schema to normalize.
   *
   * @return array
   *   The normalized props schema.
   */
  private function normalizePropsSchema(array $props): array {
    foreach ($props as &$prop_schema) {
      if (!\is_array($prop_schema)) {
        continue;
      }

      // @todo Removed this in https://drupal.org/i/3516754 when validation
      //   correctly determines that `[[]]` does not actually contain example
      //   values.
      if (isset($prop_schema['examples'])) {
        \assert(\is_array($prop_schema['examples']));
        $prop_schema['examples'] = $prop_schema['examples'] === [[]] ? [] : $prop_schema['examples'];
      }

      // Determine the type (handle both string and array forms).
      $type = \is_array($prop_schema['type'] ?? '')
        ? $prop_schema['type'][0]
        : ($prop_schema['type'] ?? '');

      // For array types with items, move enum/meta:enum/x-translation-context
      // from the array level into items, then rebuild the prop in canonical
      // key order: title, type, examples, items, …rest.
      if ($type === 'array' && isset($prop_schema['items'])) {
        $keys_to_move = ['enum', 'meta:enum', 'x-translation-context'];
        foreach ($keys_to_move as $key) {
          if (\array_key_exists($key, $prop_schema)) {
            $prop_schema['items'][$key] = $prop_schema[$key];
            unset($prop_schema[$key]);
          }
        }

        // Rebuild in canonical order so assertSame() key-order checks pass.
        $canonical_order = ['title', 'type', 'examples', 'items'];
        $reordered = [];
        foreach ($canonical_order as $key) {
          if (\array_key_exists($key, $prop_schema)) {
            $reordered[$key] = $prop_schema[$key];
          }
        }
        // Append any remaining keys that are not in the canonical list.
        foreach ($prop_schema as $key => $value) {
          if (!\array_key_exists($key, $reordered)) {
            $reordered[$key] = $value;
          }
        }
        $prop_schema = $reordered;
      }
    }

    return $props;
  }

}
