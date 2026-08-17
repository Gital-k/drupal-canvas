<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ContextGatherer;
use Drupal\canvas\Entity\Component as ComponentEntity;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Form\ClientFormSubmissionHelper;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Block\Plugin\Block\Broken;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\system\Plugin\Block\SystemBreadcrumbBlock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines a component source based on block plugins.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Blocks'),
  // Block plugins can be context-aware (their context assignments are stored in
  // `context_mapping`) and can also contain logic that performs e.g. database
  // queries that fetch data to present.
  supportsImplicitInputs: TRUE,
  discovery: BlockComponentDiscovery::class,
  updater: FALSE,
  inputs_config_schema_generator: BlockComponentInstanceInputsConfigSchemaGenerator::class,
  // @see \Drupal\Core\Block\BlockManager::__construct()
  // @see \Drupal\canvas\Block\BlockManagerDecorator
  // @todo Update after https://www.drupal.org/project/drupal/issues/3001284 lands
  discoveryCacheTags: [],
)]
final class BlockComponent extends ComponentSourceBase implements ContainerFactoryPluginInterface {

  use PluginDependencyTrait;
  use ConstraintPropertyPathTranslatorTrait;

  public const SOURCE_PLUGIN_ID = 'block';
  public const EXPLICIT_INPUT_NAME = 'settings';

