interface Linkset {
  linkset: LinksetItem[];
}

interface LinksetItem {
  anchor: string;
  item: LinksetMenuItem[];
}

interface LinksetMenuItem {
  href: string;
  hierarchy: string[];
  _children?: LinksetMenuItem[];
  _hasSubmenu: boolean;
}

/**
 * Sort menu items from core's menu API into a tree with additional
 * _children and _hasSubmenu properties.
 *
 * @param linkset
 *
 * @return menuItems
 */
export function sortMenu(linkset: Linkset) {
  const menuItemsMap = new Map();
  const menu: LinksetMenuItem[] = [];

  if (!linkset.linkset?.length) {
    return [];
  }

  linkset.linkset[0].item.forEach((item) => {
    const hierarchyKey = item.hierarchy.join('|');
    menuItemsMap.set(hierarchyKey, {
      ...item,
      id: hierarchyKey,
      _children: [],
      _hasSubmenu: false,
    });
  });

  linkset.linkset[0].item.forEach((item) => {
    const hierarchyKey = item.hierarchy.join('|');
    const currentItem = menuItemsMap.get(hierarchyKey);

    if (item.hierarchy.length === 1) {
      // Root level item.
      menu.push(currentItem);
    } else {
      // Child item.
      const parentHierarchy = item.hierarchy.slice(0, -1);
      const parentKey = parentHierarchy.join('|');
      const parent = menuItemsMap.get(parentKey);
      if (parent) {
        parent._children.push(currentItem);
        parent._hasSubmenu = true;
      }
    }
  });

  return menu;
}

interface BreadcrumbLink {
  key: string;
  text: string;
  url: string;
}

interface EntityMetadata {
  bundle: string;
  entityTypeId: string;
  uuid: string;
  // The language requested via the URL (the negotiated content language).
  requestedLanguage: string;
  // The language actually rendered. This can differ from `requestedLanguage` as
  // the default translation is used when the requested language is unavailable.
  renderedLanguage: string;
  /**
   * All enabled site languages for building a language switcher.
   *
   * Includes default and untranslated languages
   * (`translationAvailable: false`). Untranslated entries still include a URL
   * that resolves to the fallback translation. Empty when the entity type does
   * not support translation or the site is monolingual.
   */
  translations: TranslationMetadata[];
}

interface TranslationMetadata {
  langcode: string;
  // The language name in the current display language (e.g. "German").
  name: string;
  // The language's own native name (e.g. "Deutsch").
  nativeName: string;
  url: string;
  // Whether the entity has a translation in this language that the current
  // user may view.
  translationAvailable: boolean;
  // Whether this is the requested language (the URL/content language), even
  // when its content falls back to the default translation.
  current: boolean;
}

interface PageData {
  pageTitle: string;
  breadcrumbs: Array<BreadcrumbLink>;
  mainEntity: EntityMetadata | null;
}

interface ThemeAssets {
  logo: { url: string };
  favicon: { url: string; mimeType: string };
}

interface SiteData {
  branding: {
    homeUrl: string;
    siteName: string;
    siteSlogan: string;
  };
  baseUrl: string;
  themeAssets: ThemeAssets;
}

export const getPageData = (): PageData => {
  const pageData = {
    pageTitle: globalThis?.drupalSettings?.canvasData?.v0?.pageTitle || '',
    breadcrumbs: globalThis?.drupalSettings?.canvasData?.v0?.breadcrumbs || [],
    mainEntity: globalThis?.drupalSettings?.canvasData?.v0?.mainEntity || null,
  };
  globalThis?.window?.parent.postMessage({
    type: '_canvas_useswr_data_fetch',
    id: 'getPageData()',
    data: pageData,
  });
  return pageData;
};

export const getSiteData = (): SiteData => {
  const siteData = {
    branding: globalThis?.drupalSettings?.canvasData?.v0?.branding || {
      homeUrl: '',
      siteName: '',
      siteSlogan: '',
    },
    baseUrl: globalThis?.drupalSettings?.canvasData?.v0?.baseUrl || '/',
    themeAssets: globalThis?.drupalSettings?.canvasData?.v0?.themeAssets || {
      logo: { url: '' },
      favicon: { url: '', mimeType: '' },
    },
  };
  globalThis?.window?.parent.postMessage({
    type: '_canvas_useswr_data_fetch',
    id: 'getSiteData()',
    data: siteData,
  });
  return siteData;
};

/**
 * Returns whether the current document is the Canvas editor preview (layout
 * iframe). Use this in code components to adjust behavior or styling when
 * rendered inside the editor.
 *
 * @returns true when running in an iframe with a parent window on a Canvas
 *   route (but not /canvas/preview/), false on the live site, in the editor
 *   shell, or when running in an iframe with a parent window on the
 *   /canvas/preview/ route.
 */
export const isCanvasPreview = (): boolean => {
  // If we're not in an iframe, we're not in the Canvas editor preview.
  if (window.self === window.top) {
    return false;
  }

  // Check if the parent is on a Canvas route.
  try {
    const parentPathname = window.parent.location.pathname;
    // If parent is on /canvas/preview/, this is the preview mode in the editor
    // shell, so return false.
    if (parentPathname.match(/^\/canvas\/preview\//)) {
      return false;
    }
    // Parent is on /canvas/ (but not /canvas/preview/), so we're in the editor.
    if (parentPathname.match(/^\/canvas/)) {
      return true;
    }
  } catch {
    // Cross-origin or other error accessing parent - cannot determine preview
    // status, return false for safety.
    return false;
  }

  // Parent is not on a Canvas route, so we're not in the Canvas editor.
  return false;
};
