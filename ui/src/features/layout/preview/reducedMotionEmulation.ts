/**
 * Injects emulation of `prefers-reduced-motion: reduce` into preview iframe HTML.
 * Browsers do not expose a way for a parent to set this for a child document, so
 * we transform the `srcdoc` string before each assignment.
 */

export const CANVAS_REDUCED_MOTION_SCRIPT_MARKER =
  'data-canvas-reduced-motion="script"';
export const CANVAS_REDUCED_MOTION_STYLE_MARKER =
  'data-canvas-reduced-motion="style"';
export const CANVAS_REDUCED_MOTION_LATE_STYLE_MARKER =
  'data-canvas-reduced-motion="late-style"';

const REDUCED_MOTION_SELECTOR =
  '*:not(#canvas-reduced-motion-force-a)' +
  ':not(#canvas-reduced-motion-force-b)' +
  ':not(#canvas-reduced-motion-force-c)' +
  ':not(#canvas-reduced-motion-force-d)' +
  ':not(#canvas-reduced-motion-force-e)' +
  ':not(#canvas-reduced-motion-force-f)';

const REDUCED_MOTION_CSS = `${REDUCED_MOTION_SELECTOR},
${REDUCED_MOTION_SELECTOR}::before,
${REDUCED_MOTION_SELECTOR}::after {
  animation: none !important;
  transition: none !important;
  transition-property: none !important;
  scroll-behavior: auto !important;
  view-transition-name: none !important;
}

html:focus-within {
  scroll-behavior: auto !important;
}`;

const REDUCED_MOTION_SCRIPT = `<script ${CANVAS_REDUCED_MOTION_SCRIPT_MARKER}>
(function () {
  var doc = document;
  var previewDocAttr = 'data-canvas-preview-reduced-motion';
  var reducedMotionCss = ${JSON.stringify(REDUCED_MOTION_CSS)};
  var reducedMotionStyleId = 'canvas-reduced-motion-runtime-style';

  function docUsesCanvasReducedMotion(d) {
    return !!(
      d &&
      d.documentElement &&
      d.documentElement.hasAttribute(previewDocAttr)
    );
  }

  function installReducedMotionStyle(root) {
    if (!root) {
      return;
    }
    var existing =
      root.querySelector && root.querySelector('#' + reducedMotionStyleId);
    if (existing) {
      if (existing.parentNode && existing.parentNode.lastChild !== existing) {
        existing.parentNode.appendChild(existing);
      }
      return;
    }

    var owner = root.ownerDocument || doc;
    var style = owner.createElement('style');
    style.id = reducedMotionStyleId;
    style.setAttribute('data-canvas-reduced-motion', 'runtime-style');
    style.textContent = reducedMotionCss;
    root.appendChild(style);
  }

  function forceStyle(style, property, value) {
    if (
      style.getPropertyValue(property) !== value ||
      style.getPropertyPriority(property) !== 'important'
    ) {
      style.setProperty(property, value, 'important');
    }
  }

  function suppressInlineMotion(element, suppressOpacity) {
    if (!element || element.nodeType !== 1 || !element.style) {
      return;
    }
    forceStyle(element.style, 'animation', 'none');
    forceStyle(element.style, 'transition', 'none');
    forceStyle(element.style, 'transition-property', 'none');
    forceStyle(element.style, 'transform', 'none');
    forceStyle(element.style, 'translate', 'none');
    forceStyle(element.style, 'rotate', 'none');
    forceStyle(element.style, 'scale', 'none');
    forceStyle(element.style, 'scroll-behavior', 'auto');
    forceStyle(element.style, 'view-transition-name', 'none');
    if (suppressOpacity && element.style.getPropertyValue('opacity')) {
      forceStyle(element.style, 'opacity', '1');
    }
  }

  function suppressInlineMotionTree(root, suppressOpacity) {
    if (!root) {
      return;
    }
    suppressInlineMotion(root, suppressOpacity);
    if (root.querySelectorAll) {
      root.querySelectorAll('[style]').forEach(function (element) {
        suppressInlineMotion(element, suppressOpacity);
      });
    }
  }

  installReducedMotionStyle(doc.head || doc.documentElement);

  if (doc.documentElement && window.frameElement) {
    doc.documentElement.setAttribute(previewDocAttr, '');
  }

  if (typeof Element !== 'undefined' && Element.prototype.attachShadow) {
    if (!Element.prototype.attachShadow.__canvasRMWrapped) {
      var nativeAttachShadow = Element.prototype.attachShadow;
      Element.prototype.attachShadow = function (init) {
        var shadowRoot = nativeAttachShadow.call(this, init);
        if (docUsesCanvasReducedMotion(this.ownerDocument)) {
          installReducedMotionStyle(shadowRoot);
        }
        return shadowRoot;
      };
      Element.prototype.attachShadow.__canvasRMWrapped = true;
    }
  }

  if (typeof Element !== 'undefined' && Element.prototype.animate) {
    if (!Element.prototype.animate.__canvasRMWrapped) {
      var nativeAnimate = Element.prototype.animate;
      Element.prototype.animate = function (keyframes, options) {
        if (!docUsesCanvasReducedMotion(this.ownerDocument)) {
          return nativeAnimate.apply(this, arguments);
        }
        var reducedOptions =
          typeof options === 'number' ? { duration: 0 } : Object.assign({}, options);
        reducedOptions.duration = 0;
        reducedOptions.delay = 0;
        reducedOptions.iterations = 1;
        return nativeAnimate.call(this, keyframes, reducedOptions);
      };
      Element.prototype.animate.__canvasRMWrapped = true;
    }
  }

  if (
    typeof window.matchMedia === 'function' &&
    window.frameElement &&
    !window.matchMedia.__canvasRMWrapped
  ) {
    var nativeMatchMedia = window.matchMedia.bind(window);
    window.matchMedia = function (query) {
      if (!docUsesCanvasReducedMotion(window.document)) {
        return nativeMatchMedia(query);
      }
      var q = String(query);
      if (/prefers-reduced-motion\\s*:\\s*no-preference\\b/i.test(q)) {
        return syntheticMediaQueryList(q, false);
      }
      if (/prefers-reduced-motion\\s*:\\s*reduce\\b/i.test(q)) {
        return syntheticMediaQueryList(q, true);
      }
      if (/\\(\\s*prefers-reduced-motion\\s*\\)/i.test(q)) {
        return syntheticMediaQueryList(q, true);
      }
      return nativeMatchMedia(query);
    };
    window.matchMedia.__canvasRMWrapped = true;
  }

  function syntheticMediaQueryList(media, matches) {
    return {
      media: media,
      matches: matches,
      onchange: null,
      addListener: function () {},
      removeListener: function () {},
      addEventListener: function () {},
      removeEventListener: function () {},
      dispatchEvent: function () {
        return false;
      },
    };
  }

  // Observe only after the initial parse so static inline layout is not scrubbed.
  function startMotionScrubObserver() {
    var MO =
      (doc.defaultView && doc.defaultView.MutationObserver) || MutationObserver;
    if (typeof MO === 'undefined') {
      return;
    }
    var observer = new MO(function (mutations) {
      installReducedMotionStyle(doc.head || doc.documentElement);
      mutations.forEach(function (mutation) {
        if (mutation.type === 'attributes') {
          suppressInlineMotion(mutation.target, true);
          return;
        }
        mutation.addedNodes.forEach(function (node) {
          suppressInlineMotionTree(node, true);
        });
      });
    });
    observer.observe(doc.documentElement, {
      attributes: true,
      attributeFilter: ['style'],
      childList: true,
      subtree: true,
    });
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', function () {
      installReducedMotionStyle(doc.head || doc.documentElement);
      startMotionScrubObserver();
    });
  } else {
    installReducedMotionStyle(doc.head || doc.documentElement);
    startMotionScrubObserver();
  }
})();
</script>`;

