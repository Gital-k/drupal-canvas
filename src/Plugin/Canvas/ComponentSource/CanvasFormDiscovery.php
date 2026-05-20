<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\canvas\Entity\CanvasForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Discovers published CanvasForm entities for use as page builder components.
 *
 * @internal
 */
final class CanvasFormDiscovery implements ComponentCandidatesDiscoveryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new static($container->get(EntityTypeManagerInterface::class));
  }

  public function discover(): array {
    /** @var \Drupal\canvas\Entity\CanvasForm[] $forms */
    $forms = $this->entityTypeManager->getStorage(CanvasForm::ENTITY_TYPE_ID)->loadMultiple();
    return array_filter($forms, fn(CanvasForm $form) => $form->status());
  }

  public function checkRequirements(string $source_specific_id): void {
    $forms = $this->discover();
    if (!isset($forms[$source_specific_id])) {
      throw new ComponentDoesNotMeetRequirementsException(['Form not found or not published.']);
    }
  }

  public function computeComponentSettings(string $source_specific_id): array {
    return [];
  }

  public function computeInitialComponentProvider(string $source_specific_id): ?string {
    return NULL;
  }

  public function computeInitialComponentStatus(string $source_specific_id): bool {
    return TRUE;
  }

  public function computeCurrentComponentMetadata(string $source_specific_id): array {
    $forms = $this->discover();
    $form = $forms[$source_specific_id] ?? NULL;
    return ['label' => $form ? ((string) $form->label()) : $source_specific_id];
  }

  public static function getComponentConfigEntityId(string $source_specific_id): string {
    return CanvasFormComponent::SOURCE_PLUGIN_ID . '.' . $source_specific_id;
  }

  public static function getSourceSpecificComponentId(string $component_id): string {
    return substr($component_id, strlen(CanvasFormComponent::SOURCE_PLUGIN_ID . '.'));
  }

}
