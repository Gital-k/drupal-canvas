<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel;

final class ClientDataModel {

  /**
   * @var array<string, array>
   */
  protected array $regionMap;

  /**
   * @var array<string, int>
   */
  protected array $regionDeltas;

  public function __construct(
    public readonly array $layout,
    public readonly array|\stdClass $model,
    public readonly array $entityFormFields,
    public readonly ?string $clientInstanceId = NULL,
  ) {
    // Validate the passed layout.
    foreach ($this->layout as $delta => $layout_region) {
      \assert(\count(\array_intersect([
        'nodeType',
        'id',
        'name',
        'components',
      ], \array_keys($layout_region))) === 4);
      \assert($layout_region['nodeType'] === 'region');
      \assert(\is_array($layout_region['components']));
      $this->regionMap[$layout_region['id']] = $layout_region;
      $this->regionDeltas[$layout_region['id']] = $delta;
    }
  }

  protected static function duplicate(array $node, array $uuidMap): array {
    $children = self::getChildrenFromNode($node);
    if (self::nodeIsComponent($node)) {
      $uuid = $node['uuid'];
      $replacement = \array_replace($node, ['uuid' => $uuidMap[$uuid]]);
      foreach ($children as $delta => $child) {
        $children[$delta] = self::duplicate($child, $uuidMap);
      }
      return self::setChildrenForNode($replacement, $children);
    }
    \assert(self::nodeIsSlot($node));
    [$parentComponentUuid, $slotName] = \explode('/', $node['id']);
    $replacement = \array_replace($node, ['id' => \implode('/', [$uuidMap[$parentComponentUuid], $slotName])]);
    foreach ($children as $delta => $child) {
      $children[$delta] = self::duplicate($child, $uuidMap);
    }
    return self::setChildrenForNode($replacement, $children);
  }

