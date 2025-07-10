<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

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

}
