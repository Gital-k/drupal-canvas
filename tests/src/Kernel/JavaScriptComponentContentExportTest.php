<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

// cspell:ignore kiki

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ExportStorageManager;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;

class JavaScriptComponentContentExportTest extends KernelTestBase {

  const FIXTURE_IMAGES = [
    '/fixtures/images/cosmo.jpg',
    '/fixtures/images/kiki.jpeg',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'block_content',
    'config',
    'path_alias',
    'system',
    'user',
    'field',
    'file',
    'image',
    'experience_builder',
  ];

  protected array $encodedFiles;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('file');
    $this->installEntitySchema('user');
    $this->installSchema('file', ['file_usage']);

    foreach (self::FIXTURE_IMAGES as $image_path) {
      $path = \dirname(__DIR__, 2) . $image_path;
      $contents = \file_get_contents($path);
      \assert(\is_string($contents));

      // This is intentionally here until FE counterpart of this work is in.
      // After that work is complete and merged, UUID generation based on hash
      // can be refactored and moved into form submission, somewhere in
      // JavascriptComponent or into some utility class.
      $hashing = hash('sha1', $contents);
      $uuid = sprintf('%08s-%04s-%04x-%04x-%12s',
        // 32 bits for the time_low
        substr($hashing, 0, 8),
        // 16 bits for the time_mid
        substr($hashing, 8, 4),
        // 16 bits for the time_hi,
        (hexdec(substr($hashing, 12, 4)) & 0x0fff) | 0x5000,
        // 8 bits and 16 bits for the clk_seq_hi_res,
        // 8 bits for the clk_seq_low,
        (hexdec(substr($hashing, 16, 4)) & 0x3fff) | 0x8000,
        // 48 bits for the node
        substr($hashing, 20, 12)
      );
      $this->encodedFiles[$uuid] = [
        'filename' => basename($image_path),
        'data' => \base64_encode($contents),
      ];

    }

