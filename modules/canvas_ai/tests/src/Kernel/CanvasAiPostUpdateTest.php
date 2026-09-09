<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Canvas AI post-update hooks.
 */
#[Group('canvas_ai')]
final class CanvasAiPostUpdateTest extends CanvasKernelTestBase {

  private const ORCHESTRATOR = 'ai_agents.ai_agent.canvas_ai_orchestrator';

  private const PAGE_BUILDER = 'ai_agents.ai_agent.canvas_page_builder_agent';

  private const TEMPLATE_BUILDER = 'ai_agents.ai_agent.canvas_template_builder_agent';

  private const PAGE_VARIANT_SETTINGS = 'canvas_ai.page_variant.settings';

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
   * Tests that 0012 refreshes both builder agents and keeps managed keys.
   */
  public function testPostUpdate0012RefreshesBuilderAgentPrompts(): void {
    $this->includePostUpdateFile();
    $module_path = $this->container->get(ModuleExtensionList::class)->getPath('canvas_ai');
    $shipped = new FileStorage($module_path . '/config/install');

    $managed = [];
    foreach ([self::PAGE_BUILDER, self::TEMPLATE_BUILDER] as $name) {
      $config = $this->config($name);
      $managed[$name] = ['uuid' => $config->get('uuid'), '_core' => $config->get('_core')];
      self::assertNotEmpty($managed[$name]['uuid']);
      self::assertNotEmpty($managed[$name]['_core']);
      // Seed the prompt an existing site still has: it consumes the token.
      $config->set('system_prompt', "stale prompt\n[canvas_ai:available_regions]")->save(TRUE);
    }
    $this->config(self::TEMPLATE_BUILDER)->set('description', 'stale description')->save(TRUE);

    canvas_ai_post_update_0012_reimport_region_free_builder_agents();
    $config_factory = $this->container->get(ConfigFactoryInterface::class);
    $config_factory->reset(self::PAGE_BUILDER);
    $config_factory->reset(self::TEMPLATE_BUILDER);

    foreach ($managed as $name => $keys) {
      $updated = $this->config($name);
      $expected = $shipped->read($name);
      self::assertIsArray($expected);
      self::assertSame($keys['uuid'], $updated->get('uuid'));
      self::assertSame($keys['_core'], $updated->get('_core'));
      self::assertSame($expected['system_prompt'], $updated->get('system_prompt'));
      self::assertStringNotContainsString('available_regions', $updated->get('system_prompt'));
    }
    $expected = $shipped->read(self::TEMPLATE_BUILDER);
    self::assertIsArray($expected);
    self::assertSame($expected['description'], $this->config(self::TEMPLATE_BUILDER)->get('description'));

    // The token the stale prompts consumed no longer exists.
    $tokens = $this->container->get('token')->getInfo()['tokens']['canvas_ai'];
    self::assertArrayNotHasKey('available_regions', $tokens);
    self::assertArrayHasKey('active_component_uuid', $tokens);
  }

  /**
   * Tests that 0013 deletes the page variant settings from every collection.
   */
  public function testPostUpdate0013DeletesPageVariantSettings(): void {
    $this->includePostUpdateFile();

    // Seed through the raw storage: the schema is gone, so the config factory
    // would refuse to save the object under strict schema checking.
    $storage = $this->container->get(StorageCacheInterface::class);
    $storage->write(self::PAGE_VARIANT_SETTINGS, [
      'langcode' => 'en',
      'variant_descriptions' => ['homepage' => ['name' => 'Homepage', 'description' => 'Lead with a hero.']],
    ]);
    $french = $storage->createCollection('language.fr');
    $french->write(self::PAGE_VARIANT_SETTINGS, [
      'variant_descriptions' => ['homepage' => ['description' => 'Translated guidance.']],
    ]);
    self::assertTrue($storage->exists(self::PAGE_VARIANT_SETTINGS));
    self::assertTrue($french->exists(self::PAGE_VARIANT_SETTINGS));

    canvas_ai_post_update_0013_delete_page_variant_settings();

    self::assertFalse($storage->exists(self::PAGE_VARIANT_SETTINGS));
    self::assertFalse($french->exists(self::PAGE_VARIANT_SETTINGS));
    self::assertTrue($this->config(self::PAGE_VARIANT_SETTINGS)->isNew());
  }

  /**
   * Includes the Canvas AI post-update file.
   */
  private function includePostUpdateFile(): void {
    $module_path = $this->container->get(ModuleExtensionList::class)->getPath('canvas_ai');
    require_once DRUPAL_ROOT . '/' . $module_path . '/canvas_ai.post_update.php';
  }

}
