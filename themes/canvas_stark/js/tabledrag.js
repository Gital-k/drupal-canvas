((Drupal, once) => {
  Drupal.behaviors.canvasTabledrag = {
    attach(context) {
      once('canvas-tabledrag', 'body', context).forEach((body) => {
        // When a tabledrag input changes, immediately set an attribute that
        // indicates the preview is updating, which prevents AJAX operations
        // from occurring until the preview is updated. This is a supplement to
        // guardrails that already exist in the React UI app. This addition is
        // to cover rare-but-can-occur scenarios where something is typed in
        // the tabledrag widget and "Add another item" is clicked before the
        // preview-triggering debounce on the input had completed.
        body.addEventListener('input', (e) => {
          if (
            e.target &&
            e.target.tagName === 'INPUT' &&
            e.target.closest('[data-canvas-tabledrag="true"]')
          ) {
            body.setAttribute('data-canvas-layout-request-in-progress', 'true');
          }
        });
      });
    },
  };
})(Drupal, once);
