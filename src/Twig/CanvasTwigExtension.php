<?php

declare(strict_types=1);

namespace Drupal\canvas\Twig;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\canvas\Entity\ParametrizedImageStyle;
use Drupal\image\Entity\ImageStyle;
use Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\canvas\Routing\ParametrizedImageStyleConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Defines a Twig extension to support Drupal Canvas.
 *
 * This:
 * 1. adds metadata to output as HTML comments
 * 2. provides a `toSrcSet` Twig filter
 */
final class CanvasTwigExtension extends AbstractExtension {

  /**
   * Constructs a new CanvasTwigExtension object.
   *
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager service.
   * @param \Drupal\Core\Image\ImageFactory $imageFactory
   *   The image factory service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   */
  public function __construct(
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly ImageFactory $imageFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getNodeVisitors(): array {
    return [
      new CanvasPropVisitor(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters(): array {
    return [
      new TwigFilter(
        'toSrcSet',
        [$this, 'toSrcSet'],
      ),
      new TwigFilter(
        'getWidth',
        [$this, 'getWidth'],
      ),
      new TwigFilter(
        'getHeight',
        [$this, 'getHeight'],
      ),
      new TwigFilter(
        'image_style',
        [$this, 'applyImageStyle'],
      ),
    ];
  }

  /**
   * Generates `srcset` from URLs with ?alternateWidths and stream wrapper URIs.
   *
   * @param string $src
   *   An img.src attribute.
   * @param int|null $intrinsicImageWidth
   *   The intrinsic width of the image in $src.
   *
   * @return null|string
   *   A `srcset` string, or NULL if none could be generated.
   */
  public function toSrcSet(string $src, ?int $intrinsicImageWidth = NULL): ?string {
    $template = NULL;

    // URLs with alternateWidths query parameter.
    $query = parse_url($src, PHP_URL_QUERY);
    if ($query) {
      parse_str($query, $params);
      if (!empty($params[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM])) {
        // We only expect 1 `alternateWidths` query parameter.
        \assert(is_string($params[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM]));
        $template = urldecode($params[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM]);
      }
    }
    // Stream wrappers.
    elseif ($this->streamWrapperManager->isValidUri($src)) {
      $template = ParametrizedImageStyle::load('canvas_parametrized_width')?->buildUrlTemplate($src);
      if (is_string($template)) {
        $template = $this->fileUrlGenerator->transformRelative($template);
      }
      // Respect the specified width, if any, but ensure that it's never bigger
      // than the actual image width.
      $actual_intrinsic_image_width = $this->getWidth($src);
      $intrinsicImageWidth = $intrinsicImageWidth === NULL
        ? $actual_intrinsic_image_width
        : min($intrinsicImageWidth, $actual_intrinsic_image_width);
      if (is_null($intrinsicImageWidth)) {
        $intrinsicImageWidth = $this->getWidth($src);
      }
    }

    if (empty($template) || empty($intrinsicImageWidth)) {
      return NULL;
    }

    \assert(str_contains($template, '{width}'), "Expected '{width}' in template not found");

    // Filter widths greater than the intrinsic width to avoid generating
    // upscaled images. We still create a srcset candidate when the width is the
    // same so we can do other things to it like convert it to a more optimized
    // format.
    // @todo Read this from third-party settings: https://drupal.org/i/3533563
    $widths = array_filter(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, static fn($w) => $w <= $intrinsicImageWidth);

    $srcset = \array_map(static fn($w) => str_replace('{width}', (string) $w, $template) . " {$w}w", $widths);
    return implode(', ', $srcset);
  }

  /**
   * Gets the width of an image from a given source path or URL.
   *
   * @param string $src
   *   The image source path, URL, or stream wrapper URI.
   *
   * @return int|null
   *   The width of the image in pixels, or NULL if the source is invalid
   *   or the image cannot be processed.
   */
  public function getWidth(string $src): ?int {
    if (UrlHelper::isValid($src) || $this->streamWrapperManager->isValidUri($src)) {
      $image = $this->imageFactory->get(ltrim($src, "/"));
      if ($image->isValid()) {
        return $image->getWidth();
      }
    }
    return NULL;
  }

  /**
   * Gets the height of an image from a given source path or URL.
   *
   * @param string $src
   *   The image source path, URL, or stream wrapper URI.
   *
   * @return int|null
   *   The height of the image in pixels, or NULL if the source is invalid
   *   or the image cannot be processed.
   */
  public function getHeight(string $src): ?int {
    if (UrlHelper::isValid($src) || $this->streamWrapperManager->isValidUri($src)) {
      $image = $this->imageFactory->get(ltrim($src, "/"));
      if ($image->isValid()) {
        return $image->getHeight();
      }
    }
    return NULL;
  }

  /**
   * Applies an image style to an image and returns the styled derivative URI.
   *
   * Returns a stream wrapper URI for the styled derivative, which can be
   * passed to the Canvas Image component. This allows the Image component
   * to generate responsive images via toSrcSet.
   *
   * Accepts the same input formats as the Canvas Image component:
   * - Stream wrapper URIs (e.g., public://image.jpg)
   * - Local file URLs (e.g., /sites/default/files/image.jpg)
   * - External URLs (returned unchanged - cannot be styled)
   *
   * @param string $src
   *   The image source: stream wrapper URI, local URL, or external URL.
   * @param string $styleName
   *   The machine name of the image style.
   *
   * @return string|null
   *   The stream wrapper URI for the styled derivative (e.g.,
   *   public://styles/thumbnail/public/image.jpg), or the original URL if
   *   styling is not possible, or NULL if the style doesn't exist.
   */
  public function applyImageStyle(string $src, string $styleName): ?string {
    $style = ImageStyle::load($styleName);
    if (!$style) {
      return NULL;
    }

    // Handle stream wrapper URIs directly (e.g., public://image.jpg).
    if ($this->streamWrapperManager->isValidUri($src)) {
      // Return the styled derivative URI so it can work with toSrcSet.
      return $style->buildUri($src);
    }

    // Handle local file URLs by converting to stream wrapper URI.
    // Check if this is a local URL pointing to the public files directory.
    $publicBasePath = PublicStream::basePath();
    $path = ltrim(parse_url($src, PHP_URL_PATH) ?? $src, '/');

    if (str_starts_with($path, $publicBasePath . '/')) {
      // Convert /sites/default/files/image.jpg -> public://image.jpg
      $target = substr($path, strlen($publicBasePath) + 1);
      $uri = 'public://' . $target;
      // Return the styled derivative URI so it can work with toSrcSet.
      return $style->buildUri($uri);
    }

    // External URLs or unrecognized paths - return unchanged.
    return $src;
  }

}
