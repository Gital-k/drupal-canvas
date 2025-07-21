<?php

declare(strict_types=1);

// cspell:ignore kiki

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;

trait DefaultImagesTrait {
  const FIXTURE_IMAGES = [
    '/fixtures/images/cosmo.jpg',
    '/fixtures/images/kiki.jpeg',
  ];

  public static function getEncodedImages(): array {
    $encoded_images = [];
    foreach (self::FIXTURE_IMAGES as $image_path) {
      $path = \dirname(__DIR__, 2) . $image_path;
      $contents = \file_get_contents($path);
      \assert(\is_string($contents));

      $encoded_images[] = [
        'filename' => \basename($image_path),
        'data' => \base64_encode($contents),
      ];

    }

    return $encoded_images;
  }

  public function assertFileUsageCount(string $fileUuid, int $count): void {
    $file = \Drupal::service(EntityRepositoryInterface::class)->loadEntityByUuid('file', $fileUuid);
    assert($file instanceof FileInterface);
    $file_usage_count_query = \Drupal::database()->select('file_usage', 'f')
      ->where('[f].[fid] = :fid', [
        ':fid' => $file->id(),
      ])
      ->countQuery();
    $usage_count = $file_usage_count_query->execute();
    assert($usage_count instanceof StatementInterface);
    $this->assertSame($count, (int) $usage_count->fetchField());
  }

  public function assertComponentUsesFile(string $fileUuid, string $componentId): void{
    $file = \Drupal::service(EntityRepositoryInterface::class)->loadEntityByUuid('file', $fileUuid);
    assert($file instanceof FileInterface);
    $file_usage_count_query = \Drupal::database()->select('file_usage', 'f')
      ->where('[f].[fid] = :fid AND [f].[id] = :id', [
        ':fid' => $file->id(),
        ':id' => $componentId,
      ])
      ->countQuery();
    $usage_count = $file_usage_count_query->execute();
    assert($usage_count instanceof StatementInterface);
    $this->assertSame(1, (int) $usage_count->fetchField());
  }

}
