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
 * 2. provides a `toSrcSet` Twig filter.
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
        'apply_image_style',
        [$this, 'applyImageStyle'],
      ),
    ];
  }

  /**
   * Generates `srcset` from URLs with ?alternateWidths and stream wrapper URIs.
   *
   * Also handles public files URLs (e.g., /sites/default/files/... including
   * styled image URLs) by converting them to public:// stream wrapper URIs
   * when possible.
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
    $hasAlternateWidths = FALSE;
    if ($query) {
      parse_str($query, $params);
      if (!empty($params[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM])) {
        // We only expect 1 `alternateWidths` query parameter.
        \assert(\is_string($params[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM]));
        $template = urldecode($params[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM]);
        $hasAlternateWidths = TRUE;
      }
    }
    // Stream wrappers.
    if (!$hasAlternateWidths && $this->streamWrapperManager->isValidUri($src)) {
      $template = ParametrizedImageStyle::load('canvas_parametrized_width')?->buildUrlTemplate($src);
      if (\is_string($template)) {
        $template = $this->fileUrlGenerator->transformRelative($template);
      }
      // Respect the specified width, if any, but ensure that it's never bigger
      // than the actual image width.
      $actual_intrinsic_image_width = $this->getWidth($src);
      $intrinsicImageWidth = $intrinsicImageWidth === NULL
        ? $actual_intrinsic_image_width
        : min($intrinsicImageWidth, $actual_intrinsic_image_width);
      if (\is_null($intrinsicImageWidth)) {
        $intrinsicImageWidth = $this->getWidth($src);
      }
    }
    // Public files URLs (e.g., /sites/default/files/image.jpg).
    // Convert to public:// URI when possible, then use same logic as above.
    elseif (!$hasAlternateWidths && !$this->streamWrapperManager->isValidUri($src)) {
      $uri = $this->urlToStreamWrapperUri($src);
      if ($uri) {
        $template = ParametrizedImageStyle::load('canvas_parametrized_width')?->buildUrlTemplate($uri);
        if (is_string($template)) {
          $template = $this->fileUrlGenerator->transformRelative($template);
        }
        // Trust the passed width if available, otherwise try to read from file.
        if (is_null($intrinsicImageWidth)) {
          $intrinsicImageWidth = $this->getWidth($uri);
        }
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
   * Converts a public files URL back to its stream wrapper URI.
   *
   * This reverses what \Drupal\Core\StreamWrapper\PublicStream::getLocalPath()
   * does, but only for public:// files.
   *
   * @param string $url
   *   A public files URL (e.g., /sites/default/files/image.jpg).
   *
   * @return string|null
   *   The public:// stream wrapper URI, or NULL if not a public files URL.
   */
  private function urlToStreamWrapperUri(string $url): ?string {
    $publicBasePath = PublicStream::basePath();
    $path = ltrim(parse_url($url, PHP_URL_PATH) ?? $url, '/');

    // Check if this is a public files URL.
    if (!str_starts_with($path, $publicBasePath . '/')) {
      return NULL;
    }

    // Extract the path after the public files base.
    $target = substr($path, strlen($publicBasePath) + 1);

    // Return the stream wrapper URI.
    return 'public://' . $target;
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
   * Applies an image style to an image object.
   *
   * This filter takes an image object and applies a Drupal image style,
   * returning a new object with the styled URL and transformed dimensions.
   * The dimensions are calculated using ImageStyle::transformDimensions()
   * so the styled derivative doesn't need to exist yet.
   *
   * @param array $image
   *   Image object with 'src', 'width', 'height', and optionally 'alt'.
   * @param string $styleName
   *   The image style machine name.
   *
   * @return array
   *   Image object with 'src' as styled URL and transformed 'width'/'height'.
   */
  public function applyImageStyle(array $image, string $styleName): array {
    $result = $image;
    $src = $image['src'] ?? '';

    if (empty($src)) {
      return $result;
    }

    $style = ImageStyle::load($styleName);
    if (!$style) {
      return $result;
    }

    // Convert src to URI if needed.
    $uri = $this->streamWrapperManager->isValidUri($src)
      ? $src
      : $this->urlToStreamWrapperUri($src);

    if (!$uri || !$style->supportsUri($uri)) {
      return $result;
    }

    // Get styled URL.
    $result['src'] = $this->fileUrlGenerator->transformRelative($style->buildUrl($uri));

    // Transform dimensions if we have them.
    if (!empty($image['width']) && !empty($image['height'])) {
      $dimensions = [
        'width' => (int) $image['width'],
        'height' => (int) $image['height'],
      ];
      $style->transformDimensions($dimensions, $uri);
      $result['width'] = $dimensions['width'];
      $result['height'] = $dimensions['height'];
    }

    return $result;
  }

}