  protected static function shiftChild(array $node, string $componentInstanceUuid, ShiftComponentDirection $direction): ?array {
    try {
      $children = self::getChildrenFromNode($node);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    foreach ($children as $delta => $child) {
      if (self::nodeIsComponent($child) && $child['uuid'] === $componentInstanceUuid) {
        return self::setChildrenForNode($node, $direction->apply($children, (int) $delta));
      }
    }
    // No such child.
    return $node;
  }

  public function getRegion(string $region): array {
    if (!\array_key_exists($region, $this->regionMap)) {
      throw new \OutOfBoundsException(\sprintf('Region %s does not exist', $region));
    }
    return $this->regionMap[$region];
  }

  protected static function replaceSlot(array $parentComponent, string $slotName, array $replacement): array {
    foreach ($parentComponent['slots'] ?? [] as $delta => $slot) {
      if ($slot['name'] === $slotName) {
        $parentComponent['slots'][$delta] = $replacement;
      }
    }
    return $parentComponent;
  }

  protected static function replaceComponent(array $parentNode, string $componentUuid, array $replacement): array {
    $children = self::getChildrenFromNode($parentNode);
    foreach ($children as $delta => $child) {
      if (self::nodeIsComponent($child) && $child['uuid'] === $componentUuid) {
        $children[$delta] = $replacement;
        return self::setChildrenForNode($parentNode, $children);
      }
      $children[$delta] = self::replaceComponent($child, $componentUuid, $replacement);
    }
    return self::setChildrenForNode($parentNode, $children);
  }

  public function getRegionForComponentInstance(string $componentInstanceUuid): ?string {
    // @todo Replace with \array_find once we have access to the polyfill.
    $regionForComponent = array_filter(
      $this->regionMap,
      function (array $item) use ($componentInstanceUuid) {
        foreach (self::getChildrenFromNode($item) as $componentData) {
          if (self::componentInstanceExistInComponentData($componentData, $componentInstanceUuid)) {
            return TRUE;
          }
        }
        return FALSE;
      }
    );

    // @todo Fix in https://drupal.org/i/3535435 (Review and remove NULL if necessary).
    return (count($regionForComponent) === 1) ? (string) key($regionForComponent) : NULL;
  }

  protected static function componentInstanceExistInComponentData(array $componentData, string $componentInstanceUuid): bool {
    return self::getComponentByUuid($componentData, $componentInstanceUuid) !== NULL;
  }

  protected static function getComponentByUuid(array $node, string $componentInstanceUuid): ?array {
    if (($node['uuid'] ?? NULL) === $componentInstanceUuid) {
      return $node;
    }
    try {
      $children = self::getChildrenFromNode($node);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    foreach ($children as $child) {
      if ($component = self::getComponentByUuid($child, $componentInstanceUuid)) {
        return $component;
      }
    }
    return NULL;
  }

  protected static function getParentNodeByComponentId(array $node, string $childComponentUuid): ?array {
    try {
      $children = self::getChildrenFromNode($node);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    foreach ($children as $child) {
      if (self::nodeIsComponent($child) && $child['uuid'] === $childComponentUuid) {
        return $node;
      }
      if ($parent = self::getParentNodeByComponentId($child, $childComponentUuid)) {
        return $parent;
      }
    }
    return NULL;
  }

  protected static function collectChildComponentInstanceIds(array $node): array {
    $instanceIds = [];
    try {
      $children = self::getChildrenFromNode($node);
    }
    catch (\InvalidArgumentException) {
      return [];
    }

    foreach ($children as $child) {
      if (self::nodeIsComponent($child)) {
        $instanceIds[] = $child['uuid'];
      }
      $instanceIds = \array_merge($instanceIds, self::collectChildComponentInstanceIds($child));
    }
    return $instanceIds;
  }

  protected static function removeComponentByUuid(array $node, string $uuid): array {
    $children = self::getChildrenFromNode($node);
    foreach ($children as $delta => $child) {
      if (self::nodeIsComponent($child) && $child['uuid'] === $uuid) {
        unset($children[$delta]);
        return self::setChildrenForNode($node, $children);
      }
      $children[$delta] = self::removeComponentByUuid($child, $uuid);
    }
    return self::setChildrenForNode($node, $children);
  }

  /**
   * @throws \InvalidArgumentException
   */
  protected static function setChildrenForNode(array $node, array $children): array {
    return match($node['nodeType'] ?? NULL) {
      'region', 'slot' => \array_replace($node, ['components' => \array_values($children)]),
      'component' => \array_replace($node, ['slots' => \array_values($children)]),
      default => throw new \InvalidArgumentException(\sprintf('Unknown nodeType: %s', $node['nodeType'] ?? 'null')),
    };
  }

  /**
   * @throws \InvalidArgumentException
   */
  protected static function getChildrenFromNode(array $node): array {
    return match($node['nodeType'] ?? NULL) {
      'region', 'slot' => $node['components'] ?? [],
      'component' => $node['slots'] ?? [],
      default => throw new \InvalidArgumentException(\sprintf('Unknown nodeType: %s', $node['nodeType'] ?? 'null')),
    };
  }

  protected static function nodeIsComponent(array $node): bool {
    return $node['nodeType'] === 'component';
  }

  protected static function nodeIsRegion(array $node): bool {
    return $node['nodeType'] === 'region';
  }

  protected static function nodeIsSlot(array $node): bool {
    return $node['nodeType'] === 'slot';
  }

  public function withModelForComponent(string $componentInstanceUuid, array $model): static {
    $newModel = (array) $this->model;
    $newModel[$componentInstanceUuid] = $model;
    return new static(
      $this->layout,
      $newModel,
      $this->entityFormFields,
      $this->clientInstanceId,
    );
  }

  public function withClientInstanceId(string $clientInstanceId): static {
    return new static(
      $this->layout,
      $this->model,
      $this->entityFormFields,
      $clientInstanceId,
    );
  }

  public function hasComponentInstance(string $componentInstanceUuid): bool {
    foreach ($this->layout as $region) {
      $exists = self::componentInstanceExistInComponentData($region, $componentInstanceUuid);
      if ($exists) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function withoutComponent(mixed $componentInstanceUuid): static {
    $layout = $this->layout;
    $model = (array) $this->model;
    $model = \array_diff_key(
      $model,
      \array_flip(
        \array_merge(
          [$componentInstanceUuid],
          \array_reduce(
            $layout,
            static fn (array $carry, array $region) => [...$carry, ...self::collectChildComponentInstanceIds(self::getComponentByUuid($region, $componentInstanceUuid) ?? [])],
           []
          )
        )
      )
    );
    $oldRegionId = $this->getRegionForComponentInstance($componentInstanceUuid);
    if ($oldRegionId !== NULL) {
      $layout[$this->regionDeltas[$oldRegionId]] = self::removeComponentByUuid($this->regionMap[$oldRegionId], $componentInstanceUuid);
    }
    return new static(
      $layout,
      $model,
      $this->entityFormFields,
      $this->clientInstanceId
    );
  }

  protected static function insertAfter(array $parentNode, string $insertAfterUuid, array $node): array {
    $children = self::getChildrenFromNode($parentNode);
    foreach ($children as $delta => $child) {
      if (self::nodeIsComponent($child) && $child['uuid'] === $insertAfterUuid) {
        return self::setChildrenForNode($parentNode, [
          // Items up to this delta.
          ...array_slice($children, 0, $delta + 1),
          $node,
          // Remaining items.
          ...array_slice($children, $delta + 1),
        ]);
      }
    }
    throw new \OutOfBoundsException(\sprintf('Could not find item with UUID %s', $insertAfterUuid));
  }

  protected static function insertNodeAtFractal(array $parentNode, array $node, float $fractal): array {
    $children = self::getChildrenFromNode($parentNode);
    // Prepend to the start.
    if ($fractal < 1) {
      return self::setChildrenForNode($parentNode, [
        $node,
        ...$children,
      ]);
    }
    foreach ($children as $delta => $child) {
      if ($fractal > ($delta + 1) && $fractal < ($delta + 2)) {
        return self::setChildrenForNode($parentNode, [
          ...\array_slice($children, 0, $delta + 1),
          $node,
          ...\array_slice($children, $delta + 1),
        ]);
      }
    }
    // Append to the end.
    return self::setChildrenForNode($parentNode, [
      ...$children,
      $node,
    ]);
  }

  protected static function insertNodeInSlotAtFractal(array $parentNode, array $node, string $slot_name, float $fractal): array {
    \assert(self::nodeIsComponent($parentNode));
    $slots = self::getChildrenFromNode($parentNode);
    foreach ($slots as $delta => $slot) {
      if (($slot['name'] ?? NULL) === $slot_name) {
        $slots[$delta] = self::insertNodeAtFractal($slot, $node, $fractal);
        return self::setChildrenForNode($parentNode, $slots);
      }
    }
    throw new \OutOfBoundsException(\sprintf('Unknown slot: %s for component %s', $slot_name, $parentNode['uuid'] ?? 'n/a'));
  }

  public function withMovedComponent(string $componentInstanceUuid, string $parentRegionOrUuid, ?string $slot, float $fractal): static {
    $layout = $this->layout;
    // First we get the component to be moved.
    $oldRegionId = $this->getRegionForComponentInstance($componentInstanceUuid);
    if ($oldRegionId === NULL || !\array_key_exists($oldRegionId, $this->regionMap)) {
      // Doesn't exist - return as is.
      return $this;
    }
    $oldRegion = $this->regionMap[$oldRegionId];
    $component = $this->getComponentByUuid($oldRegion, $componentInstanceUuid);
    \assert($component !== NULL);
    if ($slot === NULL) {
      // We're moving to a region here.
      if (!\array_key_exists($parentRegionOrUuid, $this->regionDeltas)) {
        // No such region - return as is.
        return $this;
      }
      // Now we remove it.
      $layout[$this->regionDeltas[$oldRegionId]] = self::removeComponentByUuid($this->regionMap[$oldRegionId], $componentInstanceUuid);
      // And insert it into the region.
      $layout[$this->regionDeltas[$parentRegionOrUuid]] = self::insertNodeAtFractal($layout[$this->regionDeltas[$parentRegionOrUuid]], $component, $fractal);
      return new static(
        $layout,
        $this->model,
        $this->entityFormFields,
        $this->clientInstanceId,
      );
    }
    // We're moving to a slot, check the parent exists.
    $parentRegionId = $this->getRegionForComponentInstance($parentRegionOrUuid);
    if ($parentRegionId === NULL) {
      // @todo should this return an illegal operation exception so we can send
      //   a HTTP Conflict but _with_ a new layout/model so the front-end can
      //   warn and heal?
      // No such parent - return as is.
      return $this;
    }
    // Make sure the item isn't a child of itself.
    $childInstanceIds = [$componentInstanceUuid, self::collectChildComponentInstanceIds($component)];
    if (\in_array($parentRegionOrUuid, $childInstanceIds, TRUE)) {
      return $this;
    }
    // First we remove it.
    $layout[$this->regionDeltas[$oldRegionId]] = self::removeComponentByUuid($this->regionMap[$oldRegionId], $componentInstanceUuid);
    // Get the parent.
    $parentComponent = self::getComponentByUuid($layout[$this->regionDeltas[$parentRegionId]], $parentRegionOrUuid);
    \assert($parentComponent !== NULL);
    // Insert the removed node.
    $parentComponent = self::insertNodeInSlotAtFractal($parentComponent, $component, $slot, $fractal);
    // Replace it in the layout.
    $layout[$this->regionDeltas[$parentRegionId]] = self::replaceComponent($layout[$this->regionDeltas[$parentRegionId]], $parentRegionOrUuid, $parentComponent);
    return new static(
      $layout,
      $this->model,
      $this->entityFormFields,
      $this->clientInstanceId,
    );
  }

  public function withShiftedComponent(string $componentInstanceUuid, ShiftComponentDirection $direction): static {
    $layout = $this->layout;
    $regionId = $this->getRegionForComponentInstance($componentInstanceUuid);
    if ($regionId === NULL) {
      // No such component.
      return $this;
    }
    $parentNode = self::getParentNodeByComponentId($this->regionMap[$regionId], $componentInstanceUuid);
    \assert($parentNode !== NULL);
    if (self::nodeIsRegion($parentNode)) {
      // We've moving a top-level component (inside a region).
      $layout[$this->regionDeltas[$regionId]] = self::shiftChild($this->regionMap[$regionId], $componentInstanceUuid, $direction);
      return new static(
        $layout,
        $this->model,
        $this->entityFormFields,
        $this->clientInstanceId,
      );
    }
    // We're moving inside a slot.
    \assert(self::nodeIsSlot($parentNode));
    $parentNode = self::shiftChild($parentNode, $componentInstanceUuid, $direction);
    \assert(\is_array($parentNode) && \array_key_exists('id', $parentNode));
    [$parentComponentUuid, $slotName] = \explode('/', $parentNode['id']);
    $parentComponent = self::getComponentByUuid($this->regionMap[$regionId], $parentComponentUuid);
    \assert($parentComponent !== NULL);
    $parentComponent = self::replaceSlot($parentComponent, $slotName, $parentNode);
    $layout[$this->regionDeltas[$regionId]] = self::replaceComponent($layout[$this->regionDeltas[$regionId]], $parentComponentUuid, $parentComponent);
    return new static(
      $layout,
      $this->model,
      $this->entityFormFields,
      $this->clientInstanceId,
    );
  }

  public function withDuplicatedComponentTree(string $componentUuid, array $uuidMap): static {
    $regionId = self::getRegionForComponentInstance($componentUuid);
    if ($regionId === NULL) {
      return $this;
    }
    $duplicate = self::getComponentByUuid($this->regionMap[$regionId], $componentUuid);
    \assert($duplicate !== NULL);
    $duplicateIds = [
      $componentUuid,
      ...self::collectChildComponentInstanceIds($duplicate),
    ];
    $missingMapEntries = \array_diff($duplicateIds, \array_keys($uuidMap));
    if (\count($missingMapEntries) > 0) {
      throw new \InvalidArgumentException(\sprintf('Missing UUID map entries for %s', \implode(', ', $missingMapEntries)));
    }
    // Duplicate the model for components with explicit input.
    $model = (array) $this->model;
    foreach (\array_intersect(\array_keys($model), $duplicateIds) as $duplicateId) {
      $model[$uuidMap[$duplicateId]] = $model[$duplicateId];
    }
    // Find the parent component.
    $parentNode = self::getParentNodeByComponentId($this->regionMap[$regionId], $componentUuid);
    \assert($parentNode !== NULL);
    $layout = $this->layout;
    if (self::nodeIsRegion($parentNode)) {
      // We're duplication a top-level component (inside a region).
      $layout[$this->regionDeltas[$regionId]] = self::insertAfter($this->regionMap[$regionId], $componentUuid, self::duplicate($duplicate, $uuidMap));
      return new static(
        $layout,
        $model,
        $this->entityFormFields,
        $this->clientInstanceId,
      );
    }
    // We're moving inside a slot.
    \assert(self::nodeIsSlot($parentNode));
    $parentNode = self::insertAfter($parentNode, $componentUuid, self::duplicate($duplicate, $uuidMap));
    \assert(\array_key_exists('id', $parentNode));
    [$parentComponentUuid, $slotName] = \explode('/', $parentNode['id']);
    $parentComponent = self::getComponentByUuid($this->regionMap[$regionId], $parentComponentUuid);
    \assert($parentComponent !== NULL);
    $parentComponent = self::replaceSlot($parentComponent, $slotName, $parentNode);
    $layout[$this->regionDeltas[$regionId]] = self::replaceComponent($layout[$this->regionDeltas[$regionId]], $parentComponentUuid, $parentComponent);
    return new static(
      $layout,
      $model,
      $this->entityFormFields,
      $this->clientInstanceId,
    );
  }

  public function withInsertedComponents(
    string $parentRegionOrUuid,
    ?string $slot,
    float $fractal,
    array $components,
    array $componentModel,
  ): static {
    if (\count($components) === 0) {
      // Nothing to insert.
      return $this;
    }
    $layout = $this->layout;
    $model = \array_replace((array) $this->model, $componentModel);
    if ($slot === NULL) {
      // We're inserting into a region here.
      if (!\array_key_exists($parentRegionOrUuid, $this->regionDeltas)) {
        // No such region - return as is.
        return $this;
      }
      // Insert each component into the region.
      foreach (\array_values(\array_reverse($components)) as $component) {
        $layout[$this->regionDeltas[$parentRegionOrUuid]] = self::insertNodeAtFractal($layout[$this->regionDeltas[$parentRegionOrUuid]], $component, $fractal);
      }
      return new static(
        $layout,
        $model,
        $this->entityFormFields,
        $this->clientInstanceId,
      );
    }
    // We're inserting into a slot, check the parent exists.
    $parentRegionId = $this->getRegionForComponentInstance($parentRegionOrUuid);
    if ($parentRegionId === NULL) {
      // @todo should this return an illegal operation exception so we can send
      //   a HTTP Conflict but _with_ a new layout/model so the front-end can
      //   warn and heal?
      // No such parent - return as is.
      return $this;
    }
    // Get the parent.
    $parentComponent = self::getComponentByUuid($layout[$this->regionDeltas[$parentRegionId]], $parentRegionOrUuid);
    \assert($parentComponent !== NULL);
    // Insert the new components.
    foreach (\array_values(\array_reverse($components)) as $component) {
      $parentComponent = self::insertNodeInSlotAtFractal($parentComponent, $component, $slot, $fractal);
    }
    // Replace it in the layout.
    $layout[$this->regionDeltas[$parentRegionId]] = self::replaceComponent($this->regionMap[$parentRegionId], $parentRegionOrUuid, $parentComponent);
    return new static(
      $layout,
      $model,
      $this->entityFormFields,
      $this->clientInstanceId,
    );
  }

}