    $file_system = \Drupal::service('file_system');
    $directory = 'public://js_component/defaults';
    $file_system->prepareDirectory($directory, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $directory = 'temporary://js_component/defaults';
    $file_system->prepareDirectory($directory, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  }

  public function testDefaultImages(): void {

    // No js_component, no file entities exist and no files in-use.
    $this->assertSame(0, \Drupal::entityTypeManager()->getStorage('file')->getQuery()->count()->accessCheck(FALSE)->execute());
    $file_usage_count_query = \Drupal::database()->select('file_usage')->countQuery();
    $usage_count = $file_usage_count_query->execute();
    assert($usage_count instanceof StatementInterface);
    $this->assertSame(0, (int) $usage_count->fetchField());
    $this->assertSame([], JavaScriptComponent::loadMultiple());

    $first_image_uuid = \array_key_first($this->encodedFiles);
    // 2. Create new JavascriptComponent using default image.
    $code_component = JavaScriptComponent::create([
      'encoded_files' => [
        $first_image_uuid => $this->encodedFiles[$first_image_uuid],
      ],
      'machineName' => $this->randomMachineName(),
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
    ]);
    $code_component->save();
    $this->assertCount(1, JavaScriptComponent::loadMultiple());

    // Assert that new File entity was created.
    $this->assertSame(1, \Drupal::entityTypeManager()->getStorage('file')->getQuery()->count()->accessCheck(FALSE)->execute());
    // Assert that the file is used.
    $usage_count = $file_usage_count_query->execute();
    assert($usage_count instanceof StatementInterface);
    $this->assertSame(1, (int) $usage_count->fetchField());

    // 3. Create new JavascriptComponent using same default image.
    $code_component = JavaScriptComponent::create([
      'encoded_files' => [
        $first_image_uuid => $this->encodedFiles[$first_image_uuid],
      ],
      'machineName' => $this->randomMachineName(),
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
    ]);
    $code_component->save();

    $this->assertCount(2, JavaScriptComponent::loadMultiple());

    // Assert that no new File entities were created.
    $this->assertSame(1, \Drupal::entityTypeManager()->getStorage('file')->getQuery()->count()->accessCheck(FALSE)->execute());
    // Assert that the file is used by two js_components.
    $usage_count = $file_usage_count_query->execute();
    assert($usage_count instanceof StatementInterface);
    $this->assertSame(2, (int) $usage_count->fetchField());

    $last_image_uuid = \array_key_last($this->encodedFiles);
    // 4. Create new JavascriptComponent using same different default image.
    $code_component = JavaScriptComponent::create([
      'encoded_files' => [
        $last_image_uuid => $this->encodedFiles[$last_image_uuid],
      ],
      'machineName' => $this->randomMachineName(),
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
    ]);
    $code_component->save();

    $code_components = JavaScriptComponent::loadMultiple();
    $this->assertCount(3, $code_components);
    foreach ($code_components as $code_component) {
      $encoded_files = $code_component->get('encoded_files');
      foreach ($encoded_files as $encoded_file) {
        self::assertArrayNotHasKey('data', $encoded_file);
      }
      $this->assertEquals(SAVED_UPDATED, $code_component->save());
    }

    // Assert that additional File entity was created.
    $this->assertSame(2, \Drupal::entityTypeManager()->getStorage('file')->getQuery()->count()->accessCheck(FALSE)->execute());
    // Assert that new file is used by js_component.
    $usage_count = $file_usage_count_query->execute();
    assert($usage_count instanceof StatementInterface);
    $this->assertSame(3, (int) $usage_count->fetchField());
  }

  public function testDefaultImageExport(): void {
    // 1. Create new JavascriptComponent using default image.
    $code_component = JavaScriptComponent::create([
      'encoded_files' => $this->encodedFiles,
      'machineName' => $this->randomMachineName(),
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
    ]);
    $code_component->save();

    $this->assertCount(1, JavaScriptComponent::loadMultiple());
    $count_query = \Drupal::database()->select('file_usage')->countQuery()->execute();
    assert($count_query instanceof StatementInterface);
    $this->assertSame(2, (int) $count_query->fetchField());

    $file = \Drupal::service(EntityRepositoryInterface::class)->loadEntityByUuid('file', 'f8133ff8-3276-5d4d-b3f9-8fb6b363fb70');
    assert($file instanceof FileInterface);
    $this->assertStringStartsWith('public://js_component/defaults', (string) $file->getFileUri());

    // The export storage manager under test.
    $export_manager = new ExportStorageManager(
      $this->container->get('config.storage'),
      $this->container->get('database'),
      $this->container->get('event_dispatcher'),
      new NullLockBackend()
    );

    // 2. Export component: prove the config export transformation
    $storage = $export_manager->getStorage();
    $exported_code_component = $storage->read($code_component->getConfigDependencyName());
    assert(is_array($exported_code_component));
    $this->assertEquals(array_multisort($this->encodedFiles), array_multisort($exported_code_component['encoded_files']));

    // 3. Delete the file and code component: empty slate, to simulate importing the component on a different site.
    $components = Component::loadMultiple();
    foreach ($components as $component) {
      $component->delete();
    }
    $code_component->delete();
    foreach (File::loadMultiple() as $file) {
      $file->delete();
    }
    $this->assertCount(0, File::loadMultiple());
    $this->assertCount(0, JavaScriptComponent::loadMultiple());

    // 4. Import component: prove the import transformation.
    $storage = new MemoryStorage();
    $this->copyConfig($this->container->get('config.storage'), $storage);
    $storage->write($code_component->getConfigDependencyName(), $exported_code_component);
    $import = $this->container->get('config.import_transformer')->transform($storage);

    $storage_comparer = new StorageComparer(
      $import,
      $this->container->get('config.storage')
    );
    $this->configImporter = new ConfigImporter(
      $storage_comparer->createChangelist(),
      $this->container->get('event_dispatcher'),
      $this->container->get('config.manager'),
      $this->container->get('lock'),
      $this->container->get('config.typed'),
      $this->container->get('module_handler'),
      $this->container->get('module_installer'),
      $this->container->get('theme_handler'),
      $this->container->get('string_translation'),
      $this->container->get('extension.list.module'),
      $this->container->get('extension.list.theme')
    );
    $this->configImporter->import();

    $imported_config = $import->read($code_component->getConfigDependencyName());
    $this->assertIsArray($imported_config);
    foreach ($imported_config['encoded_files'] as $encoded_file) {
      $this->assertArrayNotHasKey('data', $encoded_file);
    }
    $this->assertCount(2, File::loadMultiple());
    $this->assertCount(1, JavaScriptComponent::loadMultiple());
    $this->assertEquals($code_component->toArray(), $import->read($code_component->getConfigDependencyName()));

    $files = File::loadMultiple();
    $matches = [];
    foreach ($files as $file) {
      foreach ($this->encodedFiles as $uuid => $encoded_file) {
        if ($file->uuid() === $uuid) {
          $matches[] = TRUE;
        }
      }
    }
    $this->assertNotEmpty($matches);
  }

}
