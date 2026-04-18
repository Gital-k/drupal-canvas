<?php

declare(strict_types=1);

namespace Drupal\canvas\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\ComponentIncompatibilityReasonRepository;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Canvas component management.
 */
final class CanvasCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentAudit $componentAudit,
    private readonly ComponentIncompatibilityReasonRepository $reasonRepository,
    private readonly ComponentSourceManager $componentSourceManager,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'canvas:component:list', aliases: ['canvas-component-list'])]
  #[CLI\Help(description: 'List all Canvas components.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'label' => 'Label',
    'source' => 'Source type',
    'status' => 'Status',
    'versions' => 'Versions',
    'usage' => 'Usage',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'label', 'source', 'status', 'versions', 'usage'])]
  public function componentList(): RowsOfFields {
    /** @var \Drupal\canvas\Entity\Component[] $entities */
    $entities = $this->entityTypeManager->getStorage('component')->loadMultiple();
    $rows = [];
    foreach ($entities as $entity) {
      $source = $entity->getComponentSource();
      $usage = $this->componentAudit->getConfigEntityUsageCount($entity)
        + count($this->componentAudit->getContentRevisionsUsingComponent($entity));
      $rows[] = [
        'id' => $entity->id(),
        'label' => $entity->label(),
        'source' => (string) $source->getPluginDefinition()['label'],
        'status' => $entity->status() ? 'Enabled' : 'Disabled',
        'versions' => count($entity->getVersions()),
        'usage' => $usage,
      ];
    }
    return new RowsOfFields($rows);
  }

  #[CLI\Command(name: 'canvas:component:status', aliases: ['canvas-component-status'])]
  #[CLI\Help(description: 'List Canvas components that have incompatibility reasons.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'Component',
    'reasons' => 'Reasons',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'reasons'])]
  public function componentStatus(): RowsOfFields {
    $all_reasons = $this->reasonRepository->getReasons();
    $rows = [];
    foreach ($all_reasons as $source_reasons) {
      foreach ($source_reasons as $component_id => $component_reasons) {
        $entity = Component::load($component_id);
        if ($entity instanceof Component && !$entity->status()) {
          continue;
        }
        $component_reasons = \is_string($component_reasons) ? [$component_reasons] : $component_reasons;
        $rows[] = [
          'id' => $component_id,
          'reasons' => implode(', ', $component_reasons),
        ];
      }
    }
    return new RowsOfFields($rows);
  }

  #[CLI\Command(name: 'canvas:component:generate', aliases: ['canvas-component-generate'])]
  #[CLI\Help(description: 'Generate Canvas component config entities from discovered sources.')]
  #[CLI\Option(name: 'source', description: 'Limit generation to a specific ComponentSource plugin ID.')]
  public function componentGenerate(array $options = ['source' => NULL]): void {
    $this->componentSourceManager->generateComponents($options['source'] ?? NULL);
    $this->logger()->success(dt('Canvas components generated successfully.'));
  }

}
