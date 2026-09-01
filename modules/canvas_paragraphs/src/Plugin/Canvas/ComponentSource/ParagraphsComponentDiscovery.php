<?php

declare(strict_types=1);

namespace Drupal\canvas_paragraphs\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent
 * @see \Drupal\canvas\Block\BlockManagerDecorator
 * @internal
 */
final class ParagraphsComponentDiscovery implements ComponentCandidatesDiscoveryInterface {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function discover(): array {
    return $this->entityTypeManager
      ->getStorage('paragraphs_type')
      ->loadMultiple();
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(string $source_specific_id): void {
    // TODO: Implement checkRequirements()
  }

  /**
   * {@inheritdoc}
   */
  public function computeComponentSettings(string $source_specific_id): array {
    return [
      'default_settings' => [
        'paragraphs_type' => $source_specific_id,
      ]
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentProvider(string $source_specific_id): ?string {
    return 'canvas_paragraphs';
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentStatus(string $source_specific_id): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function computeCurrentComponentMetadata(string $source_specific_id): array {
    $paragraph_type = $this->discover()[$source_specific_id];
    return [
      'label' => $paragraph_type->label(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getComponentConfigEntityId(string $source_specific_component_id): string {
    return \sprintf('%s.%s',
      ParagraphsComponent::SOURCE_PLUGIN_ID,
      str_replace(PluginBase::DERIVATIVE_SEPARATOR, '.', $source_specific_component_id),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSourceSpecificComponentId(string $component_id): string {
    $prefix = ParagraphsComponent::SOURCE_PLUGIN_ID . '.';
    \assert(str_starts_with($component_id, $prefix));
    return str_replace('.', PluginBase::DERIVATIVE_SEPARATOR, substr($component_id, strlen($prefix)));
  }

}
