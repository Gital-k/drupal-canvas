<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
  }

  /**
   * Updates all Canvas pages whose langcode matches the old default.
   *
   * @param string $old_langcode
   *   The previous default language code.
   * @param string $new_langcode
   *   The new default language code.
   */
  private function migrateCanvasPages(string $old_langcode, string $new_langcode): void {
    $storage = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID);

    $page_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('langcode', $old_langcode)
      ->condition('default_langcode', 1)
      ->execute();

    if (empty($page_ids)) {
      return;
    }

    foreach ($storage->loadMultiple($page_ids) as $page) {
      \assert($page instanceof Page);
      $page->set('langcode', $new_langcode);
      $page->save();
    }
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
