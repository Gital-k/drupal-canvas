<?php

declare(strict_types=1);

namespace Drupal\canvas\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
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
  #[CLI\Usage(name: 'canvas:component:list', description: 'List all Canvas components in a table.')]
  #[CLI\Usage(name: 'canvas:component:list --format=json', description: 'List all Canvas components as JSON.')]
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
  #[CLI\Help(description: 'Report the compatibility verdict for Canvas components. State is one of compatible, incompatible, or disabled. Exits non-zero when any reported component is incompatible, so it can gate CI.')]
  #[CLI\Argument(name: 'componentId', description: 'A component ID to report on. When omitted, every component is reported.')]
  #[CLI\Usage(name: 'canvas:component:status', description: 'Report the verdict for every component.')]
  #[CLI\Usage(name: 'canvas:component:status sdc.byte_theme.card', description: 'Report the verdict for a single component.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'Component',
    'state' => 'State',
    'reasons' => 'Reasons',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'state', 'reasons'])]
  public function componentStatus(?string $componentId = NULL): CommandResult {
    $reasons_by_id = $this->reasonsByComponentId();
    $entities = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID)->loadMultiple();

    if ($componentId !== NULL) {
      $entity = $entities[$componentId] ?? NULL;
      if (!$entity instanceof Component && !\array_key_exists($componentId, $reasons_by_id)) {
        throw new \InvalidArgumentException(\sprintf('Unknown component: %s', $componentId));
      }
      $component_ids = [$componentId];
    }
    else {
      $component_ids = \array_unique([...\array_keys($entities), ...\array_keys($reasons_by_id)]);
      \sort($component_ids);
    }

    $rows = [];
    $has_incompatible = FALSE;
    foreach ($component_ids as $id) {
      $entity = $entities[$id] ?? NULL;
      [$state, $reasons] = self::resolveComponentState($entity, $reasons_by_id[$id] ?? []);
      if ($state === 'incompatible') {
        $has_incompatible = TRUE;
      }
      $rows[] = [
        'id' => $id,
        'state' => $state,
        'reasons' => $reasons,
      ];
    }

    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $has_incompatible ? self::EXIT_FAILURE : self::EXIT_SUCCESS);
  }

  /**
   * Flattens stored incompatibility reasons keyed by component ID.
   *
   * @return array<string, string[]>
   *   Reason lists keyed by component config entity ID.
   */
  private function reasonsByComponentId(): array {
    $reasons_by_id = [];
    foreach ($this->reasonRepository->getReasons() as $source_reasons) {
      foreach ($source_reasons as $component_id => $reasons) {
        $reasons_by_id[$component_id] = \is_string($reasons) ? [$reasons] : $reasons;
      }
    }
    return $reasons_by_id;
  }

  /**
   * Resolves a component's compatibility state and human-readable reasons.
   *
   * A manual disable is stored as a reason but is a site-owner decision, not an
   * incompatibility, so it maps to the disabled state rather than incompatible.
   *
   * @param \Drupal\canvas\Entity\Component|null $entity
   *   The component config entity, or NULL when none exists yet.
   * @param string[] $reasons
   *   The stored incompatibility reasons for the component.
   *
   * @return array{string, string}
   *   The state (compatible, incompatible, or disabled) and reason text.
   */
  private static function resolveComponentState(?Component $entity, array $reasons): array {
    $manually_disabled = $reasons === [ComponentIncompatibilityReasonRepository::MANUALLY_DISABLED_REASON];
    if ($reasons !== [] && !$manually_disabled) {
      return ['incompatible', implode(', ', $reasons)];
    }
    if ($manually_disabled || ($entity instanceof Component && !$entity->status())) {
      return ['disabled', $manually_disabled ? ComponentIncompatibilityReasonRepository::MANUALLY_DISABLED_REASON : ''];
    }
    return ['compatible', ''];
  }

  #[CLI\Command(name: 'canvas:component:generate', aliases: ['canvas-component-generate'])]
  #[CLI\Help(description: 'Generate Canvas component config entities from discovered sources. Also reconciles incompatibility reasons: clears auto-disable reasons for components that now meet requirements and stores reasons for those that do not. A site owner\'s manual disable is preserved.')]
  #[CLI\Usage(name: 'canvas:component:generate', description: 'Generate component entities for all sources.')]
  #[CLI\Usage(name: 'canvas:component:generate --source=sdc', description: 'Generate only SDC-sourced component entities.')]
  #[CLI\Option(name: 'source', description: 'Limit generation to a specific ComponentSource plugin ID.')]
  public function componentGenerate(array $options = ['source' => NULL]): void {
    $this->componentSourceManager->generateComponents($options['source'] ?? NULL);
    $this->logger()?->success(dt('Canvas components generated successfully.'));
  }

}
