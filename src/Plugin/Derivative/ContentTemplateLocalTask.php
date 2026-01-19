<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides dynamic local tasks for content templates on entity canonical pages.
 *
 * @internal
 */
class ContentTemplateLocalTask extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * The base plugin ID.
   *
   * @var string
   */
  protected string $basePluginId;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new ContentTemplateLocalTask.
   *
   * @param string $base_plugin_id
   *   The base plugin ID.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager.
   */
  public function __construct(string $base_plugin_id, EntityTypeManagerInterface $entity_type_manager, TranslationInterface $string_translation) {
    $this->basePluginId = $base_plugin_id;
    $this->entityTypeManager = $entity_type_manager;
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static(
      $base_plugin_id,
      $container->get('entity_type.manager'),
      $container->get('string_translation')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $this->derivatives = [];

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type instanceof ContentEntityTypeInterface) {
        continue;
      }

      if (!$entity_type->hasLinkTemplate('canonical')) {
        continue;
      }

      // Create a local task for each entity type's canonical page.
      $this->derivatives["canvas_template_$entity_type_id"] = [
        'route_name' => 'canvas.template.entity',
        'title' => $this->t('Edit Content Template'),
        'base_route' => "entity.$entity_type_id.canonical",
      ];
    }

    foreach ($this->derivatives as &$entry) {
      $entry += $base_plugin_definition;
    }

    return $this->derivatives;
  }

}

