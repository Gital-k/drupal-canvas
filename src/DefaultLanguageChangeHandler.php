<?php

declare(strict_types=1);

namespace Drupal\canvas;

// cspell:ignore bulkupdate

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to a site default language change and migrates Canvas content.
 *
 * Subscribes to ConfigEvents::SAVE and watches for changes to
 * system.site:default_langcode. When the default language changes:
 * - Canvas pages are re-assigned to the new default language code (AC1, AC2).
 * - When the new default already has translations, they are promoted to the
 *   default and the former default becomes a translation (AC9).
 * - Config entity translations (ContentTemplate, PageRegion) are restructured
 *   so the new default language becomes the base config (AC4).
 * - Canvas page auto-save keys are updated (AC3).
 *
 * @see \Drupal\language\EventSubscriber\ConfigSubscriber
 */
final class DefaultLanguageChangeHandler implements EventSubscriberInterface {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Responds to a system.site config save.
   *
   * When default_langcode changes, migrates Canvas content and config.
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
    $this->migrateConfigEntityTranslations($old_langcode, $new_langcode);
    $this->regenerateCanvasPageAliases();
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
    if (!empty($ids_to_promote)) {
      $this->database->update('canvas_page_field_data')
        ->fields(['default_langcode' => 1])
        ->condition('id', $ids_to_promote, 'IN')
        ->condition('langcode', $new_langcode)
        ->execute();
      $this->database->update('canvas_page_field_data')
        ->fields(['default_langcode' => 0])
        ->condition('id', $ids_to_promote, 'IN')
        ->condition('langcode', $old_langcode)
        ->execute();

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

    $this->entityTypeManager
      ->getStorage(Page::ENTITY_TYPE_ID)
      ->resetCache();
  }

  /**
   * Regenerates Pathauto URL aliases for all Canvas pages.
   *
   * Canvas normally suppresses Pathauto auto-generation on page presave so
   * that aliases are managed via the UI. After a default language change,
   * however, language tokens in Pathauto patterns may resolve differently, so
   * aliases must be regenerated programmatically regardless of the stored
   * PathautoState.
   *
   * This is a no-op if the pathauto module is not installed.
   *
   * @see \Drupal\canvas\Hook\PageHooks::ensurePathautoSkipped()
   */
  private function regenerateCanvasPageAliases(): void {
    if (!$this->moduleHandler->moduleExists('pathauto')) {
      return;
    }

    /** @var \Drupal\pathauto\PathautoGeneratorInterface $pathauto_generator */
    $pathauto_generator = \Drupal::service('pathauto.generator');

    // Temporarily set the Pathauto update action to DELETE (replace existing
    // aliases) so that language-token-based patterns are fully re-evaluated.
    // The default UPDATE_ACTION_NO_NEW would leave existing aliases untouched.
    $pathauto_config = $this->configFactory->getEditable('pathauto.settings');
    $original_update_action = $pathauto_config->get('update_action');
    // PathautoGeneratorInterface::UPDATE_ACTION_DELETE = 2.
    $pathauto_config->set('update_action', 2)->save(TRUE);

    try {
      $storage = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID);
      $page_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->execute();

      foreach ($storage->loadMultiple($page_ids) as $page) {
        \assert($page instanceof Page);
        // Force alias generation bypassing the PathautoState::SKIP value that
        // PageHooks::ensurePathautoSkipped() sets on normal presave.
        $pathauto_generator->updateEntityAlias($page, 'bulkupdate', ['force' => TRUE]);
      }
    }
    finally {
      $pathauto_config->set('update_action', $original_update_action)->save(TRUE);
    }
  }

  /**
   * Restructures config translations for ContentTemplates and PageRegions.
   *
   * For each entity that has a language override for $new_langcode:
   * - The override values are merged with the base config to produce the full
   *   new-default values, which are saved back as the base config.
   * - The original base values are stored as an override for $old_langcode
   *   (unless the old language is no longer a site language).
   * - The $new_langcode override is deleted since it is now the base.
   *
   * For entities with no override for $new_langcode, nothing changes.
   *
   * @param string $old_langcode
   *   The previous default language code.
   * @param string $new_langcode
   *   The new default language code.
   */
  private function migrateConfigEntityTranslations(
    string $old_langcode,
    string $new_langcode,
  ): void {
    $language_manager = $this->languageManager;
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $old_language_exists = (bool) $language_manager->getLanguage($old_langcode);

    $entity_type_ids = [
      ContentTemplate::ENTITY_TYPE_ID,
      PageRegion::ENTITY_TYPE_ID,
    ];

    foreach ($entity_type_ids as $entity_type_id) {
      $entities = $this->entityTypeManager
        ->getStorage($entity_type_id)
        ->loadMultiple();

      foreach ($entities as $entity) {
        $config_name = $entity->getConfigDependencyName();

        $new_override = $language_manager
          ->getLanguageConfigOverride($new_langcode, $config_name);

        // Nothing to do if the new default language has no override.
        if ($new_override->isNew()) {
          continue;
        }

        // Get the original base config values (the old default language data).
        $base_config = $this->configFactory->getEditable($config_name);
        $original_base_data = $base_config->getRawData();

        // Merge the new-langcode override onto base to get full new values.
        $new_base_data = NestedArray::mergeDeepArray([
          $original_base_data,
          $new_override->getRawData(),
        ], TRUE);

        // Save the merged values as the new base config.
        $base_config->setData($new_base_data)->save(TRUE);

        // Store the original base values as an override for the old language,
        // but only if the old language still exists on the site.
        if ($old_language_exists) {
          $old_override = $language_manager
            ->getLanguageConfigOverride($old_langcode, $config_name);
          // Build a minimal diff: only the keys present in the new-langcode
          // override (the translatable fields), with the original base values.
          $diff = $this->extractTranslatableDiff(
            $new_override->getRawData(),
            $original_base_data,
          );
          if (!empty($diff)) {
            $old_override->setData($diff)->save();
          }
        }

        // Delete the new-langcode override — it is now the base config.
        $new_override->delete();
      }
    }
  }

  /**
   * Extracts original values for the keys present in a reference override.
   *
   * Given the shape of a translation override (which keys were translated) and
   * the original base config values, returns an array in the same shape but
   * populated with values from the original base.
   *
   * @param array $override_shape
   *   The shape of the existing translation override (new-langcode override).
   * @param array $original_base
   *   The original base config values (old-langcode values).
   *
   * @return array
   *   An array in the same shape as $override_shape, with values from
   *   $original_base.
   */
  private function extractTranslatableDiff(
    array $override_shape,
    array $original_base,
  ): array {
    $diff = [];
    foreach ($override_shape as $key => $value) {
      if (!\array_key_exists($key, $original_base)) {
        continue;
      }
      if (\is_array($value) && \is_array($original_base[$key])) {
        $nested = $this->extractTranslatableDiff($value, $original_base[$key]);
        if (!empty($nested)) {
          $diff[$key] = $nested;
        }
      }
      else {
        $diff[$key] = $original_base[$key];
      }
    }
    return $diff;
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
