/**
 * Computes an iframe `sandbox` attribute value for a given `src` URL.
 *
 * `allow-same-origin` is only appended when `url` resolves to the same
 * origin as the parent document. Granting it to a cross-origin (or
 * unparseable) URL would be pointless for the browser's own origin checks,
 * but granting it unconditionally — as before — let any iframe, including a
 * genuinely same-origin one, escape its sandbox entirely.
 *
 * @see https://www.drupal.org/project/canvas/issues/3527515
 */
export function getIframeSandbox(
  url: string | undefined | null,
  baseTokens: string,
): string {
  if (!url) {
    return baseTokens;
  }
  try {
    const isSameOrigin =
      new URL(url, window.location.href).origin === window.location.origin;
    return isSameOrigin ? `${baseTokens} allow-same-origin` : baseTokens;
  } catch {
    return baseTokens;
  }
}
