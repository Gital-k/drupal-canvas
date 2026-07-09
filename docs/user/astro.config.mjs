// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

/**
 * Rehype plugin: prefix root-relative content links with the configured base.
 *
 * The site is deployed under a base path (`/canvas/` in production,
 * `/canvas/mr-<iid>/` for merge request previews — see `ASTRO_BASE` in
 * `.gitlab-ci.yml`). Starlight resolves sidebar `slug` links against that base,
 * but hardcoded root-relative Markdown links in the content — for example
 * `[Translations](/guides/translations)` — are emitted verbatim and would 404
 * on the deployed site. This prepends the base so those links resolve correctly
 * regardless of the deploy target.
 */
function rehypeBaseRelativeLinks() {
  // ASTRO_BASE is normalized with a trailing slash; drop it so `base + href`
  // never produces a double slash. Empty when unset (local dev at the domain
  // root), which makes the rewrite a no-op.
  const base = (process.env.ASTRO_BASE || '').replace(/\/+$/, '');
  return (/** @type {any} */ tree) => {
    if (!base) return;
    const visit = (/** @type {any} */ node) => {
      if (
        node.type === 'element' &&
        node.tagName === 'a' &&
        typeof node.properties?.href === 'string'
      ) {
        const href = node.properties.href;
        // Only rewrite in-site absolute paths. Leave external (`//`, `https://`),
        // anchor (`#…`), and already-prefixed links untouched.
        if (
          href.startsWith('/') &&
          !href.startsWith('//') &&
          href !== base &&
          !href.startsWith(`${base}/`)
        ) {
          node.properties.href = `${base}${href}`;
        }
      }
      if (Array.isArray(node.children)) node.children.forEach(visit);
    };
    visit(tree);
  };
}

// https://astro.build/config
export default defineConfig({
  trailingSlash: 'never',
  markdown: {
    rehypePlugins: [rehypeBaseRelativeLinks],
  },
  integrations: [
    starlight({
      title: 'Drupal Canvas',
      social: [
        {
          icon: 'gitlab',
          label: 'GitLab',
          href: 'https://git.drupalcode.org/project/canvas/',
        },
        {
          icon: 'slack',
          label: 'Slack',
          href: 'https://drupal.slack.com/archives/C072JMEPUS1',
        },
        {
          icon: 'bun',
          label: 'drupal.org',
          href: 'https://www.drupal.org/project/canvas',
        },
      ],
      sidebar: [
        {
          label: 'Code Components',
          items: [
            { label: 'Introduction', slug: 'code-components' },
            { label: 'Concepts', slug: 'code-components/concepts' },
            { label: 'Local codebase', slug: 'code-components/local-codebase' },
            {
              label: 'Imports and assets',
              slug: 'code-components/imports-and-assets',
            },
            { label: 'Built-in packages', slug: 'code-components/packages' },
            { label: 'Data fetching', slug: 'code-components/data-fetching' },
            {
              label: 'Responsive images',
              slug: 'code-components/responsive-images',
            },
            { label: 'Brand Kit', slug: 'code-components/brand-kit' },
            {
              label: 'Component metadata',
              slug: 'code-components/component-metadata',
            },
            {
              label: 'Workbench',
              items: [
                { label: 'Introduction', slug: 'code-components/workbench' },
                { label: 'Mocks', slug: 'code-components/workbench/mocks' },
                { label: 'Pages', slug: 'code-components/workbench/pages' },
                {
                  label: 'Content templates',
                  slug: 'code-components/workbench/content-templates',
                },
                {
                  label: 'Global regions',
                  slug: 'code-components/workbench/regions',
                },
              ],
            },
          ],
        },
        {
          label: 'SDC components',
          items: [
            { label: 'Introduction', slug: 'sdc-components' },
            { label: 'Props', slug: 'sdc-components/props' },
            { label: 'Slots', slug: 'sdc-components/slots' },
            { label: 'Image', slug: 'sdc-components/image' },
            {
              label: 'Validations',
              slug: 'sdc-components/validations',
            },
            { label: 'Troubleshooting', slug: 'sdc-components/troubleshooting' },
          ],
        },
        {
          label: 'AI assistant',
          items: [
            { label: 'Introduction', slug: 'ai-assistant' }
          ],
        },
        {
          label: 'Guides',
          items: [
            { label: 'Introduction', slug: 'guides' },
            { label: 'Translations', slug: 'guides/translations' },
          ],
        },
        {
          label: 'APIs',
          items: [
            { label: 'Introduction', slug: 'apis' },
            { label: 'Customizing forms', slug: 'apis/customizing-forms' },
            { label: 'Theme settings', slug: 'apis/theme-settings' },
          ],
        }
      ],
    }),
  ],
  base: process.env.ASTRO_BASE || undefined,
  site: process.env.ASTRO_SITE || undefined,
});
