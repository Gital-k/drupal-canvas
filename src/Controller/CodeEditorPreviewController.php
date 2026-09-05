<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\GlobalImports;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
{{ import_map }}
<script type="module" src="{{ script_url }}"></script>
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
    private readonly RendererInterface $renderer,
    private readonly ExtensionPathResolver $extensionPathResolver,
  ) {}

  /**
   * Serves the preview shell document.
   *
   * Loaded via the iframe's `src` attribute (not `srcdoc`), so it gets an
   * opaque origin under `sandbox="allow-scripts"` (no `allow-same-origin`).
   */
  public function shell(): Response {
    $import_map_tag = ImportMapResponseAttachmentsProcessor::buildHtmlTagForAttachedImportMaps(
      (new HtmlResponse())->setAttachments(['import_maps' => $this->buildImportMap()]),
    );
    \assert($import_map_tag !== NULL);
    $build = [
      '#type' => 'inline_template',
      '#template' => self::HTML,
      '#context' => [
        'import_map' => $this->renderer->renderInIsolation($import_map_tag),
        'script_url' => Url::fromRoute('canvas.code_editor_preview.script')->toString(),
      ],
    ];
    $response = new Response((string) $this->renderer->renderInIsolation($build));
    $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
    // Harmless: this document contains no secrets, only fixed asset URLs.
    // Needed for the `@/components/` entries added by the import map, which
    // an opaque-origin `import()` from inside this document will fetch.
    $response->headers->set('Access-Control-Allow-Origin', '*');
    return $response;
  }

  /**
   * Serves `ui/dist/assets/code-editor-preview.js` with a CORS header.
   *
   * The shell above loads this via `<script type="module" src="...">`.
   * Module scripts are always CORS-mode fetches, and the shell's opaque
   * origin sends `Origin: null`, so the response needs
   * `Access-Control-Allow-Origin: *` or the fetch is blocked.
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
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Cache-Control', 'no-cache');
    return $response;
  }

  /**
   * Builds the import map for the preview shell.
   *
   * Same as the base import map used by the main Canvas app
   * (@see \Drupal\canvas\GlobalImports::getImportMap()), plus the
   * `@/components/` entry the preview needs to import other auto-saved code
   * components. This mirrors what `Preview.tsx` currently builds
   * client-side by copying the parent document's import map.
   *
   * Every `packages/astro-hydration/dist/` entry is rewritten to route
   * through `asset()` instead of its plain static file URL: the shell's
   * opaque origin needs `Access-Control-Allow-Origin` on every module it
   * imports (not just its own entry script), and that directory is served
   * directly by the web server, which adds no such header.
   *
   * @return array{imports: array<string, string>, scopes: array<string, array<string, string>>}
   */
  private function buildImportMap(): array {
    $import_map = $this->globalImports->getImportMap();
    $import_map[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]['@/components/'] = \base_path() . 'canvas/api/v0/auto-saves/js/js_component/';

    $astro_hydration_path = \sprintf('%s%s/packages/astro-hydration/dist/', \base_path(), $this->extensionPathResolver->getPath('module', 'canvas'));
    foreach ($import_map[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS] as $specifier => $url) {
      if ($specifier === '@/components/' || !\str_starts_with($url, $astro_hydration_path)) {
        continue;
      }
      [$filename, $query] = \array_pad(\explode('?', \substr($url, \strlen($astro_hydration_path)), 2), 2, NULL);
      $import_map[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS][$specifier] = Url::fromRoute('canvas.code_editor_preview.asset', ['filename' => $filename])->toString()
        . ($query !== NULL ? '?' . $query : '');
    }
    return $import_map;
  }

  /**
   * Serves `packages/astro-hydration/dist/{filename}` with a CORS header.
   *
   * The import map built by `buildImportMap()` points every
   * `packages/astro-hydration/dist/` entry (`preact`, `react`, `clsx`, etc.)
   * here instead of at its plain static file URL, for the same reason as
   * `script()` above.
   */
  public function asset(string $filename): BinaryFileResponse {
    if (\preg_match('/^[A-Za-z0-9_.-]+\.m?js$/', $filename) !== 1) {
      throw new NotFoundHttpException();
    }
    $file_path = \sprintf(
      '%s/%s/packages/astro-hydration/dist/%s',
      DRUPAL_ROOT,
      $this->extensionPathResolver->getPath('module', 'canvas'),
      $filename,
    );
    if (!\is_file($file_path)) {
      throw new NotFoundHttpException();
    }
    $response = new BinaryFileResponse($file_path);
    $response->headers->set('Content-Type', 'text/javascript');
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Cache-Control', 'no-cache');
    return $response;
  }

}
