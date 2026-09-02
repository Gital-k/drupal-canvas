<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the agent config post-update hooks keep system-managed keys.
 */
#[Group('canvas_ai')]
final class CanvasAiAgentPostUpdateTest extends CanvasKernelTestBase {

  private const ORCHESTRATOR = 'ai_agents.ai_agent.canvas_ai_orchestrator';

  private const DEV_PAGE_BUILDER = 'ai_agents.ai_agent.canvas_dev_page_builder_agent';

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
   * Tests that the prompt update keeps the uuid and _core hash intact.
   */
  public function testPostUpdate0003PreservesManagedKeys(): void {
    $this->includePostUpdateFile();

    $original = $this->config(self::ORCHESTRATOR);
    $uuid = $original->get('uuid');
    $core = $original->get('_core');
    self::assertNotEmpty($uuid);
    self::assertNotEmpty($core);

    // Change the active prompt so the update has something to overwrite.
    $this->config(self::ORCHESTRATOR)->set('system_prompt', 'stale prompt')->save(TRUE);

    canvas_ai_post_update_0003_reimport_orchestrator_agent();
    $this->container->get(ConfigFactoryInterface::class)->reset(self::ORCHESTRATOR);

    $updated = $this->config(self::ORCHESTRATOR);
    self::assertSame($uuid, $updated->get('uuid'));
    self::assertSame($core, $updated->get('_core'));
    self::assertNotSame('stale prompt', $updated->get('system_prompt'));
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
   * Tests the dev page builder prompt update lands the end-of-turn marker.
   *
   * The controller nudges a build hop that ends without a tool call, unless the
   * answer carries the marker the prompt asks for. Sites that installed
   * canvas_dev_ai before the marker existed keep the old prompt in active
   * config, so without this update every turn ending would be nudged.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::endedOnPurpose()
   */
  public function testPostUpdate0001UpdatesDevPageBuilderPrompt(): void {
    // canvas_dev_ai replaces the AI chat backend while installed, so it is
    // installed here rather than in ::$modules, where it would also apply to
    // the orchestrator cases above.
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $module_path = $this->container->get(ModuleExtensionList::class)->getPath('canvas_dev_ai');
    require_once DRUPAL_ROOT . '/' . $module_path . '/canvas_dev_ai.post_update.php';

    $original = $this->config(self::DEV_PAGE_BUILDER);
    $uuid = $original->get('uuid');
    $core = $original->get('_core');
    self::assertNotEmpty($uuid);
    self::assertNotEmpty($core);

    // The prompt an existing site carries, from before the marker existed.
    $this->config(self::DEV_PAGE_BUILDER)->set('system_prompt', 'stale prompt')->save(TRUE);

    canvas_dev_ai_post_update_0001_reimport_dev_page_builder_agent();
    $this->container->get(ConfigFactoryInterface::class)->reset(self::DEV_PAGE_BUILDER);

    $updated = $this->config(self::DEV_PAGE_BUILDER);
    self::assertSame($uuid, $updated->get('uuid'));
    self::assertSame($core, $updated->get('_core'));
    // The marker is the contract between the prompt and the controller, so the
    // reimported prompt has to teach it.
    self::assertStringContainsString('##END_OF_TURN##', (string) $updated->get('system_prompt'));
  }

  /**
   * Includes the Canvas AI post-update file.
   */
  private function includePostUpdateFile(): void {
    $module_path = $this->container->get(ModuleExtensionList::class)->getPath('canvas_ai');
    require_once DRUPAL_ROOT . '/' . $module_path . '/canvas_ai.post_update.php';
  }

}
