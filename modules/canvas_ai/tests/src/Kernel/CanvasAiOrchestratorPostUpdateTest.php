<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the orchestrator agent post-update hooks keep system-managed keys.
 */
#[Group('canvas_ai')]
final class CanvasAiOrchestratorPostUpdateTest extends CanvasKernelTestBase {

  private const ORCHESTRATOR = 'ai_agents.ai_agent.canvas_ai_orchestrator';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_agents',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_ai']);
  }

  /**
   * Tests that a prompt reimport keeps the uuid and _core hash intact.
   *
   * @param string $function
   *   The post-update function that re-imports the orchestrator system prompt.
   */
  #[DataProvider('providerPromptReimport')]
  public function testPromptReimportPreservesManagedKeys(string $function): void {
    $this->includePostUpdateFile();

    $original = $this->config(self::ORCHESTRATOR);
    $uuid = $original->get('uuid');
    $core = $original->get('_core');
    self::assertNotEmpty($uuid);
    self::assertNotEmpty($core);

    // Change the active prompt so the update has something to overwrite.
    $this->config(self::ORCHESTRATOR)->set('system_prompt', 'stale prompt')->save(TRUE);

    \assert(\is_callable($function));
    $function();
    $this->container->get(ConfigFactoryInterface::class)->reset(self::ORCHESTRATOR);

    $updated = $this->config(self::ORCHESTRATOR);
    self::assertSame($uuid, $updated->get('uuid'));
    self::assertSame($core, $updated->get('_core'));
    self::assertSame($this->readInstallConfig()['system_prompt'], $updated->get('system_prompt'));
    self::assertStringContainsString('Site Chrome Comes From the Page Variant', $updated->get('system_prompt'));
    self::assertStringNotContainsString('including headers, footers, and multiple sections', $updated->get('system_prompt'));
  }

  /**
   * Provides the post-update functions that re-import the orchestrator prompt.
   *
   * @return array<string, array{string}>
   *   The post-update function names keyed by update number.
   */
  public static function providerPromptReimport(): array {
    return [
      '0003' => ['canvas_ai_post_update_0003_reimport_orchestrator_agent'],
      '0014' => ['canvas_ai_post_update_0014_reimport_orchestrator_content_only_chrome'],
    ];
  }

  /**
   * Tests that the repair hook restores the uuid and _core stripped by 0003.
   */
  public function testPostUpdate0006RestoresManagedKeys(): void {
    $this->includePostUpdateFile();

    // Simulate the active config left behind by the original 0003, which
    // dropped both the uuid and _core when it replaced the whole record.
    $storage = $this->container->get(StorageCacheInterface::class);
    $data = $storage->read(self::ORCHESTRATOR);
    unset($data['uuid'], $data['_core']);
    $storage->write(self::ORCHESTRATOR, $data);
    $this->container->get(ConfigFactoryInterface::class)->reset(self::ORCHESTRATOR);
    self::assertEmpty($this->config(self::ORCHESTRATOR)->get('uuid'));
    self::assertEmpty($this->config(self::ORCHESTRATOR)->get('_core'));

    canvas_ai_post_update_0006_restore_orchestrator_agent_uuid();
    $this->container->get(ConfigFactoryInterface::class)->reset(self::ORCHESTRATOR);

    $repaired = $this->config(self::ORCHESTRATOR);
    self::assertNotEmpty($repaired->get('uuid'));
    self::assertNotEmpty($repaired->get('_core.default_config_hash'));
  }

  /**
   * Tests that the prompt reimport is a no-op when the orchestrator is absent.
   */
  public function testPromptReimportSkipsMissingConfig(): void {
    $this->includePostUpdateFile();

    $this->config(self::ORCHESTRATOR)->delete();
    $this->container->get(ConfigFactoryInterface::class)->reset(self::ORCHESTRATOR);
    self::assertTrue($this->config(self::ORCHESTRATOR)->isNew());

    canvas_ai_post_update_0014_reimport_orchestrator_content_only_chrome();
    $this->container->get(ConfigFactoryInterface::class)->reset(self::ORCHESTRATOR);

    // The guard on isNew() must leave a missing orchestrator missing rather
    // than create a partial record holding only the prompt.
    self::assertTrue($this->config(self::ORCHESTRATOR)->isNew());
  }

  /**
   * Includes the Canvas AI post-update file.
   */
  private function includePostUpdateFile(): void {
    require_once DRUPAL_ROOT . '/' . $this->getModulePath('canvas_ai') . '/canvas_ai.post_update.php';
  }

  /**
   * Reads the orchestrator agent record shipped in config/install.
   *
   * @return array<string, mixed>
   *   The decoded install config.
   */
  private function readInstallConfig(): array {
    $data = (new FileStorage(DRUPAL_ROOT . '/' . $this->getModulePath('canvas_ai') . '/config/install'))->read(self::ORCHESTRATOR);
    self::assertIsArray($data);
    return $data;
  }

}
