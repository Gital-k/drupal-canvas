<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\EventSubscriber\CodeComponentSyncTransformer;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @covers \Drupal\canvas\EventSubscriber\CodeComponentSyncTransformer
 */
#[Group('canvas')]
final class CodeComponentSyncTransformerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'canvas',
  ];

  private MemoryStorage $activeStorage;

  protected function setUp(): void {
    parent::setUp();
    $this->activeStorage = new MemoryStorage();
  }

  public function testPreserveProductionKeepsDbOnlyJsComponentOnImport(): void {
    $this->setMode('preserve_production');
    $this->activeStorage->write('canvas.js_component.foo', ['machineName' => 'foo']);

    $sync = new MemoryStorage();
    $this->dispatchImport($sync);

    self::assertSame(['machineName' => 'foo'], $sync->read('canvas.js_component.foo'));
  }

  public function testPreserveProductionDoesNotOverwriteSyncEntry(): void {
    $this->setMode('preserve_production');
    $this->activeStorage->write('canvas.js_component.foo', ['machineName' => 'foo', 'name' => 'DB']);

    $sync = new MemoryStorage();
    $sync->write('canvas.js_component.foo', ['machineName' => 'foo', 'name' => 'Sync']);
    $this->dispatchImport($sync);

    self::assertSame('Sync', $sync->read('canvas.js_component.foo')['name']);
  }

  public function testPreserveProductionCopiesPairedJsSourcedComponent(): void {
    $this->setMode('preserve_production');
    $this->activeStorage->write('canvas.component.js.foo', ['id' => 'js.foo', 'source' => 'js']);
    $this->activeStorage->write('canvas.component.block.bar', ['id' => 'block.bar', 'source' => 'block']);

    $sync = new MemoryStorage();
    $this->dispatchImport($sync);

    self::assertTrue($sync->exists('canvas.component.js.foo'));
    self::assertFalse($sync->exists('canvas.component.block.bar'));
  }

  public function testCliModeStripsJsComponentsOnImport(): void {
    $this->setMode('cli');
    $sync = new MemoryStorage();
    $sync->write('canvas.js_component.foo', ['machineName' => 'foo']);
    $sync->write('canvas.component.js.foo', ['id' => 'js.foo', 'source' => 'js']);
    $sync->write('canvas.component.block.bar', ['id' => 'block.bar', 'source' => 'block']);

    $this->dispatchImport($sync);

    self::assertFalse($sync->exists('canvas.js_component.foo'));
    self::assertFalse($sync->exists('canvas.component.js.foo'));
    self::assertTrue($sync->exists('canvas.component.block.bar'));
  }

  public function testCliModeStripsJsComponentsOnExport(): void {
    $this->setMode('cli');
    $sync = new MemoryStorage();
    $sync->write('canvas.js_component.foo', ['machineName' => 'foo']);
    $sync->write('canvas.component.js.foo', ['id' => 'js.foo', 'source' => 'js']);

    $this->dispatchExport($sync);

    self::assertFalse($sync->exists('canvas.js_component.foo'));
    self::assertFalse($sync->exists('canvas.component.js.foo'));
  }

  public function testPreserveProductionLeavesExportAlone(): void {
    $this->setMode('preserve_production');
    $this->activeStorage->write('canvas.js_component.foo', ['machineName' => 'foo']);

    $sync = new MemoryStorage();
    $sync->write('canvas.js_component.bar', ['machineName' => 'bar']);
    $this->dispatchExport($sync);

    self::assertTrue($sync->exists('canvas.js_component.bar'));
    self::assertFalse($sync->exists('canvas.js_component.foo'));
  }

  public function testIgnoresNonDefaultCollection(): void {
    $this->setMode('cli');
    $sync = (new MemoryStorage())->createCollection('language.fr');
    $sync->write('canvas.js_component.foo', ['machineName' => 'foo']);

    $this->dispatchImport($sync);

    self::assertTrue($sync->exists('canvas.js_component.foo'));
  }

  private function setMode(string $mode): void {
    $this->config('canvas.settings')
      ->set('code_components.sync_mode', $mode)
      ->save();
  }

  private function transformer(): CodeComponentSyncTransformer {
    return new CodeComponentSyncTransformer(
      $this->container->get('config.factory'),
      $this->activeStorage,
    );
  }

  private function dispatchImport(StorageInterface $sync): void {
    $this->transformer()->onImport(new StorageTransformEvent($sync));
  }

  private function dispatchExport(StorageInterface $sync): void {
    $this->transformer()->onExport(new StorageTransformEvent($sync));
  }

}
