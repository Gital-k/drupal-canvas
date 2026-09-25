<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_dev_translation\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_dev_translation\Hook\TmgmtJobHooks;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveManagerTestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\tmgmt\Entity\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves that publishing canvas_page entities via the Canvas UI results in
 * one batched TMGMT job per target language, not one job per entity.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(TmgmtJobHooks::class)]
#[Group('canvas_dev_translation')]
final class TmgmtJobCreationTest extends KernelTestBase {

  use AutoSaveManagerTestTrait;
  use AutoSaveRequestTestTrait;
  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path_alias',
    'path',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    (new CanvasTestSetup())->setup();

    $this->container->get('module_installer')->install(['canvas_dev_translation']);
    $this->container = \Drupal::getContainer();
    $this->installEntitySchema('tmgmt_job');
    $this->installEntitySchema('tmgmt_job_item');
    $this->installEntitySchema('tmgmt_message');
  }

  /**
   * Publishing multiple canvas_page entities creates one job per target
   * language, with every published entity as a job item inside that job.
   */
  public function testPublishingMultiplePagesCreatesOneBatchedJobPerTargetLanguage(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);

    $page1 = Page::create([
      'title' => 'Page One',
      'status' => TRUE,
      'components' => [],
    ]);
    $page1->save();
    $page1->set('title', 'Page One updated');
    $autoSave->saveEntity($page1);

    $page2 = Page::create([
      'title' => 'Page Two',
      'status' => TRUE,
      'components' => [],
    ]);
    $page2->save();
    $page2->set('title', 'Page Two updated');
    $autoSave->saveEntity($page2);

    // Drain any pending items from the initial entity_insert saves above.
    // In production each save is in its own request so self::$pending is
    // always fresh; here we simulate that boundary manually.
    /** @var \Drupal\canvas_dev_translation\Hook\TmgmtJobHooks $hooks */
    $hooks = $this->container->get(TmgmtJobHooks::class);
    $hooks->processShutdown();

    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    // PHP shutdown functions do not run mid-test, so trigger batching manually.
    $hooks->processShutdown();


    // There should be exactly one job per target language (2 total), each
    // containing both pages as job items — not one job per entity per language.
    $jobs = \Drupal::entityTypeManager()
      ->getStorage('tmgmt_job')
      ->loadMultiple();

    self::assertCount(2, $jobs, 'Expected exactly one TMGMT job per target language.');

    $jobs_by_target = [];
    foreach ($jobs as $job) {
      \assert($job instanceof Job);
      $jobs_by_target[$job->getTargetLangcode()] = $job;
    }

    self::assertArrayHasKey('de', $jobs_by_target, 'Expected a job targeting German.');
    self::assertArrayHasKey('fr', $jobs_by_target, 'Expected a job targeting French.');

    foreach (['de', 'fr'] as $langcode) {
      $job   = $jobs_by_target[$langcode];
      $items = $job->getItems();

      self::assertCount(2, $items, "Expected 2 job items in the $langcode job.");

      $item_ids = array_map(
        static fn($item) => $item->getItemId(),
        $items,
      );

      self::assertContains(
        (string) $page1->id(),
        $item_ids,
        "Expected Page One in the $langcode job.",
      );
      self::assertContains(
        (string) $page2->id(),
        $item_ids,
        "Expected Page Two in the $langcode job.",
      );
    }
  }

  /**
   * Republishing an already-translated page replaces the old job item with a
   * fresh one in a new job rather than creating a duplicate.
   */
  public function testRepublishingPageReplacesStaleJobItem(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);

    $page = Page::create([
      'title' => 'My Page',
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();
    $page->set('title', 'My Page v1');
    $autoSave->saveEntity($page);

    /** @var \Drupal\canvas_dev_translation\Hook\TmgmtJobHooks $hooks */
    $hooks = $this->container->get(TmgmtJobHooks::class);
    // Drain entity_insert from the save above before the publish request.
    $hooks->processShutdown();

    // First publish → first batch of jobs.
    $this->makePublishAllRequest();
    $hooks->processShutdown();

    $jobs_after_first_publish = \Drupal::entityTypeManager()
      ->getStorage('tmgmt_job')
      ->loadMultiple();
    self::assertCount(1, $jobs_after_first_publish);

    // Simulate an update and second publish.
    $page = \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->load($page->id());
    \assert($page instanceof Page);
    $page->set('title', 'My Page v2');
    $autoSave->saveEntity($page);

    $this->makePublishAllRequest();
    $hooks->processShutdown();

    // Still only one job — the stale one was replaced, not duplicated.
    $all_jobs = \Drupal::entityTypeManager()
      ->getStorage('tmgmt_job')
      ->loadMultiple();
    self::assertCount(1, $all_jobs, 'Stale job should be replaced, not duplicated.');
  }

}




