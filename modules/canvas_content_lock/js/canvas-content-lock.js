/**
 * @file
 * Warns Canvas editors when another user is editing the same page.
 *
 * The Canvas UI is a single-page app, so this follows client-side navigation:
 * - opening /canvas/editor/canvas_page/{id} acquires the lock on that page;
 * - the lock is refreshed every `pollInterval` seconds while the page is open;
 * - leaving that URL, or closing the browser tab, releases the lock.
 * When another user holds the lock, a modal dialog names them and offers to
 * go back, or to take over editing for users allowed to break locks.
 *
 * @see \Drupal\canvas_content_lock\ContentLockManager
 * @see \Drupal\canvas_content_lock\EventSubscriber\ContentLockAssetsSubscriber
 */
((Drupal, drupalSettings) => {
  const settings = drupalSettings.canvasContentLock;
  if (!settings || Drupal.canvasContentLock) {
    return;
  }

  const baseUrl = drupalSettings.path.baseUrl;
  const editorPathPattern = new RegExp(
    `^${baseUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}canvas/editor/${settings.entityType}/([^/]+)`,
  );
  const locationChangeEvent = 'canvasContentLock:locationChange';

  let csrfToken = null;
  let currentPageId = null;
  let heldPageId = null;
  let timer = null;
  let dialog = null;

  /**
   * Gets the ID of the page open in the Canvas editor, if any.
   */
  const getPageIdFromLocation = () => {
    const match = window.location.pathname.match(editorPathPattern);
    return match ? decodeURIComponent(match[1]) : null;
  };

  const getCsrfToken = async () => {
    if (csrfToken === null) {
      const response = await fetch(`${baseUrl}session/token`, {
        credentials: 'same-origin',
      });
      csrfToken = await response.text();
    }
    return csrfToken;
  };

  const buildUrl = (pageId, operation) =>
    `${baseUrl}canvas/api/v0/content-lock/${encodeURIComponent(pageId)}/${operation}`;

  /**
   * Calls a content lock endpoint.
   *
   * @return {Promise<{status: number, body: object|null}>}
   *   The response status and decoded lock status.
   */
  const request = async (pageId, operation) => {
    const options = {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    };
    if (operation !== 'check') {
      options.method = 'POST';
      options.headers['X-CSRF-Token'] = await getCsrfToken();
    }
    const response = await fetch(buildUrl(pageId, operation), options);
    let body = null;
    try {
      body = await response.json();
    } catch {
      // Error responses may not be JSON.
    }
    return { status: response.status, body };
  };

  /**
   * Releases a lock while the browser tab is closing.
   *
   * Uses a keepalive request, which may outlive the document. This must not
   * await anything: the CSRF token is only used if it was already fetched.
   */
  const releaseOnUnload = (pageId) => {
    if (csrfToken === null) {
      return;
    }
    fetch(buildUrl(pageId, 'release'), {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
    });
  };

  const closeDialog = () => {
    if (dialog) {
      dialog.remove();
      dialog = null;
    }
  };

  const goBack = () => {
    closeDialog();
    if (window.history.length > 1) {
      window.history.back();
    } else {
      window.location.assign(`${baseUrl}canvas`);
    }
  };

  const createButton = (label, className, onClick) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `canvas-content-lock__button ${className}`;
    button.textContent = label;
    button.addEventListener('click', onClick);
    return button;
  };

  /**
   * Shows who holds the lock on the current page.
   */
  const showDialog = (status) => {
    const ownerName = status?.owner?.name ?? Drupal.t('Another user');

    if (!dialog) {
      dialog = document.createElement('div');
      dialog.className = 'canvas-content-lock';
      dialog.innerHTML = `
        <div class="canvas-content-lock__dialog" role="alertdialog" aria-modal="true"
             aria-labelledby="canvas-content-lock-title" aria-describedby="canvas-content-lock-message">
          <h2 class="canvas-content-lock__title" id="canvas-content-lock-title"></h2>
          <p class="canvas-content-lock__message" id="canvas-content-lock-message"></p>
          <div class="canvas-content-lock__actions"></div>
        </div>`;
      dialog.querySelector('.canvas-content-lock__title').textContent =
        Drupal.t('This page is being edited');

      const actions = dialog.querySelector('.canvas-content-lock__actions');
      actions.append(
        createButton(
          Drupal.t('Go back'),
          'canvas-content-lock__button--primary',
          goBack,
        ),
      );
      if (settings.canBreak) {
        actions.append(
          createButton(
            Drupal.t('Take over editing'),
            'canvas-content-lock__button--secondary',
             
            takeOver,
          ),
        );
      }
      document.body.append(dialog);
      dialog.querySelector('button').focus();
    }

    // Updated on every refresh: the lock may change hands while it is shown.
    dialog.querySelector('.canvas-content-lock__message').textContent =
      Drupal.t(
        '@name is currently editing this page. Changes made at the same time may conflict and fail to save.',
        { '@name': ownerName },
      );
  };

  /**
   * Handles a lock status response for the current page.
   */
  const handleResponse = (pageId, { status, body }) => {
    // The user navigated elsewhere while the request was in flight.
    if (pageId !== currentPageId) {
      return;
    }
    if (status === 200 && body?.isOwner) {
      heldPageId = pageId;
      closeDialog();
    } else if (status === 200 && body?.locked) {
      heldPageId = null;
      showDialog(body);
    }
    // Other responses (for example 403 or 404) are left to the Canvas UI.
  };

  const refresh = async () => {
    const pageId = currentPageId;
    if (pageId === null) {
      return;
    }
    try {
      handleResponse(pageId, await request(pageId, 'acquire'));
    } catch {
      // Network errors are retried on the next refresh.
    }
  };

  const takeOver = async () => {
    const pageId = currentPageId;
    if (pageId === null) {
      return;
    }
    try {
      handleResponse(pageId, await request(pageId, 'break'));
    } catch {
      // The dialog stays open; the user can try again.
    }
  };

  const stopTimer = () => {
    if (timer !== null) {
      window.clearInterval(timer);
      timer = null;
    }
  };

  /**
   * Acquires the lock for the page in the URL, and releases the previous one.
   */
  const onLocationChange = () => {
    const pageId = getPageIdFromLocation();
    if (pageId === currentPageId) {
      return;
    }

    if (heldPageId !== null && heldPageId !== pageId) {
      request(heldPageId, 'release').catch(() => {});
      heldPageId = null;
    }
    stopTimer();
    closeDialog();
    currentPageId = pageId;

    if (pageId !== null) {
      refresh();
      timer = window.setInterval(refresh, settings.pollInterval * 1000);
    }
  };

  // React Router navigates with the History API, which fires no event for
  // pushState() or replaceState().
  ['pushState', 'replaceState'].forEach((method) => {
    const original = window.history[method];
    window.history[method] = function wrappedHistoryMethod(...args) {
      const result = original.apply(this, args);
      window.dispatchEvent(new Event(locationChangeEvent));
      return result;
    };
  });
  window.addEventListener('popstate', onLocationChange);
  window.addEventListener(locationChangeEvent, onLocationChange);

  window.addEventListener('pagehide', () => {
    if (heldPageId !== null) {
      releaseOnUnload(heldPageId);
      heldPageId = null;
    }
  });
  window.addEventListener('pageshow', (event) => {
    // Restored from the back/forward cache after the lock was released.
    if (event.persisted && currentPageId !== null) {
      refresh();
    }
  });
  document.addEventListener('visibilitychange', () => {
    // Browsers throttle timers in hidden tabs.
    if (document.visibilityState === 'visible') {
      refresh();
    }
  });

  Drupal.canvasContentLock = { refresh };
  // This script loads in <head>, so the dialog cannot be added before <body>
  // exists.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onLocationChange, {
      once: true,
    });
  } else {
    onLocationChange();
  }
})(Drupal, drupalSettings);
