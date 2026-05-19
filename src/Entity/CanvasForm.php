<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Component\Utility\Random;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\CanvasFormStorage;
use Drupal\canvas\EntityHandlers\ContentCreatorVisibleCanvasConfigEntityAccessControlHandler;

#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Canvas Form'),
  label_singular: new TranslatableMarkup('form'),
  label_plural: new TranslatableMarkup('forms'),
  label_collection: new TranslatableMarkup('Canvas Forms'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'storage' => CanvasFormStorage::class,
    'access' => ContentCreatorVisibleCanvasConfigEntityAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  config_export: [
    'id',
    'label',
    'form_elements',
    'submit_settings',
  ],
)]
final class CanvasForm extends ConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface, FolderItemInterface {

  public const string ENTITY_TYPE_ID = 'canvas_form';
  public const string ADMIN_PERMISSION = 'administer canvas forms';

  protected string $id;
  protected ?string $label;

  /**
   * Flat list of form element definitions.
   *
   * Each item: uuid, type, machine_name, properties (keyed without #), weight, parent (uuid or null).
   */
  protected array $form_elements = [];

  protected array $submit_settings = [
    'button_text' => 'Submit',
    'confirmation_message' => 'Thank you for your submission.',
    'redirect_url' => '',
  ];

  public static function preCreate(EntityStorageInterface $storage, array &$values): void {
    if (!\array_key_exists('id', $values)) {
      $values['id'] = self::generateId();
    }
    parent::preCreate($storage, $values);
  }

  private static function generateId(): string {
    return 'form_' . (new Random())->machineName(8);
  }

  // @phpstan-ignore shipmonk.deadMethod
  public function getFormElements(): array {
    return $this->form_elements;
  }

  // @phpstan-ignore shipmonk.deadMethod
  public function setFormElements(array $elements): static {
    $this->form_elements = $elements;
    return $this;
  }

  public function getSubmitSettings(): array {
    return $this->submit_settings + [
      'button_text' => 'Submit',
      'confirmation_message' => 'Thank you for your submission.',
      'redirect_url' => '',
    ];
  }

  // @phpstan-ignore shipmonk.deadMethod
  public function setSubmitSettings(array $settings): static {
    $this->submit_settings = $settings;
    return $this;
  }

  /**
   * Converts the stored form element definitions to a Drupal Form API render array.
   */
  public function toRenderArray(): array {
    $elements_by_uuid = [];
    $root_elements = [];

    foreach ($this->form_elements as $element) {
      $elements_by_uuid[$element['uuid']] = $element;
      if (empty($element['parent'])) {
        $root_elements[] = $element['uuid'];
      }
    }

    $build = [];
    foreach ($root_elements as $uuid) {
      $element = $elements_by_uuid[$uuid];
      $key = $element['machine_name'];
      $build[$key] = $this->buildRenderElement($element, $elements_by_uuid);
    }

    uasort($build, fn($a, $b) => ($a['#weight'] ?? 0) <=> ($b['#weight'] ?? 0));

    return $build;
  }

  private function buildRenderElement(array $element, array $elements_by_uuid): array {
    $render = ['#type' => $element['type']];

    foreach ($element['properties'] as $prop => $value) {
      $render['#' . $prop] = $value;
    }

    $render['#weight'] = $element['weight'] ?? 0;

    $children = array_filter($elements_by_uuid, fn($e) => ($e['parent'] ?? NULL) === $element['uuid']);
    usort($children, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    foreach ($children as $child) {
      $render[$child['machine_name']] = $this->buildRenderElement($child, $elements_by_uuid);
    }

    return $render;
  }

  public function normalizeForClientSide(): ClientSideRepresentation {
    return ClientSideRepresentation::create(
      values: [
        'id' => $this->id(),
        'name' => $this->label(),
        'formElements' => \array_map([self::class, 'elementToClientSide'], $this->form_elements),
        'submitSettings' => self::submitSettingsToClientSide($this->submit_settings),
      ],
      preview: NULL,
    )->addCacheableDependency($this);
  }

  private static function elementToClientSide(array $element): array {
    return [
      'uuid' => $element['uuid'],
      'type' => $element['type'],
      'machineName' => $element['machine_name'],
      'properties' => $element['properties'] ?? [],
      'weight' => $element['weight'] ?? 0,
      'parent' => $element['parent'] ?? NULL,
    ];
  }

  private static function submitSettingsToClientSide(array $settings): array {
    return [
      'button_text' => $settings['button_text'] ?? 'Submit',
      'confirmation_message' => $settings['confirmation_message'] ?? 'Thank you for your submission.',
      'redirect_url' => $settings['redirect_url'] ?? '',
    ];
  }

  public static function createFromClientSide(array $data): static {
    $values = [];
    if (isset($data['id'])) {
      $values['id'] = $data['id'];
    }
    if (isset($data['name'])) {
      $values['label'] = $data['name'];
    }
    $entity = static::create($values);
    $entity->updateFromClientSide($data);
    return $entity;
  }

  public function updateFromClientSide(array $data): void {
    if (isset($data['name'])) {
      $this->set('label', $data['name']);
    }
    if (isset($data['formElements'])) {
      $this->form_elements = \array_map([self::class, 'elementFromClientSide'], $data['formElements']);
    }
    if (isset($data['submitSettings'])) {
      $this->submit_settings = $data['submitSettings'];
    }
  }

  private static function elementFromClientSide(array $element): array {
    return [
      'uuid' => $element['uuid'],
      'type' => $element['type'],
      'machine_name' => $element['machineName'] ?? $element['machine_name'] ?? '',
      'properties' => $element['properties'] ?? [],
      'weight' => $element['weight'] ?? 0,
      'parent' => $element['parent'] ?? NULL,
    ];
  }

  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {}

}
