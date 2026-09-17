<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DataTypeOverride;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\TypedData\Plugin\DataType\Uri;

/**
 * @todo Fix upstream: `uri` data type *never* returns valid URIs without this!
 */
class UriOverride extends Uri {

  /**
   * {@inheritdoc}
   */
  public function getCastedValue() {
    $components = parse_url($this->value);
    // Without at least a scheme and host, there's no hope of casting this to a
    // valid URI. Abort.
    if (!\is_array($components) || !\array_key_exists('scheme', $components) || !\array_key_exists('host', $components)) {
      return $this->value;
    }

    // ⚠️ The authority must be encoded too, not just the path: this data type
    // also represents stream wrapper URIs, whose first segment after `://` is
    // a directory or — for a file stored at the stream wrapper root — the file
    // name itself, both of which `parse_url()` reports as the authority.
    // Leaving it unencoded returns an invalid URI for any such file name
    // containing a space or a non-ASCII character.
    // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\FileUriItemOverride
    $uri = $components['scheme'] . '://'
      . UrlHelper::encodePath($components['host'])
      . UrlHelper::encodePath($components['path'] ?? '');

    if (\array_key_exists('query', $components) && strlen($components['query'])) {
      $uri .= '?' . $components['query'];
    }
    if (\array_key_exists('fragment', $components)) {
      $uri .= '#' . $components['fragment'];
    }

    \assert(UrlHelper::isValid($uri));
    return $uri;
  }

}
