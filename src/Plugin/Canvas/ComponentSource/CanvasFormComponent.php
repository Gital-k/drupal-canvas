<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\Entity\CanvasForm;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\canvas\CanvasForm\CanvasFormRenderer;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Exposes Canvas Form entities as page builder components.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Canvas Forms'),
  supportsImplicitInputs: FALSE,
  discovery: CanvasFormDiscovery::class,
  updater: FALSE,
  discoveryCacheTags: ['config:canvas_form_list'],
)]
final class CanvasFormComponent extends ComponentSourceBase implements ContainerFactoryPluginInterface {

  public const SOURCE_PLUGIN_ID = 'canvas_form';

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly FormBuilderInterface $formBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(FormBuilderInterface::class),
    );
  }

  public function isBroken(): bool {
    return CanvasForm::load($this->getSourceSpecificComponentId()) === NULL;
  }

  public function getReferencedPluginClass(): ?string {
    return NULL;
  }

  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup('A Canvas Form');
  }

  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    $form = CanvasForm::load($this->getSourceSpecificComponentId());
    if (!$form) {
      return ['#markup' => ''];
    }

    if ($isPreview) {
      $form_build = $this->formBuilder->getForm(CanvasFormRenderer::class, $form);
      $form_build['#attributes']['inert'] = TRUE;
      return $form_build;
    }

    return $this->formBuilder->getForm(CanvasFormRenderer::class, $form);
  }

  public function requiresExplicitInput(): bool {
    return FALSE;
  }

  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return [];
  }

  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    return [];
  }

  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    return $explicit_input;
  }

  public function inputToClientModel(array $explicit_input): array {
    return ['resolved' => $explicit_input];
  }

  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    return $client_model['resolved'] ?? [];
  }

  public function buildComponentInstanceForm(array $form, FormStateInterface $form_state, Component $component, string $component_instance_uuid = '', array $inputValues = [], ?EntityInterface $entity = NULL, array $settings = []): array {
    return $form;
  }

  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    return new ConstraintViolationList();
  }

  public function getClientSideInfo(Component $component): array {
    $form = CanvasForm::load($this->getSourceSpecificComponentId());
    return [
      'build' => $form ? $this->formBuilder->getForm(CanvasFormRenderer::class, $form) : [],
      'propSources' => [],
    ];
  }

  protected function getExplicitInputDefinitions(): array {
    return [];
  }

  public function calculateDependencies(): array {
    $form = CanvasForm::load($this->getSourceSpecificComponentId());
    if ($form) {
      return ['config' => [$form->getConfigDependencyName()]];
    }
    return [];
  }

}
