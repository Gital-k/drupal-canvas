<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_translation\EventSubscriber;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Creates TMGMT translation jobs whenever a content_template or page_region
 * config entity is saved or deleted.
 */
class ConfigTranslationJobSubscriber implements EventSubscriberInterface {

  /**
   * Maps config name prefix to TMGMT item type (entity_type_id).
   *
   * Keys are the config prefix (provider.entity_type_id), values are the
   * entity type IDs used as TMGMT item_type for the 'config' source plugin.
   */
  private const CONFIG_PREFIXES = [
    'canvas.' . ContentTemplate::ENTITY_TYPE_ID => ContentTemplate::ENTITY_TYPE_ID,
    'canvas.' . PageRegion::ENTITY_TYPE_ID      => PageRegion::ENTITY_TYPE_ID,
  ];

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly AccountInterface $currentUser,
    private readonly LanguageManagerInterface $languageManager,
    #[Autowire(service: 'logger.channel.canvas_dev_translation')]
    private readonly LoggerChannelInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE   => 'onConfigSave',
      ConfigEvents::DELETE => 'onConfigDelete',
    ];
  }

  /**
   * Reacts to a config entity being saved (created or updated).
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if (!$this->moduleHandler->moduleExists('tmgmt')) {
      return;
    }

    $config_name = $event->getConfig()->getName();
    $item_type   = $this->resolveItemType($config_name);

    if ($item_type === NULL) {
      return;
    }

    $source_langcode = $this->languageManager->getDefaultLanguage()->getId();

    $target_languages = array_filter(
      $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE),
      static fn(LanguageInterface $lang): bool => $lang->getId() !== $source_langcode,
    );

    if (empty($target_languages)) {
      return;
    }

    $uid = $this->currentUser->isAnonymous() ? 1 : $this->currentUser->id();

    foreach ($target_languages as $target_language) {
      $this->deleteExistingPendingJobItem($item_type, $config_name, $source_langcode, $target_language->getId());

      /** @var \Drupal\tmgmt\JobInterface $job */
      $job = tmgmt_job_create($source_langcode, $target_language->getId(), $uid);
      $job->save();

      try {
        $job->addItem('config', $item_type, $config_name);
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
          'Failed to create TMGMT job for config @name targeting @lang: @msg',
          ['@name' => $config_name, '@lang' => $target_language->getId(), '@msg' => $e->getMessage()],
        );
      }
    }
  }

  /**
   * Reacts to a config entity being deleted.
   */
  public function onConfigDelete(ConfigCrudEvent $event): void {
    if (!$this->moduleHandler->moduleExists('tmgmt')) {
      return;
    }

    $config_name = $event->getConfig()->getName();
    $item_type   = $this->resolveItemType($config_name);

    if ($item_type === NULL) {
      return;
    }

    $source_langcode = $this->languageManager->getDefaultLanguage()->getId();
    $job_items = tmgmt_job_item_load_latest('config', $item_type, $config_name, $source_langcode);

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
          'Failed to delete TMGMT job item for deleted config @name targeting @lang: @msg',
          ['@name' => $config_name, '@lang' => $target_langcode, '@msg' => $e->getMessage()],
        );
      }
    }
  }

  /**
   * Returns the TMGMT item_type for a config name, or NULL if not handled.
   *
   * The config source plugin uses the entity_type_id as item_type and the full
   * config name (e.g. "canvas.content_template.foo") as item_id.
   */
  private function resolveItemType(string $config_name): ?string {
    foreach (self::CONFIG_PREFIXES as $prefix => $item_type) {
      if (str_starts_with($config_name, $prefix . '.')) {
        return $item_type;
      }
    }
    return NULL;
  }

  /**
   * Deletes any unprocessed TMGMT job item for the config entity and target
   * language so stale content is not queued for translation.
   *
   * Only the specific job item is removed. If that leaves the parent job with
   * no remaining items, the now-empty job is also deleted — TMGMT does not do
   * this automatically.
   */
  private function deleteExistingPendingJobItem(string $item_type, string $config_name, string $source_langcode, string $target_langcode): void {
    $existing = tmgmt_job_item_load_latest('config', $item_type, $config_name, $source_langcode);

    if (empty($existing[$target_langcode])) {
      return;
    }

    $job_item = $existing[$target_langcode];
    $job = $job_item->getJob();

    try {
      $job_item->delete();

      if ($job && empty($job->getItems())) {
        $job->delete();
      }
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to delete stale TMGMT job item for config @name targeting @lang: @msg',
        ['@name' => $config_name, '@lang' => $target_langcode, '@msg' => $e->getMessage()],
      );
    }
  }

}

