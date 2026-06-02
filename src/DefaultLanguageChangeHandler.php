<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Database\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to a site default language change and migrates Canvas content.
 *
 * Subscribes to ConfigEvents::SAVE and watches for changes to
 * system.site:default_langcode. When the default language changes, all
 * Canvas pages whose langcode matches the old default are re-assigned to the
 * new default language code.
 *
 * @see \Drupal\language\EventSubscriber\ConfigSubscriber
 */
final class DefaultLanguageChangeHandler implements EventSubscriberInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * Responds to a system.site config save.
   *
   * When default_langcode changes, migrates Canvas pages from the old default
   * language to the new one.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() !== 'system.site' || !$event->isChanged('default_langcode')) {
      return;
    }

    $new_langcode = $config->get('default_langcode');
    $old_langcode = $config->getOriginal('default_langcode');

    // No original value means this is a first-time install, not a change.
    if (!\is_string($old_langcode)) {
      return;
    }

    $this->migrateCanvasPages($old_langcode, $new_langcode);
    $this->autoSaveManager->migrateCanvasPageAutoSaves($old_langcode, $new_langcode);
  }

  /**
   * Updates the langcode on all Canvas page rows that match the old default.
   *
   * Uses direct database updates rather than the entity API to avoid
   * translation-loading issues when the new langcode has no existing
   * translation entries on a given entity.
   *
   * Only rows where default_langcode = 1 are updated, leaving non-default
   * translation rows untouched.
   *
   * @param string $old_langcode
   *   The previous default language code.
   * @param string $new_langcode
   *   The new default language code.
   */
  private function migrateCanvasPages(string $old_langcode, string $new_langcode): void {
    if (!$this->database->schema()->tableExists('canvas_page_field_data')) {
      return;
    }

    // Update the data table (stores per-translation field values).
    $this->database->update('canvas_page_field_data')
      ->fields(['langcode' => $new_langcode])
      ->condition('langcode', $old_langcode)
      ->condition('default_langcode', 1)
      ->execute();

    // Update the revision data table so historical revisions are consistent.
    if ($this->database->schema()->tableExists('canvas_page_field_revision')) {
      $this->database->update('canvas_page_field_revision')
        ->fields(['langcode' => $new_langcode])
        ->condition('langcode', $old_langcode)
        ->condition('default_langcode', 1)
        ->execute();
    }

    // Invalidate the entity cache so reloads reflect the new langcode.
    \Drupal::entityTypeManager()
      ->getStorage(Page::ENTITY_TYPE_ID)
      ->resetCache();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => ['onConfigSave'],
    ];
  }

}
