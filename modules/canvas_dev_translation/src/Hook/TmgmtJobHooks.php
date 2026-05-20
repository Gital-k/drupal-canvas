<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_translation\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates TMGMT translation jobs whenever a canvas_page entity is saved.
 */
class TmgmtJobHooks {

  private static array $pending = [];
  private static bool $shutdownRegistered = FALSE;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly AccountInterface $currentUser,
    private readonly LanguageManagerInterface $languageManager,
    #[Autowire(service: 'logger.channel.canvas_dev_translation')]
    private readonly LoggerChannelInterface $logger,
  ) {
  }

  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->scheduleCreateJobs($entity);
  }

  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->scheduleCreateJobs($entity);
  }

  /**
   * Implements hook_entity_delete().
   *
   * Deletes any pending TMGMT job items for the removed canvas_page immediately.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== Page::ENTITY_TYPE_ID) {
      return;
    }
    if (!$this->moduleHandler->moduleExists('tmgmt')) {
      return;
    }

    $this->deletePendingJobItems([
      'entity_type'     => $entity->getEntityTypeId(),
      'entity_id'       => $entity->id(),
      'source_langcode' => $entity->language()->getId(),
    ]);
  }

  private function scheduleCreateJobs(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== Page::ENTITY_TYPE_ID) {
      return;
    }
    if (!$this->moduleHandler->moduleExists('tmgmt')) {
      return;
    }
    if ($entity instanceof TranslatableInterface && !$entity->isDefaultTranslation()) {
      return;
    }
    if ($entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
      return;
    }

    $this->schedule([
      'entity_type'     => $entity->getEntityTypeId(),
      'entity_id'       => $entity->id(),
      'source_langcode' => $entity->language()->getId(),
      'uid'             => $this->currentUser->isAnonymous() ? 1 : $this->currentUser->id(),
    ]);
  }

  private function schedule(array $item): void {
    self::$pending[] = $item;

    if (!self::$shutdownRegistered) {
      self::$shutdownRegistered = TRUE;
      register_shutdown_function([$this, 'processShutdown']);
    }
  }

  /**
   * Processes all accumulated TMGMT operations at PHP shutdown.
   *
   * All canvas_page saves collected during the same request are batched into
   * one job per target language so the translator sees one coherent unit of
   * work.
   */
  public function processShutdown(): void {
    $items = self::$pending;
    self::$pending = [];

    if (empty($items)) {
      return;
    }

    $this->createBatchedJobs($items);
  }

  /**
   * Creates one TMGMT job per target language containing all collected entities.
   *
   * @param array[] $items
   *   Items collected during the request, each with keys:
   *   entity_type, entity_id, source_langcode, uid.
   */
  private function createBatchedJobs(array $items): void {
    $first           = reset($items);
    $source_langcode = $first['source_langcode'];
    $uid             = $first['uid'];

    $target_languages = array_filter(
      $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE),
      static fn(LanguageInterface $lang): bool => $lang->getId() !== $source_langcode,
    );

    if (empty($target_languages)) {
      return;
    }

    foreach ($target_languages as $target_language) {
      $target_langcode = $target_language->getId();

      // Remove any stale pending job items before creating the new batch job
      // to avoid "conflicting items" errors when submitting.
      foreach ($items as $item) {
        $this->deleteExistingPendingJobItem($item['entity_type'], $item['entity_id'], $source_langcode, $target_langcode);
      }

      /** @var \Drupal\tmgmt\JobInterface $job */
      $job = tmgmt_job_create($source_langcode, $target_langcode, $uid);
      $job->save();

      $added = 0;
      foreach ($items as $item) {
        try {
          $job->addItem('content', $item['entity_type'], $item['entity_id']);
          $added++;
        }
        catch (\Exception $e) {
          $this->logger->error(
            'Failed to add @type @id to TMGMT job targeting @lang: @msg',
            ['@type' => $item['entity_type'], '@id' => $item['entity_id'], '@lang' => $target_langcode, '@msg' => $e->getMessage()],
          );
        }
      }

      if ($added === 0) {
        try {
          $job->delete();
        }
        catch (\Exception) {
        }
        continue;
      }

      try {
        $job->save();
      }
      catch (\Exception $e) {
        $this->logger->error(
          'Failed to save TMGMT batch job targeting @lang: @msg',
          ['@lang' => $target_langcode, '@msg' => $e->getMessage()],
        );
      }
    }
  }

  /**
   * Deletes any unprocessed TMGMT job item for the given entity and target
   * language. If that leaves the parent job empty, the job is deleted too —
   * TMGMT does not do this automatically.
   */
  private function deleteExistingPendingJobItem(string $entity_type, string $entity_id, string $source_langcode, string $target_langcode): void {
    $existing = tmgmt_job_item_load_latest('content', $entity_type, $entity_id, $source_langcode);

    if (empty($existing[$target_langcode])) {
      return;
    }

    $job_item = $existing[$target_langcode];
    $job = $job_item->getJob();

    try {
      $job_item->delete();

      // TMGMT does not clean up jobs that have been left with no items, so we
      // do it ourselves to avoid zombie UNPROCESSED jobs appearing in the UI.
      if ($job && empty($job->getItems())) {
        $job->delete();
      }
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to delete stale TMGMT job item for canvas_page @id targeting @lang: @msg',
        ['@id' => $entity_id, '@lang' => $target_langcode, '@msg' => $e->getMessage()],
      );
    }
  }

  /**
   * Deletes all unprocessed or active TMGMT job items for a deleted canvas_page.
   *
   * Empty parent jobs are deleted as well since TMGMT does not do this
   * automatically.
   */
  private function deletePendingJobItems(array $item): void {
    $job_items = tmgmt_job_item_load_latest('content', $item['entity_type'], (string) $item['entity_id'], $item['source_langcode']);

    if (empty($job_items)) {
      return;
    }

    foreach ($job_items as $target_langcode => $job_item) {
      $job = $job_item->getJob();

      try {
        $job_item->delete();

        if ($job && empty($job->getItems())) {
          $job->delete();
        }
      }
      catch (\Exception $e) {
        $this->logger->error(
          'Failed to delete TMGMT job item for deleted canvas_page @id targeting @lang: @msg',
          ['@id' => $item['entity_id'], '@lang' => $target_langcode, '@msg' => $e->getMessage()],
        );
      }
    }
  }

}
