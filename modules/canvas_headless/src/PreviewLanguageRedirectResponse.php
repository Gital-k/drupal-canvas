<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Core\Routing\LocalRedirectResponse;

/**
 * A content-API transport redirect, not a frontend navigation result.
 *
 * @internal
 */
final class PreviewLanguageRedirectResponse extends LocalRedirectResponse {

  public function __construct(string $url) {
    parent::__construct($url);
    $this->getCacheableMetadata()->setCacheMaxAge(0);
    $this->headers->set('Cache-Control', 'private, no-store');
  }

}
