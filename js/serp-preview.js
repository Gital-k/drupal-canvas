(function (Drupal) {
  Drupal.behaviors.seoPreview = {
    attach: function (context, settings) {
      // Get site name and base URL from Drupal settings
      const siteName = settings.canvas.siteName || '';
      const baseUrl = settings.canvas.baseUrl || '';

      // Select fields and preview elements
      const titleField = context.querySelector('input[name="title[0][value]"]');
      const aliasField = context.querySelector('input[name="path[0][alias]"]');
      const descriptionField = context.querySelector('textarea[name="description[0][value]"]');

      const previewTitle = context.querySelector('.serp-preview__title');
      const previewUrl = context.querySelector('.serp-preview__url');
      const previewDescription = context.querySelector('.serp-preview__description');

      if (titleField && previewTitle) {
        titleField.addEventListener('input', () => {
          const title = titleField.value.trim();
          previewTitle.textContent = title ? `${title} | ${siteName}` : siteName;
        });
      }

      if (aliasField && previewUrl) {
        aliasField.addEventListener('input', () => {
          const alias = aliasField.value.trim();
          const formattedAlias = alias.startsWith('/') ? alias : `/${alias}`;
          previewUrl.textContent = `${baseUrl}${formattedAlias}`;
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