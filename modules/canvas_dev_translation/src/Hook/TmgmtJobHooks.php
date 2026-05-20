<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_translation\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
      'op'              => 'create',
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
   */
  public function processShutdown(): void {
    $pending = self::$pending;
    self::$pending = [];

    foreach ($pending as $item) {
      match ($item['op']) {
        'create'  => $this->createJobs($item),
        default   => NULL,
      };
    }
  }

  private function createJobs(array $item): void {
    $entity = $this->entityTypeManager
      ->getStorage($item['entity_type'])
      ->load($item['entity_id']);

    if ($entity === NULL) {
      return;
    }
    if ($entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
      return;
    }

    $source_langcode = $item['source_langcode'];

    $target_languages = array_filter(
      $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE),
      static fn(LanguageInterface $lang): bool => $lang->getId() !== $source_langcode,
    );

    if (empty($target_languages)) {
      return;
    }

    foreach ($target_languages as $target_language) {
      $this->deleteExistingPendingJobItem($entity, $source_langcode, $target_language->getId());

      /** @var \Drupal\tmgmt\JobInterface $job */
      $job = tmgmt_job_create($source_langcode, $target_language->getId(), $item['uid']);
      $job->save();

      try {
        $job->addItem('content', $entity->getEntityTypeId(), $entity->id());
        $job->save();
        // Translation must be requested manually after assigning a translator
        // provider via the TMGMT UI.
      }
      catch (\Exception $e) {
        try {
          $job->delete();
        }
        catch (\Exception) {
        }
        $this->logger->error(
          'Failed to create TMGMT job for @type @id targeting @lang: @msg',
          ['@type' => $entity->getEntityTypeId(), '@id' => $entity->id(), '@lang' => $target_language->getId(), '@msg' => $e->getMessage()],
        );
      }
    }
  }

  /**
   * Deletes any unprocessed or active TMGMT job item for the given entity and
   * target language so stale content is not queued for translation.
   *
   * Only the specific job item is removed. If that leaves the parent job with
   * no remaining items, the now-empty job is also deleted — TMGMT does not do
   * this automatically.
   */
  private function deleteExistingPendingJobItem(EntityInterface $entity, string $source_langcode, string $target_langcode): void {
    $existing = tmgmt_job_item_load_latest('content', $entity->getEntityTypeId(), (string) $entity->id(), $source_langcode);

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
        ['@id' => $entity->id(), '@lang' => $target_langcode, '@msg' => $e->getMessage()],
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