const REDUCED_MOTION_STYLE = `<style ${CANVAS_REDUCED_MOTION_STYLE_MARKER}>${REDUCED_MOTION_CSS}</style>`;

const REDUCED_MOTION_LATE_STYLE = `<style ${CANVAS_REDUCED_MOTION_LATE_STYLE_MARKER}>${REDUCED_MOTION_CSS}</style>`;

function insertAfterFirstMatch(
  html: string,
  pattern: RegExp,
  insertion: string,
): string | null {
  const match = pattern.exec(html);
  if (!match || match.index === undefined) {
    return null;
  }
  const end = match.index + match[0].length;
  return html.slice(0, end) + insertion + html.slice(end);
}

function insertBeforeFirstClosingHead(html: string, insertion: string): string {
  const re = /<\/head>/i;
  const found = re.exec(html);
  if (!found || found.index === undefined) {
    return html + insertion;
  }
  const i = found.index;
  return html.slice(0, i) + insertion + html.slice(i);
}

/**
 * Returns preview HTML with reduced-motion emulation injected once.
 * Calling again on already-injected HTML is a no-op.
 */
export function injectReducedMotionEmulation(html: string): string {
  if (!html || html.includes(CANVAS_REDUCED_MOTION_SCRIPT_MARKER)) {
    return html;
  }

  const headOpen = /<head\b[^>]*>/i;
  if (headOpen.test(html)) {
    const withEarlyEmulation = insertAfterFirstMatch(
      html,
      headOpen,
      REDUCED_MOTION_SCRIPT + REDUCED_MOTION_STYLE,
    );
    if (withEarlyEmulation) {
      return insertBeforeFirstClosingHead(
        withEarlyEmulation,
        REDUCED_MOTION_LATE_STYLE,
      );
    }
  }

  const htmlOpen = /<html\b[^>]*>/i;
  if (htmlOpen.test(html)) {
    const withBlock = insertAfterFirstMatch(
      html,
      htmlOpen,
      REDUCED_MOTION_SCRIPT + REDUCED_MOTION_STYLE + REDUCED_MOTION_LATE_STYLE,
    );
    if (withBlock) {
      return withBlock;
    }
  }

  return (
    REDUCED_MOTION_SCRIPT +
    REDUCED_MOTION_STYLE +
    REDUCED_MOTION_LATE_STYLE +
    html
  );
}
