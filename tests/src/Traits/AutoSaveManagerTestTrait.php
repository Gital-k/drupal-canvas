<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Traits;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\file\Entity\File;
use Drupal\image\ImageStyleInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;

trait AutoSaveManagerTestTrait {

  protected const string NEW_PAGE_TITLE = 'Untitled page';
  protected const string NEW_NODE_TITLE = 'Untitled content item';

  use UserCreationTrait;

  protected static function generateAutoSaveHash(array $data): string {
    // Use reflection access private \Drupal\canvas\AutoSave\AutoSaveManager::generateHash
    $autoSaveManager = new \ReflectionClass('Drupal\canvas\AutoSave\AutoSaveManager');
    $generateHash = $autoSaveManager->getMethod('generateHash');
    $generateHash->setAccessible(TRUE);
    $hash = $generateHash->invokeArgs(NULL, [$data]);
    self::assertIsString($hash);
    return $hash;
  }

  /**
   * Builds the `autoSaves` request/response body the client exchanges.
   *
   * @param array<\Drupal\Core\Entity\EntityInterface> $entities
   *   The entities the request may update.
   * @param bool $addRegions
   *   Whether to also cover the active theme's PageRegion config entities, as
   *   the controllers building a page preview do.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::getClientAutoSaves()
   */
  protected function getClientAutoSaves(array $entities, bool $addRegions = TRUE): array {
    $autoSaveManager = \Drupal::service(AutoSaveManager::class);
    \assert($autoSaveManager instanceof AutoSaveManager);
    if ($addRegions) {
      $entities += PageRegion::loadForActiveTheme();
    }
    return ['autoSaves' => $autoSaveManager->getClientAutoSaves($entities)];
  }

  /**
   * Adds a user with picture field and sets as current.
   *
   * @return array
   *   The user, and the picture image style url.
   */
  protected function setUserWithPictureField(array $permissions): array {
    $fileUri = 'public://image-2.jpg';
    \Drupal::service(FileSystemInterface::class)->copy(\Drupal::root() . '/core/tests/fixtures/files/image-2.jpg', PublicStream::basePath(), FileExists::Replace);
    $picture = File::create([
      'uri' => $fileUri,
      'status' => TRUE,
    ]);
    $imageStyle = \Drupal::entityTypeManager()->getStorage('image_style')->load(ApiAutoSaveController::AVATAR_IMAGE_STYLE);
    self::assertInstanceOf(ImageStyleInterface::class, $imageStyle);
    $avatarUrl = $imageStyle->buildUrl($fileUri);

    $account1 = $this->createUser($permissions, values: ['user_picture' => $picture]);
    self::assertInstanceOf(AccountInterface::class, $account1);
    $this->setCurrentUser($account1);

    return [$account1, $avatarUrl];
  }

}
