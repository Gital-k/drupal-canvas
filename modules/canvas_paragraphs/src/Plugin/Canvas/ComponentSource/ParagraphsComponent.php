<?php

declare(strict_types=1);

namespace Drupal\canvas_paragraphs\Plugin\Canvas\ComponentSource;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Block\MainContentBlockPluginInterface;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityDisplayBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Plugin\Validation\Constraint\FullyValidatableConstraint;
use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\Controller\EntityFormTrait;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Component as ComponentEntity;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\field\FieldConfigInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphsTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines a component source based on block plugins.
 *
 * @todo Context mappings.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Paragraphs'),
  supportsImplicitInputs: TRUE,
  discovery: ParagraphsComponentDiscovery::class,
)]
final class ParagraphsComponent extends ComponentSourceBase implements ContainerFactoryPluginInterface {

  use ConstraintPropertyPathTranslatorTrait;
  use EntityFormTrait;

  public const SOURCE_PLUGIN_ID = 'paragraphs';
  public const EXPLICIT_INPUT_NAME = 'settings';

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected readonly AccountInterface $currentUser,
    protected readonly TypedConfigManagerInterface $typedConfigManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FormBuilderInterface $formBuilder,
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
      $container->get(AccountInterface::class),
      $container->get(TypedConfigManagerInterface::class),
      $container->get(EntityTypeManagerInterface::class),
      $container->get('form_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    return $this->getParagraphType() === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    return Paragraph::class;
  }

  /**
   * {@inheritdoc}
   */
  protected function getParagraphType(): ParagraphsTypeInterface {
    $paragraph_type = $this->entityTypeManager->getStorage('paragraphs_type')->load($this->configuration['local_source_id']);
    assert($paragraph_type instanceof ParagraphsTypeInterface);
    return $paragraph_type;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return NestedArray::mergeDeep($this->getParagraphType()->getDependencies() ?? [], ['module' => ['canvas_paragraphs']]);
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    $paragraph_type = $this->getParagraphType();
    return new TranslatableMarkup('Paragraph: %name', [
      '%name' => $paragraph_type->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE): array {
    $paragraph_type = $this->getParagraphType();

    $paragraph = Paragraph::create(['type' => $paragraph_type->id()]);
    foreach ($paragraph->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition instanceof FieldConfigInterface) {
        $paragraph->get($field_name)->generateSampleItems();
      }
    }

    $build = $this->entityTypeManager->getViewBuilder('paragraph')->view($paragraph);

    return $build;
  }

  protected function getExplicitInputDefinitions(): array {
    $paragraph_type_id = $this->getParagraphType()->id();

    $definition = $this->typedConfigManager
      ->getDefinition('paragraph.settings.' . $paragraph_type_id);

    if (!is_array($definition)) {
      return [];
    }

    return self::removeConfigSchemaLabels($definition);
  }

  private static function removeConfigSchemaLabels(array $config_schema): array {
    $normalized = [];
    foreach ($config_schema as $key => $value) {
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
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return [];
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
      assert(!$this->requiresExplicitInput());
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
    // @see SimpleComponent type-script definition.
    // @see ComponentModel type-script definition.
    return ['resolved' => $explicit_input];
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
    $paragraph_type = $this->getParagraphType();
    $paragraph = Paragraph::create(['type' => $paragraph_type->id()]);

    foreach ($paragraph->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition instanceof FieldConfigInterface) {
        $paragraph->get($field_name)->generateSampleItems();
      }
    }

    $form_display = EntityFormDisplay::collectRenderDisplay($paragraph, 'default');
    assert($form_display instanceof EntityFormDisplay);

    $form_display->buildForm($paragraph, $form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(ComponentEntity $component): array {
    // These 2 block plugin interfaces cannot be previewed (regardless of which
    // implementation) because they depend on the global context.
    // @see `type: experience_builder.page_region.*`'s `component_trees.tree.presence`
    $block_plugin = $this->getParagraphType();
    if ($block_plugin instanceof TitleBlockPluginInterface || $block_plugin instanceof MessagesBlockPluginInterface) {
      return ['build' => []];
    }

    return ['build' => $this->renderComponent([], $component->getSlotDefinitions(), $component->uuid(), TRUE)];
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, ComponentEntity $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    $input = [];
    return $input;
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    //if (!$this->requiresExplicitInput()) {
      return new ConstraintViolationList();
    // }

    //return $this->translateConstraintPropertyPathsAndRoot(['' => \sprintf('inputs.%s.', $component_instance_uuid)], $typed_data->validate());
  }

}
