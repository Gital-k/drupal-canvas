import { useCallback, useLayoutEffect, useRef } from 'react';

const CANVAS_PREVIEW_MAX_HEIGHT = 'data-canvas-preview-max-height';
const PREVIEW_IFRAME_SELECTOR = 'iframe[data-canvas-swap-active="true"]';
const VH_MULTIPLIERS = [1, 3, 8];

/**
 * Returns the best-effort content height of an iframe document for sizing the
 * parent preview container. Prefers scrollHeight so growth after the iframe
 * viewport is pinned (height: 100%) is still measured.
 */
export function getPreviewContentHeight(
  documentElement: HTMLElement,
  body: HTMLElement,
): number {
  return Math.max(
    documentElement.scrollHeight,
    documentElement.offsetHeight,
    body.scrollHeight,
    body.offsetHeight,
  );
}

/**
 * Detects block-level boxes whose height tracks the iframe size (e.g. vh) and
 * sets max-height and min-height to break feedback loops. Only processes
 * `candidates` that are still connected and not already tagged.
 */
function applyVhMaxHeight(
  iframe: HTMLIFrameElement,
  minHeight: number,
  candidates: Iterable<HTMLElement>,
  activePreviewIframe: HTMLIFrameElement | null,
): void {
  if (!activePreviewIframe) {
    return;
  }
  const filtered: HTMLElement[] = [];
  for (const el of candidates) {
    if (
      el.isConnected &&
      !el.hasAttribute(CANVAS_PREVIEW_MAX_HEIGHT) &&
      !['HTML', 'BODY'].includes(el.tagName)
    ) {
      filtered.push(el);
    }
  }
  if (filtered.length === 0) {
    return;
  }

  const heightRatios = new WeakMap<HTMLElement, number[]>();
  const savedHeight = iframe.style.height;
  const savedOverflow = iframe.style.overflow;

  VH_MULTIPLIERS.forEach((multi) => {
    iframe.style.height = minHeight * multi + 'px';
    iframe.style.overflow = 'visible';
    // Force a synchronous parent-frame layout flush so the iframe's viewport
    // height propagates before reading cross-frame clientHeight.
    void iframe.offsetHeight;
    filtered.forEach((element) => {
      const ratios: number[] = heightRatios.get(element) || [];
      if (element.clientHeight > 10) {
        ratios.push(Math.floor(element.clientHeight / multi));
        heightRatios.set(element, ratios);
      }
    });
  });
  iframe.style.height = savedHeight;
  iframe.style.overflow = savedOverflow;

  filtered.forEach((element) => {
    const ratios: number[] = heightRatios.get(element) || [];
    if (
      ratios.length === VH_MULTIPLIERS.length &&
      ratios.every((ratio) => ratio === ratios[0])
    ) {
      const maxHeight = ratios[0];
      if (maxHeight) {
        const pixelHeight = `${maxHeight}px`;
        element.style.maxHeight = pixelHeight;
        element.style.minHeight = pixelHeight;
      } else {
        element.style.maxHeight = '';
        element.style.minHeight = '';
      }
      element.setAttribute(CANVAS_PREVIEW_MAX_HEIGHT, `${maxHeight}`);
    }
  });
}

/**
 * This hook takes preview iFrame and ensures that the height of the iFrame html element matches the height of the
 * content being rendered in the iFrame. It uses a mutation observer to keep it in sync
 */
