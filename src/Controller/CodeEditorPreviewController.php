<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\GlobalImports;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\Core\Extension\ExtensionPathResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the code editor preview iframe's document from its own origin.
 *
 * Reachable without authentication: both responses are Canvas's own fixed,
 * non-secret, unauthenticated code, not the dynamic per-component payload
 * (which the parent window sends via `postMessage()` instead).
 *
 * @see ui/src/features/code-editor/Preview.tsx
 * @see https://www.drupal.org/i/3527515
 */
final class CodeEditorPreviewController {

  private const string HTML = <<<HTML
<!doctype html>
<html>
<head>
<script type="module">
// This document's own opaque origin has no classifiable network address
// space, so Chrome blocks any subresource fetch it makes to a private
// network target (this site's own host) outright, before the request is
// even sent — neither `code-editor-preview.js` itself nor the bare-specifier
// imports (`preact`, etc.) it needs can be loaded over the network from in
// here. The parent fetches all of it instead, from its real origin, and
// relays it over `postMessage()` as `Blob`s (which survive `postMessage()`'s
// structured clone across an opaque-origin boundary): the script's own
// source, and one `Blob` per import map entry. Turning each into a local
// blob: URL and dynamically importing one are both purely local operations,
// not network fetches, regardless of how the `Blob` got here.
// @see ui/src/features/code-editor/Preview.tsx
window.addEventListener('message', function (event) {
  if (event.source !== window.parent || event.data?.type !== '_canvas_preview_script_source') {
    return;
  }
  if (event.data.importMap) {
    var imports = {};
    for (var specifier in event.data.importMap) {
      imports[specifier] = URL.createObjectURL(event.data.importMap[specifier]);
    }
    var importMapScript = document.createElement('script');
    importMapScript.type = 'importmap';
    importMapScript.textContent = JSON.stringify({ imports: imports });
    document.head.appendChild(importMapScript);
  }
  var url = URL.createObjectURL(event.data.source);
  import(url).finally(function () {
    URL.revokeObjectURL(url);
  });
});
window.parent.postMessage({ type: '_canvas_preview_bootstrap_ready' }, '*');
</script>
</head>
<body>
<div id="canvas-code-editor-preview-root"></div>
<script>
document.addEventListener('click', function (e) {
  const anchor = e.target.closest('a');
  if (anchor) {
    e.preventDefault();
    e.stopPropagation();
  }
});
</script>
</body>
</html>
HTML;

  public function __construct(
    private readonly GlobalImports $globalImports,
    private readonly ExtensionPathResolver $extensionPathResolver,
  ) {}

  /**
   * Serves the preview shell document.
   *
   * Loaded via the iframe's `src` attribute (not `srcdoc`), so it gets an
   * opaque origin under `sandbox="allow-scripts"` (no `allow-same-origin`).
   *
   * Entirely static: everything the previous version needed a Twig context
   * for (the import map, the script URL) is now fetched and relayed by the
   * parent instead — @see `importMap()` and `script()` below.
   */
  public function shell(): Response {
    $response = new Response(self::HTML);
    $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
    return $response;
  }

  /**
   * Serves `ui/dist/assets/code-editor-preview.js`.
   *
   * Fetched by the parent window itself, not by the sandboxed shell — the
   * shell's opaque origin can't fetch this itself.
   *
   * @see ui/src/features/code-editor/Preview.tsx
   */
  public function script(): BinaryFileResponse {
    $file_path = \sprintf(
      '%s/%s/ui/dist/assets/code-editor-preview.js',
      DRUPAL_ROOT,
      $this->extensionPathResolver->getPath('module', 'canvas'),
    );
    if (!\is_file($file_path)) {
      throw new NotFoundHttpException();
    }
    $response = new BinaryFileResponse($file_path);
    $response->headers->set('Content-Type', 'text/javascript');
    $response->headers->set('Cache-Control', 'no-cache');
    return $response;
  }

  /**
   * Serves the import map as specifier => URL JSON.
   *
   * These are the bare-specifier imports (`preact`, `react`, etc.) the
   * preview script needs to resolve its own imports. Fetched by the parent
   * window, same as `script()` above — the shell can't fetch these itself.
   * The parent fetches each URL's content and relays it to the shell as a
   * `Blob`, so these are the real static file paths; unlike the previous
   * `asset()` route this replaces, no CORS header is needed, since the
   * parent is same-origin.
   *
   * Same as the base import map used by the main Canvas app
   * (@see \Drupal\canvas\GlobalImports::getImportMap()). Deliberately
   * missing a `@/components/` entry other auto-saved code components need
   * to resolve: that would be a prefix mapping, not a single fetchable
   * file, and `code-editor-preview.js` already rewrites those specifiers to
   * blob URLs directly instead (@see its `rewriteComponentImports()`).
   */
  public function importMap(): JsonResponse {
    $import_map = $this->globalImports->getImportMap();
    return new JsonResponse($import_map[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]);
  }

}
