<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[Group('canvas')]
#[RunTestsInSeparateProcesses]
class FolderTest extends CanvasKernelTestBase {

  protected Folder $entity;

  protected function setUp(): void {
    parent::setUp();
    $this->entity = Folder::create([
      'name' => 'Test folder, please ignore',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
    ]);
    $this->entity->save();
  }

  public function testFolderAutoCreationValidation(): void {
    $folders = Folder::loadMultiple();
    // 1. At the start, the ::setUp()-created Folder must exist.
    // Additional Folders may already exist due to auto-generated Block
    // Components.
    $this->assertArrayHasKey($this->entity->id(), $folders);
    $this->enableModules(['canvas_test_sdc']);

    // 2. Generate Component config entities, this will create additional Folder
    // entities.
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $folders = Folder::loadMultiple();

    // 3. Folder created during ::setUp() still exists.
    $this->assertArrayHasKey($this->entity->id(), $folders);

    // 4. It is not the only Folder entity anymore.
    $folder_labels = \array_map(fn (Folder $folder) => $folder->label(), \array_values($folders));
    \sort($folder_labels);
    // No 'Forms' or 'core' Folder: every Component in those categories is a
    // core-provided Block Component, which is generated disabled, and Folders
    // are only created for enabled Components.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery::computeInitialComponentStatus()
    $this->assertEquals([
      'Atom/Media',
      'Atom/Tabs',
      'Atom/Text',
      'Container',
      'Container/Special',
      'Menus',
      'Other',
      'Status',
      'System',
      'Test folder, please ignore',
    ], $folder_labels);

    // 5. Delete all folders and regenerate Components. As no new Components
    // will be created, no Folder entities will be created either.
    foreach ($folders as $folder) {
      $folder->delete();
    }
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $folders = Folder::loadMultiple();

    $folder_labels = \array_map(fn (Folder $folder) => $folder->label(), \array_values($folders));
    \sort($folder_labels);
    $this->assertEquals([], $folder_labels);

    // 6. Delete all Components and folders and regenerate Components. As new
    // Components will created, Folder entities matching Component default
    // folder values will be created.
    foreach ($folders as $folder) {
      $folder->delete();
    }
    foreach (Component::loadMultiple() as $component) {
      $component->delete();
    }

    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $folders = Folder::loadMultiple();

    $folder_labels = \array_map(fn (Folder $folder) => $folder->label(), \array_values($folders));
    \sort($folder_labels);
    $this->assertEquals([
      'Atom/Media',
      'Atom/Tabs',
      'Atom/Text',
      'Container',
      'Container/Special',
      'Menus',
      'Other',
      'Status',
      'System',
    ], $folder_labels);
  }

  /**
   * Tests that a Folder is created when a disabled Component is enabled.
   */
  public function testFolderCreatedWhenComponentIsEnabled(): void {
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    // Core-provided Block Components are generated disabled, so no Folder was
    // created for their category.
    $disabled = \array_filter(Component::loadMultiple(), fn (Component $component): bool => !$component->status());
    $this->assertNotEmpty($disabled);
    $component = \reset($disabled);
    $category = (string) $component->getComponentSource()->determineDefaultFolder();
    // Use a Block Component whose category has only disabled Components, so
    // no Folder exists yet. Categories such as System/Menus already have a
    // Folder from BLOCKS_TO_KEEP_ENABLED.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery::computeInitialComponentStatus()
    $component = Component::load('block.local_actions_block');
    $this->assertInstanceOf(Component::class, $component);
    $this->assertFalse($component->status());
    $category = (string) $component->getComponentSource()->determineDefaultFolder();
    $this->assertSame('core', $category);
    $this->assertNull(Folder::loadByNameAndConfigEntityTypeId($category, Component::ENTITY_TYPE_ID));

    // Enabling it files it into a Folder named after its category.
    $component->setStatus(TRUE)->save();
    $folder = Folder::loadByItemAndConfigEntityTypeId($component->id(), Component::ENTITY_TYPE_ID);
    \assert($folder instanceof Folder);
    $this->assertSame($category, $folder->label());

    // Disabling and re-enabling does not move it back out of a Folder the site
    // owner chose: it is already in one, so it is left alone.
    $folder->removeItem($component->id())->save();
    $this->entity->addItems([$component->id()])->save();
    $component->setStatus(FALSE)->save();
    $component->setStatus(TRUE)->save();
    $this->assertSame(
      $this->entity->id(),
      Folder::loadByItemAndConfigEntityTypeId($component->id(), Component::ENTITY_TYPE_ID)?->id(),
    );
  }

  public function testComponentDeletionModifiesFolder(): void {
    $this->enableModules(['canvas_test_sdc']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    // 1. Load Folder that contains 'sdc.canvas_test_sdc.card'.
    $original_folder = Folder::loadByItemAndConfigEntityTypeId('sdc.canvas_test_sdc.card', Component::ENTITY_TYPE_ID);
    \assert($original_folder instanceof Folder);

    // 2. Remove 'sdc.canvas_test_sdc.card' from its default Folder.
    $original_folder->removeItem('sdc.canvas_test_sdc.card')->save();

    // 3. Add 'sdc.canvas_test_sdc.card' to our test Folder.
    $this->entity->addItems(['sdc.canvas_test_sdc.card'])->save();
    $this->assertEquals(['sdc.canvas_test_sdc.card'], $this->entity->get('items'));

    // 4. Delete Component entity, this should remove it from test Folder.
    Component::load('sdc.canvas_test_sdc.card')?->delete();
    $this->assertEquals([], Folder::load($this->entity->id())?->get('items'));

    // 5. Delete another Component from its default Folder, this should remove
    // the Component from Folder items list.
    $default_folder_id = Folder::loadByItemAndConfigEntityTypeId('sdc.canvas_test_sdc.attributes', Component::ENTITY_TYPE_ID);
    \assert($default_folder_id instanceof Folder);
    Component::load('sdc.canvas_test_sdc.attributes')?->delete();
    $this->assertNotContains('sdc.canvas_test_sdc.attributes', Folder::load($default_folder_id->id())?->get('items'));
  }

}