function useSyncIframeHeightToContent(
  iframe: HTMLIFrameElement | null,
  previewContainer: HTMLDivElement | null,
  height: number,
) {
  const mutationObserverRef = useRef<MutationObserver | null>(null);
  const resizeObserverRef = useRef<ResizeObserver | null>(null);
  const pendingVhElementsRef = useRef<Set<HTMLElement>>(new Set());
  const vhDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const iframeContentDocForLoadRef = useRef<Document | null>(null);

  const resizeIframe = useCallback(() => {
    if (iframe && iframe.contentDocument) {
      const iframeHTML = iframe.contentDocument.documentElement;
      const iframeBody = iframe.contentDocument.body;
      window.requestAnimationFrame(() => {
        if (previewContainer?.style) {
          const contentHeight = getPreviewContentHeight(iframeHTML, iframeBody);
          if (contentHeight) {
            previewContainer.style.height = `${contentHeight}px`;
          }
        }
        if (iframeHTML?.style) {
          iframeHTML.style.minHeight = height + 'px';
        }
        if (iframeBody?.style) {
          iframeBody.style.minHeight = height + 'px';
        }
      });
    }
  }, [iframe, height, previewContainer]);

  useLayoutEffect(() => {
    if (iframe) {
      const pendingVhElementsForCleanup = pendingVhElementsRef;
      const onMutations: MutationCallback = (mutations) => {
        const addedForVh: HTMLElement[] = [];
        for (const m of mutations) {
          if (m.type === 'childList') {
            m.addedNodes.forEach((node) => {
              if (node.nodeType === Node.ELEMENT_NODE) {
                const el = node as HTMLElement;
                if (!el.hasAttribute(CANVAS_PREVIEW_MAX_HEIGHT)) {
                  addedForVh.push(el);
                  el.querySelectorAll('*').forEach((child) => {
                    if (
                      child instanceof HTMLElement &&
                      !child.hasAttribute(CANVAS_PREVIEW_MAX_HEIGHT)
                    ) {
                      addedForVh.push(child);
                    }
                  });
                }
              }
            });
          }
        }
        resizeIframe();
        if (addedForVh.length > 0) {
          addedForVh.forEach((el) => {
            if (el.isConnected) {
              pendingVhElementsRef.current.add(el);
            }
          });
          if (vhDebounceRef.current !== null) {
            clearTimeout(vhDebounceRef.current);
          }
          vhDebounceRef.current = setTimeout(() => {
            vhDebounceRef.current = null;
            const batch = Array.from(pendingVhElementsRef.current);
            pendingVhElementsRef.current.clear();
            if (batch.length === 0) {
              return;
            }
            const previewIframe = document.querySelector(
              PREVIEW_IFRAME_SELECTOR,
            ) as HTMLIFrameElement | null;
            applyVhMaxHeight(iframe, height, batch, previewIframe);
            resizeIframe();
          }, 0);
        }
      };

      const handleLoad = () => {
        const iframeContentDoc = iframe.contentDocument;

        if (iframeContentDoc) {
          const iframeHTML = iframeContentDoc.documentElement;
          const iframeBody = iframeContentDoc.body;

          iframeContentDocForLoadRef.current?.removeEventListener(
            'load',
            resizeIframe,
            { capture: true },
          );
          mutationObserverRef.current?.disconnect();
          resizeObserverRef.current?.disconnect();

          iframeContentDocForLoadRef.current = iframeContentDoc;
          iframeContentDoc.addEventListener('load', resizeIframe, {
            capture: true,
          });

          // Leave iframe.style.height unset so the CSS rule (height: 100%)
          // keeps the iframe element in sync with the previewContainer as it
          // grows. Any inline height here would clip the iframe to that size.
          iframeHTML.style.overflow = 'hidden';
          // Set up a MutationObserver to watch for changes in the content of the iframe
          mutationObserverRef.current = new MutationObserver(onMutations);
          mutationObserverRef.current.observe(iframeHTML, {
            attributes: true,
            childList: true,
            subtree: true,
          });
          resizeObserverRef.current = new ResizeObserver(resizeIframe);
          resizeObserverRef.current.observe(iframeHTML);
          if (iframeBody) {
            resizeObserverRef.current.observe(iframeBody);
          }

          // Apply a max-height to elements with vh units in their height - otherwise an infinite loop can occur where a component's
          // height is based on the height of the iFrame and the iFrame's height is based on that component leading
          // to an ever-increasing iFrame height!
          const allElements: HTMLElement[] = Array.from(
            iframeHTML.querySelectorAll('*'),
          ) as HTMLElement[];

          const previewIframe = document.querySelector(
            PREVIEW_IFRAME_SELECTOR,
          ) as HTMLIFrameElement | null;
          applyVhMaxHeight(iframe, height, allElements, previewIframe);

          resizeIframe();
        }
      };

      // Assign the load event listener
      iframe.addEventListener('load', handleLoad);

      // Check if the iFrame is already loaded
      if (iframe.contentDocument?.readyState === 'complete') {
        handleLoad();
      }

      return () => {
        iframe.removeEventListener('load', handleLoad);
        iframeContentDocForLoadRef.current?.removeEventListener(
          'load',
          resizeIframe,
          { capture: true },
        );
        iframeContentDocForLoadRef.current = null;
        mutationObserverRef.current?.disconnect();
        resizeObserverRef.current?.disconnect();
        if (vhDebounceRef.current !== null) {
          clearTimeout(vhDebounceRef.current);
          vhDebounceRef.current = null;
        }
        pendingVhElementsForCleanup.current.clear();
      };
    }
  }, [iframe, height, resizeIframe]);
}

export default useSyncIframeHeightToContent;
