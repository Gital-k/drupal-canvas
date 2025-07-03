<?php

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FileDependencyHandler implements EventSubscriberInterface {

  public function __construct(
    protected readonly EntityRepositoryInterface $entityRepository,
    protected readonly FileSystemInterface $fileSystem,
  ) {}

  public function onExportTransform(StorageTransformEvent $event): void {
    $storage = $event->getStorage();

    foreach ($storage->listAll() as $name) {
      if (!str_starts_with($name, 'experience_builder.js_component.')) {
        continue;
      }
      $config = $storage->read($name);
      if (!empty($config['encoded_files'])) {
        $changed = FALSE;
        foreach ($config['encoded_files'] as $key => $encoded_file) {
          $file = $this->entityRepository->loadEntityByUuid('file', $encoded_file['uuid']);
          if ($file instanceof FileInterface) {
            $content = file_get_contents((string) $file->getFileUri());
            if (!empty($content)) {
              $config['encoded_files'][$key]['base64'] = base64_encode($content);
            }
            $changed = TRUE;
          }
        }
        if ($changed) {
          $storage->write($name, $config);
        }
      }
    }
  }

  public function onImportTransform(StorageTransformEvent $event): void {
    $storage = $event->getStorage();
    $temporary_image_directory = JavaScriptComponent::TEMPORARY_IMAGE_DIRECTORY;
    $this->fileSystem->prepareDirectory($temporary_image_directory, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    foreach ($storage->listAll() as $name) {
      if (!str_starts_with($name, 'experience_builder.js_component.')) {
        continue;
      }
      $config = $storage->read($name);
      $changed = FALSE;
      if (isset($config['encoded_files'])) {
        foreach ($config['encoded_files'] as $key => $encoded_file) {
          $file = $this->entityRepository->loadEntityByUuid('file', $encoded_file['uuid']);
          // UUID is based on hash, so if the file exists there's nothing to do.
          if (!$file instanceof FileInterface) {
            $filename = $this->fileSystem->createFilename($encoded_file['filename'], $temporary_image_directory);

            $file = File::create([
              'uuid' => $encoded_file['uuid'],
              'uri' => $filename,
            ]);
            $this->fileSystem->saveData(base64_decode($encoded_file['base64']), (string) $file->getFileUri(), FileExists::Rename);

            $file->setTemporary();
            $file->save();
          }
          unset($encoded_file['base64']);
          $config['encoded_files'][$key] = $encoded_file;
          $changed = TRUE;
        }
      }
      if ($changed && !empty($config)) {
        $storage->write($name, $config);
      }
    }
  }

  public static function getSubscribedEvents(): array {
    $events[ConfigEvents::STORAGE_TRANSFORM_EXPORT][] = ['onExportTransform'];
    $events[ConfigEvents::STORAGE_TRANSFORM_IMPORT][] = ['onImportTransform'];
    return $events;
  }

}
