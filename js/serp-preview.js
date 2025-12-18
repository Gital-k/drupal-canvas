(function (Drupal) {
  Drupal.behaviors.seoPreview = {
    attach: function (context, settings) {
      // Only process if we haven't already attached to these elements
      const wrapper = context.querySelector('.serp-preview-wrapper');
      if (!wrapper || wrapper.dataset.seoPreviewAttached) {
        return;
      }
      wrapper.dataset.seoPreviewAttached = 'true';

      // Get site name and base URL from Drupal settings
      const siteName = settings.canvas?.siteName || '';
      const baseUrl = settings.canvas?.baseUrl || '';

      // Select fields and preview elements
      const titleField = context.querySelector('input[name="title[0][value]"]');
      const aliasField = context.querySelector('input[name="path[0][alias]"]');
      const descriptionField = context.querySelector('textarea[name="description[0][value]"]');

      const previewTitle = context.querySelector('.serp-preview__title');
      const previewUrl = context.querySelector('.serp-preview__url');
      const previewDescription = context.querySelector('.serp-preview__description');

      // Update preview URL helper function
      const updatePreviewUrl = (field) => {
        if (field && previewUrl) {
          const alias = field.value.trim();
          if (alias) {
            const formattedAlias = alias.startsWith('/') ? alias : `/${alias}`;
            previewUrl.textContent = `${baseUrl}${formattedAlias}`;
          } else {
            // Fallback to default URL if alias is empty
            previewUrl.textContent = baseUrl;
          }
        }
      };

      if (titleField && previewTitle) {
        titleField.addEventListener('input', () => {
          const title = titleField.value.trim();
          previewTitle.textContent = title ? `${title} | ${siteName}` : siteName;
        });
      }

      if (aliasField && previewUrl) {
        aliasField.addEventListener('input', () => {
          updatePreviewUrl(aliasField);
        });

        // Also listen for change event (for programmatic updates)
        aliasField.addEventListener('change', () => {
          updatePreviewUrl(aliasField);
        });
      }

      if (descriptionField && previewDescription) {
        descriptionField.addEventListener('input', () => {
          previewDescription.textContent = descriptionField.value.trim();
        });
      }
    }
  };
})(Drupal);