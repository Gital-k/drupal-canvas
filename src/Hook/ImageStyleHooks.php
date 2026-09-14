<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Entity\ParametrizedImageStyle;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\image\Entity\ImageStyle;

/**
 * Hook implementations keeping parametrized image styles in sync.
 *
 * Flushing the image style a parametrized style is derived from must also flush
 * the parametrized style, whose derivatives core does not know about.
 *
 * @see \Drupal\canvas\Entity\ParametrizedImageStyle
 */
final class ImageStyleHooks {

  /**
   * Implements hook_image_style_flush().
   */
  #[Hook('image_style_flush')]
  public static function imageStyleFlush(ImageStyle $style, ?string $path = NULL): void {
    // Avoid recursion.
    if ($style instanceof ParametrizedImageStyle) {
      return;
    }
    if ($style->id() === 'canvas_parametrized_width') {
      ParametrizedImageStyle::load('canvas_parametrized_width')?->flush($path);
    }
  }

}