  /**
   * Constructs a new BlockComponent.
   *
   * @param array $configuration
   *   Configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param array $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   Block plugin manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly BlockManagerInterface $blockManager,
    private readonly AccountInterface $currentUser,
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly PluginFormFactoryInterface $pluginFormFactory,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ContextGatherer $contextGatherer,
    private readonly ContextHandlerInterface $contextHandler,
  ) {
    \assert(\array_key_exists('local_source_id', $configuration));
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
      $container->get(BlockManagerInterface::class),
      $container->get(AccountInterface::class),
      $container->get(TypedConfigManagerInterface::class),
      $container->get(FormBuilderInterface::class),
      $container->get(PluginFormFactoryInterface::class),
      $container->get(AutoSaveManager::class),
      $container->get(ContextGatherer::class),
      $container->get(ContextHandlerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    return $this->getBlockPlugin() instanceof Broken;
  }

  public function determineDefaultFolder(): string {
    $plugin_definition = $this->getBlockPlugin()->getPluginDefinition();
    \assert(\is_array($plugin_definition));
    \assert(!empty($plugin_definition['category']));

    return (string) $plugin_definition['category'];
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    try {
      return $this->blockManager->getDefinition($this->configuration['local_source_id'])['class'];
    }
    catch (PluginNotFoundException) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getBlockPlugin(): BlockPluginInterface {
    // @todo this should probably use DefaultSingleLazyPluginCollection
    $block = $this->blockManager->createInstance($this->configuration['local_source_id'], $this->configuration);
    \assert($block instanceof BlockPluginInterface);
    return $block;
  }

  /**
   * Gets the context definitions of the underlying block plugin.
   *
   * Used by the component library endpoint to decide whether this block-sourced
   * Component can be placed in the editing situation being requested: a block
   * whose required contexts cannot be satisfied is filtered out.
   *
   * @return array<string, \Drupal\Core\Plugin\Context\ContextDefinitionInterface>
   *   The block plugin's context definitions keyed by context slot name, or an
   *   empty array when the block is not context-aware or is broken/missing.
   *
   * @see \Drupal\canvas\Controller\ApiConfigControllers::list()
   * @see \Drupal\canvas\ComponentSource\ContextGatherer::hasRequiredContextsSatisfied()
   */
  public function getContextDefinitions(): array {
    try {
      $block = $this->getBlockPlugin();
    }
    catch (PluginNotFoundException) {
      return [];
    }
    return $block instanceof ContextAwarePluginInterface ? $block->getContextDefinitions() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return $this->getPluginDependencies($this->getBlockPlugin());
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    $pluginDefinition = $this->getBlockPlugin()->getPluginDefinition() ?? [];
    \assert(\is_array($pluginDefinition));
    return new TranslatableMarkup('Block: %name', [
      '%name' => $pluginDefinition['admin_label'] ?? new TranslatableMarkup('Invalid/broken'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE, ?FieldableEntityInterface $host_entity = NULL): array {
    $block = $this->getBlockPlugin();

    // Avoid the fallback rendering of the Block system; instead use Canvas'
    // own.
    // @see \Drupal\Core\Block\Plugin\Block\Broken::build()
    if ($block instanceof Broken) {
      throw new \OutOfBoundsException('This block is broken or missing.');
    }
    // @todo Refine to reflect the edited entity route in https://www.drupal.org/i/3509500
    if ($isPreview && $block instanceof SystemBreadcrumbBlock) {
      $block = new SystemBreadcrumbBlock(
        $block->getConfiguration(),
        $block->getPluginId(),
        $block->getPluginDefinition(),
        new class() implements BreadcrumbBuilderInterface {
          use StringTranslationTrait;

          public function applies(RouteMatchInterface $route_match) {
             return TRUE;
          }

          /**
           * In the preview, the breadcrumbs always points to the frontpage.
           */
          public function build(RouteMatchInterface $route_match) {
            $breadcrumb = new Breadcrumb();
            $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
            return $breadcrumb;
          }

        },
        // @phpstan-ignore-next-line
        new RouteMatch('<front>', \Drupal::service(RouteProviderInterface::class)->getRouteByName('<front>')),
      );
    }

    foreach ($inputs[self::EXPLICIT_INPUT_NAME] ?? [] as $key => $value) {
      $block->setConfigurationValue($key, $value);
    }

    // Give a context-aware block its context values before it builds. The
    // stored `context_mapping` (set as a configuration value above) says which
    // gathered context feeds each of the block's context slots. This resolves
    // the block's implicit inputs from the real editing/rendering situation:
    // the host content entity plus the populated global contexts.
    // A required context that cannot be satisfied makes applyContextMapping()
    // throw; that is left to bubble so the component-tree render path degrades
    // to a placeholder instead of breaking the whole page.
    // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::renderify()
    if ($block instanceof ContextAwarePluginInterface && $block->getContextDefinitions() !== []) {
      $this->contextHandler->applyContextMapping($block, $this->contextGatherer->getPopulatedContexts($host_entity));
    }

    // Allow global context to be injected by suspending the fiber.
    // @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
    if ($block instanceof TitleBlockPluginInterface || $block instanceof MessagesBlockPluginInterface) {
      if (\Fiber::getCurrent() === NULL) {
        throw new \LogicException(\sprintf('The %s block plugin does not support previews.', $block->getPluginId()));
      }
      \Fiber::suspend($block);
    }

    // @todo preview fallback handling (in case of no access or emptiness) in https://drupal.org/i/3497990
    // @see \Drupal\layout_builder\EventSubscriber\BlockComponentRenderArray::onBuildRender()
    $build = [
      '#access' => $block->access($this->currentUser, TRUE),
    ];
    $cacheable_metadata = CacheableMetadata::createFromObject($block);
    // Bubble the cacheability of every context value applied above, so the
    // render varies and invalidates with the contexts it was built from.
    if ($block instanceof ContextAwarePluginInterface) {
      foreach ($block->getContexts() as $context) {
        $cacheable_metadata->addCacheableDependency($context);
      }
    }
    $cacheable_metadata->applyTo($build);

    \assert($build['#access'] instanceof AccessResultInterface);
    if (!$build['#access']->isAllowed()) {
      return $build;
    }

    $build['content'] = $block->build();
    if (Element::isEmpty($build['content'])) {
      return $build;
    }

    // @todo This render array might be refactored in https://www.drupal.org/node/2931040
    // @see \Drupal\block\BlockViewBuilder::buildPreRenderableBlock
    $build += [
      '#theme' => 'block',
      '#configuration' => $block->getConfiguration(),
      '#plugin_id' => $block->getPluginId(),
      '#base_plugin_id' => $block->getBaseId(),
      '#derivative_plugin_id' => $block->getDerivativeId(),
      '#id' => $componentUuid,
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    $block_plugin = $this->getBlockPlugin();
    $plugin_id = $block_plugin->getPluginId();
    $config_schema_type_definition = $this->typedConfigManager->getDefinition('block.settings.' . $plugin_id);
    return self::removeConfigSchemaLabels($config_schema_type_definition);
  }

  private static function removeConfigSchemaLabels(array $config_schema): array {
    $normalized = [];
    foreach ($config_schema as $key => $value) {
      // TRICKY: this is being omitted despite
      // https://www.drupal.org/project/canvas/issues/3572850. In a way, it
      // makes sense: every block plugin has this due to `type: block_settings`,
      // so it is kinda pointless to compute the version hash. However, then
      // `label_display` should also have been omitted.
      // @todo Change this in https://www.drupal.org/project/canvas/issues/3572850.
      if ($key === 'label') {
        continue;
      }
      if (\is_array($value)) {
        $value = self::removeConfigSchemaLabels($value);
      }
      $normalized[$key] = $value;
    }
    return $normalized;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    return !empty($this->getDefaultExplicitInput());
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    // @todo implement $only_required handling after https://www.drupal.org/i/3521221.
    // @todo handle requiredness per component version: https://www.drupal.org/project/canvas/issues/3558531
    // @todo Expose `label` input in https://www.drupal.org/project/canvas/issues/3572850
    // (Until this is implemented, block component instances are "unforgiving":
    // they will fail to pass validation unless all explicit inputs are
    // specified, including `label` and `label_display`. These two are hidden
    // from the Content Creator, but Canvas requires them to be stored. Consider
    // introducing a similar "optimizeInputs()" implementation for blocks as for
    // SDCs, because for most block instances storing these two is pointless,
    // and until these are exposed to the Content Creator, it is pointless for
    // all block component instances.)
    return $this->getBlockPlugin()->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getResolvedExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    $hydrated_inputs = parent::getResolvedExplicitInput($uuid, $item, $host_entity);
    \assert(\array_key_exists(self::EXPLICIT_INPUT_NAME, $hydrated_inputs));
    return $hydrated_inputs[self::EXPLICIT_INPUT_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {

    try {
      return $item->getInputs() ?? [];
    }
    catch (MissingComponentInputsException) {
      // There is no input for this component. That should only be the case for
      // block plugins without any settings.
      \assert(!$this->requiresExplicitInput());
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    return [self::EXPLICIT_INPUT_NAME => $explicit_input];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    // @see DynamicComponent type-script definition.
    // @see ComponentModel type-script definition.
    $form_state = new FormState();
    // Some plugins make use of nested form elements such as details elements
    // wrapping the configuration form. Our client values need to reflect those
    // of the form structure.
    // @see \Drupal\system\Plugin\Block\SystemBrandingBlock::blockForm
    $block_plugin = $this->getBlockPlugin();
    $block_plugin->setConfiguration($explicit_input);
    // Seed the gathered contexts so a context-aware block's form renders its
    // context-assignment element: a stored `context_mapping` then pre-populates
    // the selects, flows into the client model, and is sent back on the next
    // edit. No host entity is available here, so only global contexts are
    // offered as options.
    $gathered_contexts = $this->gatherContextsFor($block_plugin, NULL);
    $form_object = $this->buildAnonymousFormForBlockPlugin(\hash('sha256', \json_encode($explicit_input, \JSON_THROW_ON_ERROR)), $block_plugin, $gathered_contexts);
    $form_state->setFormObject($form_object);
    $form = $this->formBuilder->buildForm($form_object, $form_state);
    $values = self::filterClientInput($form_state->getValues(), $form);
    return [
      'resolved' => ($values['settings'] ?? []) +
        // We always pass along the explicit input for label and label_display
        // as we've removed these from the form in
        // ::buildAnonymousFormForBlockPlugin.
      \array_intersect_key($explicit_input, \array_flip([
        'label',
        'label_display',
      ])),
    ];
  }

  protected static function filterClientInput(array $input, array $element): array {
    foreach (Element::children($element) as $child) {
      $child_element = $element[$child];
      $input = self::filterClientInput($input, $child_element);

      if (isset($child_element['#access']) && $child_element['#access'] === FALSE) {
        NestedArray::unsetValue($input, $child_element['#parents']);
      }
    }
    return $input;
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    ComponentEntity $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $blockPlugin = $this->getBlockPlugin();
    if ($inputValues) {
      $blockPlugin->setConfiguration($inputValues);
    }
    // A context-aware block's configuration form renders a context-assignment
    // element for each of its context slots, but only when the gathered
    // contexts are seeded in form state. Seed them before building the subform
    // so the block's own form adds and later applies the `context_mapping`
    // element; Canvas does not fork that trait.
    // @see \Drupal\Core\Block\BlockBase::buildConfigurationForm()
    // @see \Drupal\block\BlockForm::form()
    $gathered_contexts = [];
    $is_context_aware = $blockPlugin instanceof ContextAwarePluginInterface && $blockPlugin->getContextDefinitions() !== [];
    if ($is_context_aware) {
      \assert($blockPlugin instanceof ContextAwarePluginInterface);
      // The instance form receives the host entity for a content entity or,
      // for a ContentTemplate, an empty entity of the previewed type; either
      // exposes the entity context blocks match against. Region editing has no
      // host entity, so only global contexts are available.
      // @todo ComponentInstanceForm already resolves the selected host entity; a follow-up could seed the gathered contexts from the controller (or a shared context-aware component-source base) instead of re-deriving them per source here.
      $host_entity = match (TRUE) {
        $entity instanceof FieldableEntityInterface => $entity,
        $entity instanceof ContentTemplate => $entity->createEmptyTargetEntity(),
        default => NULL,
      };
      $gathered_contexts = $this->contextGatherer->getPopulatedContexts($host_entity);
      $form_state->setTemporaryValue('gathered_contexts', $gathered_contexts);
    }

    // Mirror the sub-form logic from the Block config entity form.
    // @see \Drupal\block\BlockForm::form
    $form['#tree'] = TRUE;
    $form['settings'] = [
      '#parents' => $form['#parents'],
    ];
    $subform_state = SubformState::createForSubform($form['settings'], $form, $form_state);
    $form['settings'] = $this->getPluginForm($blockPlugin)->buildConfigurationForm($form['settings'], $subform_state);
    // These fields are added by \Drupal\Core\Block\BlockBase - but we don't
    // want them to factor into the calculation of the input values or client
    // model. The value of these keys are added back to the input
    // in ::clientModelToInput so we can safely remove them.
    foreach (['label', 'admin_label', 'label_display'] as $field) {
      unset($form['settings'][$field]);
    }

    // Core hides a required context that has exactly one candidate, but the
    // editor must still SEE which contexts the instance consumes. Add a
    // read-only display of every required context and the context it resolves
    // to. It is a non-input element, so it contributes no submitted value.
    if ($is_context_aware) {
      \assert($blockPlugin instanceof ContextAwarePluginInterface);
      $form['settings'] += $this->buildRequiredContextsDisplay($blockPlugin, $gathered_contexts);
    }

    return $form;
  }

  /**
   * Builds a read-only display of a block's required contexts.
   *
   * @param \Drupal\Core\Plugin\ContextAwarePluginInterface $block_plugin
   *   The context-aware block plugin.
   * @param array<string, \Drupal\Core\Plugin\Context\ContextInterface> $gathered_contexts
   *   The contexts available for this editing situation, keyed by context id.
   *
   * @return array
   *   A render array of `item` elements, one per required context, or an empty
   *   array when the block declares no required contexts.
   *
   * @todo The context-aware behavior on this source (gathering contexts, the required-context display, and mapping recovery) could move to a shared ContextAwareComponentSourceInterface once another component source needs it.
   */
  private function buildRequiredContextsDisplay(ContextAwarePluginInterface $block_plugin, array $gathered_contexts): array {
    $required_definitions = \array_filter(
      $block_plugin->getContextDefinitions(),
      static fn (ContextDefinitionInterface $definition): bool => $definition->isRequired(),
    );
    if ($required_definitions === []) {
      return [];
    }
    $mapping = $block_plugin->getContextMapping();
    $display = ['canvas_required_contexts' => []];
    foreach ($required_definitions as $context_slot => $definition) {
      // The slot uses its stored/auto-assigned mapping when present, otherwise
      // the single candidate core would auto-assign to a required context.
      $matching_contexts = $this->contextHandler->getMatchingContexts($gathered_contexts, $definition);
      $assigned_id = $mapping[$context_slot] ?? (\count($matching_contexts) === 1 ? \array_key_first($matching_contexts) : NULL);
      $assigned_label = $assigned_id !== NULL && isset($gathered_contexts[$assigned_id])
        ? $gathered_contexts[$assigned_id]->getContextDefinition()->getLabel()
        : $this->t('Not available');
      $display['canvas_required_contexts'][$context_slot] = [
        '#type' => 'item',
        '#title' => $definition->getLabel() ?? $context_slot,
        '#markup' => $assigned_label,
      ];
    }
    return $display;
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(ComponentEntity $component): array {
    // These 2 block plugin interfaces cannot be previewed (regardless of which
    // implementation) because they depend on the global context.
    // @see `type: canvas.page_region.*`'s `component_trees.tree.presence`
    $block_plugin = $this->getBlockPlugin();
    if ($block_plugin instanceof TitleBlockPluginInterface || $block_plugin instanceof MessagesBlockPluginInterface) {
      return ['build' => []];
    }

    // The component library preview is rendered without a host entity, so a
    // block whose required contexts cannot be satisfied by the globally
    // available contexts alone cannot be previewed here. Offer an empty preview
    // instead of crashing the whole component list: the block is still
    // placeable, and whether its contexts are satisfiable for the entity being
    // edited is decided by the library filtering against the real host entity.
    // @see \Drupal\canvas\Controller\ApiConfigControllers::list()
    if ($block_plugin instanceof ContextAwarePluginInterface
      && !$this->contextGatherer->hasRequiredContextsSatisfied($block_plugin->getContextDefinitions(), $this->contextGatherer->getPopulatedContexts())) {
      return ['build' => []];
    }

    return ['build' => $this->renderComponent([], $component->getSlotDefinitions(), $component->uuid(), TRUE)];
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, ComponentEntity $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    // @todo Remove this in https://www.drupal.org/project/canvas/issues/3500994#comment-15951774 — the client should send the right data.
    $defaults = $component->get('settings')['default_settings'];
    // 💡 The client side's simplest model (`ComponentModel`) is used for
    // components of this ("block") ComponentSource: because block plugins
    // provide their own input UX, no evaluation of PropSources is needed.
    // @see docs/components.md#3.2.1
    // @see DynamicComponent type-script definition.
    // @see ComponentModel type-script definition.
    // @todo Make this less confusing in https://www.drupal.org/project/canvas/issues/3521041
    if ($client_model === ['resolved' => []]) {
      // This is the default case for a new component, initialize with defaults.
      // @see addNewComponentToLayout AppThunk in layoutModelSlice.ts
      $client_model['resolved'] = $defaults;
    }
    $input = $client_model['resolved'] ?? [];
    $label_input = \array_intersect_key($input, \array_flip([
      // We remove these from the form in ::submitBlockConfigurationForm and
      // ::buildConfigurationForm, so let's make note of their values before
      // submitting the form.
      'label',
      'label_display',
    ]));

    $block_plugin = $this->getBlockPlugin();
    $this->submitBlockConfigurationForm($block_plugin, $component_instance_uuid, $input, $host_entity);
    $input = $label_input + \array_intersect_key(
      // Ignore any keys from configuration that don't exist in configuration.
      $block_plugin->getConfiguration(),
      // But also ignore any submitted values for label/label_display as we
      // don't show those fields to the user and will fill them from
      // $label_input.
      \array_diff_key($defaults, \array_flip(['label', 'label_display']))
    );

    // We don't need to store these as they can be recalculated based on the
    // plugin ID.
    $input += $defaults;
    unset($input['provider'], $input['id']);

    // A context-aware block stores its context assignments in
    // `context_mapping`. The merge above keeps only keys present in the default
    // settings, so it drops `context_mapping` whenever the block declares no
    // default for it. Recover the assignments the anonymous form produced when
    // submitting: the context-assignment element auto-assigns a single
    // candidate required context and applies any chosen mapping, so this
    // covers both a freshly placed block and an edited one. Empty selections
    // (an unset optional context) are filtered out so no mapping is stored.
    if ($block_plugin instanceof ContextAwarePluginInterface && $block_plugin->getContextDefinitions() !== []) {
      $context_mapping = \array_filter($block_plugin->getConfiguration()['context_mapping'] ?? []);
      if ($context_mapping !== []) {
        $input['context_mapping'] = $context_mapping;
      }
      else {
        unset($input['context_mapping']);
      }
    }
    return $input;
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    if (!$this->requiresExplicitInput()) {
      return new ConstraintViolationList();
    }
    $block_plugin = $this->getBlockPlugin();
    $plugin_id = $block_plugin->getPluginId();
    $definition = $block_plugin->getPluginDefinition();
    $form_violations = $this->autoSaveManager->getComponentInstanceFormViolations($component_instance_uuid);
    \assert(\is_array($definition));
    // We don't store these, but they're needed for validation.
    $inputValues += [
      'id' => $plugin_id,
      'provider' => $definition['provider'] ?? 'system',
    ];
    $typed_data = $this->typedConfigManager->createFromNameAndData('block.settings.' . $plugin_id, $inputValues);
    $violations = $typed_data->validate();
    $violations->addAll($form_violations);
    return $this->translateConstraintPropertyPathsAndRoot(['' => 'inputs.'], $violations);
  }

  protected function submitBlockConfigurationForm(
    BlockPluginInterface $block_plugin,
    string $component_instance_uuid,
    array $input,
    ?FieldableEntityInterface $host_entity = NULL,
  ): FormStateInterface {
    $form_state = new FormState();
    // Some plugins make use of form elements that modify the submitted values
    // during validation. Additionally, the block plugin might make changes to
    // the values from the form in its ::blockSubmit or ::blockValidate method.
    // To call these methods we make an anonymous form class with the block
    // plugin form in a sub-form and submit it with the values from the client
    // model.
    // Seed the gathered contexts so the block's own form adds the
    // context-assignment element and, on submission, auto-assigns a
    // single-candidate required context and applies any chosen mapping to the
    // block plugin's `context_mapping`.
    // @see \Drupal\Core\Block\BlockBase::submitConfigurationForm()
    $gathered_contexts = $this->gatherContextsFor($block_plugin, $host_entity);
    $form_object = $this->buildAnonymousFormForBlockPlugin($component_instance_uuid, $block_plugin, $gathered_contexts);
    $form_state = ClientFormSubmissionHelper::prepareProgrammedFormStateForFormObject($form_state, $form_object)
      // With the values provided from the front-end.
      ->setValues(['settings' => $input]);
    $this->formBuilder->submitForm($form_object, $form_state);
    return $form_state;
  }

  protected function getPluginForm(BlockPluginInterface $block): PluginFormInterface {
    // Mirror the logic from BlockForm::getPluginForm.
    if ($block instanceof PluginWithFormsInterface) {
      return $this->pluginFormFactory->createInstance($block, 'configure');
    }
    return $block;
  }

  /**
   * Gathers the contexts a context-aware block's form needs, if applicable.
   *
   * @param \Drupal\Core\Block\BlockPluginInterface $block_plugin
   *   The block plugin.
   * @param \Drupal\Core\Entity\EntityInterface|null $host_entity
   *   The host entity whose tree is being edited, or NULL when there is none.
   *
   * @return array<string, \Drupal\Core\Plugin\Context\ContextInterface>
   *   The gathered contexts keyed by context id, or an empty array when the
   *   block declares no contexts. Seed these as the `gathered_contexts` form
   *   state temporary value so the block's own form renders and applies its
   *   `context_mapping` element.
   *
   * @see \Drupal\Core\Block\BlockBase::buildConfigurationForm()
   */
  private function gatherContextsFor(BlockPluginInterface $block_plugin, ?EntityInterface $host_entity): array {
    if (!$block_plugin instanceof ContextAwarePluginInterface || $block_plugin->getContextDefinitions() === []) {
      return [];
    }
    return $this->contextGatherer->getPopulatedContexts($host_entity);
  }

  protected function buildAnonymousFormForBlockPlugin(string $form_id, BlockPluginInterface $block_plugin, array $gathered_contexts = []): FormInterface {
    // Build and return an ephemeral form object that can be used with the form
    // builder to validate and submit block plugin forms. This gives any block
    // plugins that have logic in these methods a chance to modify the client
    // model when transforming it to input and vice versa.
    return new class(
      $form_id,
      $block_plugin,
      $this->pluginFormFactory,
      $this->autoSaveManager,
      $gathered_contexts,
    ) implements FormInterface {

      public function __construct(
        protected readonly string $formId,
        protected readonly BlockPluginInterface $blockPlugin,
        protected readonly PluginFormFactoryInterface $formFactory,
        protected readonly AutoSaveManager $autoSaveManager,
        protected readonly array $gatheredContexts,
      ) {
      }

      public function getFormId(): string {
        return $this->formId;
      }

      public function buildForm(array $form, FormStateInterface $form_state): array {
        $form['#tree'] = TRUE;
        $form['settings'] = [];
        // Seed the gathered contexts so a context-aware block's form adds the
        // `context_mapping` element: it round-trips into the client model
        // (::inputToClientModel) and, on submission, auto-assigns a
        // single-candidate required context and applies any chosen mapping
        // (::clientModelToInput).
        // @see \Drupal\Core\Block\BlockBase::buildConfigurationForm()
        if ($this->gatheredContexts !== []) {
          $form_state->setTemporaryValue('gathered_contexts', $this->gatheredContexts);
        }
        $subform_state = SubformState::createForSubform($form['settings'], $form, $form_state);
        $form['settings'] = $this->getPluginForm($this->blockPlugin)
          ->buildConfigurationForm($form['settings'], $subform_state);
        foreach ([
          'label',
          'label_display',
          'admin_label',
          'provider',
        ] as $field) {
          // Remove these fields and let the defaults be added from
          // the component's default_settings in ::clientModelToInput.
          unset($form['settings'][$field]);
        }
        return $form;
      }

      public function validateForm(array &$form, FormStateInterface $form_state): void {
        // Give the block plugin a chance to perform any logic in its
        // ::blockValidate method.
        $checkboxes = ClientFormSubmissionHelper::spotCheckboxesParents($form['settings']);
        $input = $form_state->getUserInput();
        foreach ($checkboxes as $parents) {
          $value = NestedArray::getValue($input, $parents);
          // Unchecked checkboxes are expected to be set with value NULL. For
          // a normal form submission, this is done for us by the Form
          // Builder. But for a programmatic form submission, this needs to be
          // done manually. However, in this case we're not dealing with a
          // programmatic form submission so it is appropriate to set the value
          // directly to a boolean both in the form state values and user input.
          // We use empty as this covers NULL, FALSE, 0 and '0' by design.
          // @see \Drupal\Core\Form\FormBuilder::handleInputElement
          NestedArray::setValue($input, $parents, !empty($value) && $value !== 'false');
          $form_state->setValue($parents, !empty($value) && $value !== 'false');
        }
        $form_state->setUserInput($input);
        $this->getPluginForm($this->blockPlugin)->validateConfigurationForm(
          $form['settings'],
          SubformState::createForSubform($form['settings'], $form, $form_state),
        );
        $errors = $form_state->getErrors();
        if (\count($errors) > 0) {
          $violations_list = new ConstraintViolationList();
          foreach ($errors as $element_path => $error) {
            $parents = \explode('][', $element_path);
            $element = NestedArray::getValue($form, $parents);
            // If validation changed the user's input but still resulted in an
            // error, revert back to the user-provided value so that is stored
            // in the temp store.
            // Check for #required errors.
            $form_state->setValue($parents, NestedArray::getValue($input, $parents));
            if (($error instanceof TranslatableMarkup && $error->getUntranslatedString() === '@name field is required.') ||
              ((string) $error === ($element['#required_error'] ?? NULL))) {
              // Ignore the error.
              continue;
            }
            // Remove the 'settings' key added in the ::buildForm method.
            \array_shift($parents);
            $violations_list->add(new ConstraintViolation(
            // Some errors may contain markup from the user of % placeholders in
            // TranslatableMarkup. We just want the plain text version.
              PlainTextOutput::renderFromHtml((string) $error),
              NULL,
              [],
              NULL,
              \implode('.', $parents),
              $form_state->getValue($parents),
            ));
          }
          // Store block form validation errors so they can be used later during
          // component validation.
          $this->autoSaveManager->saveComponentInstanceFormViolations($this->formId, $violations_list);
          // Clear errors so that ::submitForm is still called - we're not using
          // this to store any data etc, we're just making sure that any block
          // plugins that have logic in the ::blockValidate and ::blockSubmit
          // methods get a chance to perform that logic during conversion from
          // the client model (form values) to input (block settings that comply
          // to the config schema).
          $form_state->clearErrors();
          return;
        }
        // No violations, so clean the auto-save manager in case previous form
        // violations existed and were stored.
        $this->autoSaveManager->saveComponentInstanceFormViolations($this->formId);
      }

      public function submitForm(array &$form, FormStateInterface $form_state): void {
        // Give the block plugin a chance to perform any logic in its
        // ::blockSubmit method. For a context-aware block this also applies the
        // submitted `context_mapping` to the plugin configuration.
        // @see \Drupal\Core\Block\BlockBase::submitConfigurationForm()
        $this->getPluginForm($this->blockPlugin)->submitConfigurationForm(
          $form['settings'],
          SubformState::createForSubform($form['settings'], $form, $form_state),
        );
      }

      protected function getPluginForm(BlockPluginInterface $block): PluginFormInterface {
        // Mirror the logic from BlockForm::getPluginForm.
        if ($block instanceof PluginWithFormsInterface) {
          return $this->formFactory->createInstance($block, 'configure');
        }
        return $block;
      }

    };
  }

}
