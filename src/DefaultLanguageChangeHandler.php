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
 * When the new default language already has existing translations on a page,
 * those translations are promoted to become the default (AC9): the
 * default_langcode flag is flipped rather than changing langcode values, so
 * all translation data is preserved.
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
   * Migrates Canvas page langcodes from the old default to the new one.
   *
   * Uses direct database updates rather than the entity API to avoid
   * translation-loading issues.
   *
   * Two cases are handled per page:
   * - Page already has a translation in $new_langcode: promote it by flipping
   *   the default_langcode flag. The old default row stays under $old_langcode
   *   as a non-default translation (AC9).
   * - Page has no translation in $new_langcode: update the default row's
   *   langcode directly to $new_langcode (AC1, AC2).
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

    // Find all page IDs whose current default row is in $old_langcode.
    $default_ids = $this->database->select('canvas_page_field_data', 'd')
      ->fields('d', ['id'])
      ->condition('d.langcode', $old_langcode)
      ->condition('d.default_langcode', 1)
      ->execute()
      ->fetchCol();

    if (empty($default_ids)) {
      return;
    }

    // Find which of those already have a translation in $new_langcode.
    $ids_with_new_translation = $this->database->select('canvas_page_field_data', 'd')
      ->fields('d', ['id'])
      ->condition('d.id', $default_ids, 'IN')
      ->condition('d.langcode', $new_langcode)
      ->execute()
      ->fetchCol();

    $ids_to_promote = \array_intersect($default_ids, $ids_with_new_translation);
    $ids_to_relabel = \array_diff($default_ids, $ids_with_new_translation);

    // AC9: Promote existing $new_langcode translation to default.
    // Flip default_langcode on the two affected rows for each page.
    if (!empty($ids_to_promote)) {
      // The new-langcode row becomes the default.
      $this->database->update('canvas_page_field_data')
        ->fields(['default_langcode' => 1])
        ->condition('id', $ids_to_promote, 'IN')
        ->condition('langcode', $new_langcode)
        ->execute();
      // The old-langcode row becomes a non-default translation.
      $this->database->update('canvas_page_field_data')
        ->fields(['default_langcode' => 0])
        ->condition('id', $ids_to_promote, 'IN')
        ->condition('langcode', $old_langcode)
        ->execute();

      // Mirror the same changes in the revision table.
      if ($this->database->schema()->tableExists('canvas_page_field_revision')) {
        $this->database->update('canvas_page_field_revision')
          ->fields(['default_langcode' => 1])
          ->condition('id', $ids_to_promote, 'IN')
          ->condition('langcode', $new_langcode)
          ->execute();
        $this->database->update('canvas_page_field_revision')
          ->fields(['default_langcode' => 0])
          ->condition('id', $ids_to_promote, 'IN')
          ->condition('langcode', $old_langcode)
          ->execute();
      }
    }

    // AC1/AC2: No existing translation — update langcode directly.
    if (!empty($ids_to_relabel)) {
      $this->database->update('canvas_page_field_data')
        ->fields(['langcode' => $new_langcode])
        ->condition('id', $ids_to_relabel, 'IN')
        ->condition('langcode', $old_langcode)
        ->condition('default_langcode', 1)
        ->execute();

      if ($this->database->schema()->tableExists('canvas_page_field_revision')) {
        $this->database->update('canvas_page_field_revision')
          ->fields(['langcode' => $new_langcode])
          ->condition('id', $ids_to_relabel, 'IN')
          ->condition('langcode', $old_langcode)
          ->condition('default_langcode', 1)
          ->execute();
      }
    }

    // Invalidate the entity cache so reloads reflect the updated rows.
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
