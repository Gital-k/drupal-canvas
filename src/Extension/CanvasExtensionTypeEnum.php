<?php

declare(strict_types=1);

namespace Drupal\canvas\Extension;

/**
 * The surface of the Canvas UI an extension plugs into.
 */
enum CanvasExtensionTypeEnum: string {
  case Canvas = 'canvas';
  case CodeEditor = 'code-editor';
  case Page = 'page';
}
