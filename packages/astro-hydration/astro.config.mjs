import { defineConfig } from 'astro/config';
import preact from '@astrojs/preact';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const require = createRequire(import.meta.url);
const pkg = require('./package.json');

// Maps `drupal-canvas`'s built subpath files to the bare specifier the import
// map exposes them under, so Rolldown rewrites its package-internal relative
// imports (e.g. `./drupal-utils.js`) back to bare ones. Relative imports bypass
// the import map and lose its cache-busting query string.
//
// Deliberately NOT derived from `drupal-canvas`'s `exports` field: most subpaths
// are published under a legacy `@/lib/*` alias, so `exports` would yield
// specifiers like `drupal-canvas/drupal-utils` that no import map entry
// resolves, breaking these modules at runtime.
// @see \Drupal\canvas\GlobalImports::getImportMap()
const drupalCanvasImportMapSpecifiers = new Map([
  ['index.js', 'drupal-canvas'],
  ['react.js', 'drupal-canvas/react'],
  ['FormattedText.js', '@/lib/FormattedText'],
  ['drupal-utils.js', '@/lib/drupal-utils'],
  ['jsonapi-utils.js', '@/lib/jsonapi-utils'],
  ['utils.js', '@/lib/utils'],
  ['next-image-standalone.js', 'next-image-standalone'],
  ['jsonapi-client.js', '@drupal-api-client/json-api-client'],
]);

/**
 * Resolves a `drupal-canvas` package-internal relative import to its bare
 * import map specifier.
 *
 * @param {string} source
 *   The import specifier, e.g. `./drupal-utils.js`.
 * @param {string | undefined} importer
 *   The importing module's resolved path.
 *
 * @return {string | null}
 *   The bare specifier, or `null` when this is not a `drupal-canvas`
 *   cross-subpath import.
 */
const resolveDrupalCanvasSubpath = (source, importer) => {
  if (!importer || !source.startsWith('.')) {
    return null;
  }
  // Only rewrite imports originating from within the drupal-canvas package.
  // `node_modules/drupal-canvas` is a symlink to `packages/drupal-canvas`, so
  // importer paths resolve to `.../packages/drupal-canvas/dist/...`.
  if (!importer.includes('/drupal-canvas/dist/')) {
    return null;
  }
  const basename = path.basename(source);
  const specifier = drupalCanvasImportMapSpecifiers.get(basename);
  // Don't rewrite a file to its own specifier; only rewrite cross-subpath
  // imports. Internal chunks not listed above (e.g. `migration-*.js`) stay
  // relative: they are not import map entries and their content-hashed
  // filenames already provide cache busting.
  if (!specifier || importer.endsWith(`/${basename}`)) {
    return null;
  }
  return specifier;
};


/**
 * Keeps import map libraries external in Astro's client build.
 *
 * Astro v7 (Vite 8 / Rolldown) no longer accepts a function-form `external` in
 * the Astro config: at `vite.build.rollupOptions.external` Vite arrayifies the
 * value and Rolldown rejects a function inside an array, and at
 * `vite.environments.client.build.rollupOptions.external` Astro's client build
 * config builder copies only the `output.*` sub-keys and drops `external`.
 *
 * Assigning `external` inside a plugin `options` hook sidesteps both: the hook
 * receives the fully resolved Rolldown input options for a single environment,
 * after Astro has built them, so the function survives verbatim.
 */
const externalizeImportMapLibraries = () => ({
  name: 'canvas-externalize-import-map',
  options(options) {
    // Only externalize in the client build; the server/prerender builds must
    // bundle these so Astro can render the island markup.
    if (this.environment?.name !== 'client') {
      return null;
    }
        // @see src/features/code-editor/Preview.tsx
        // @see src/Plugin/Canvas/ComponentSource/JsComponent.php
        const isExternal = (id, parent) => {
          // Mark React external so Astro's bundler doesn't bundle it. This way
          // if a module (e.g., lib/astro-hydration/src/lib/swr.ts) imports
          // React, our import maps will handle the module resolution, which
          // will take care of aliasing to `preact/compat`. This ensures that
          // imports in the code of code components as well as in bundled
          // packages can be mapped to the same module.
          // (An alternative would be to use the `compat` option of the
          // @astrojs/preact plugin, but it doesn't produce a bundle that can
          // work in both code components and bundled packages.)
          if (['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime'].includes(id)) {
            return true;
          }

          // @see docs/adr/0008-astro-hydration-bundled-dependencies-as-external.md
          // Libraries in the import map need special handling when imported from
          // nested dependencies. Without this, Rollup creates shared chunks with
          // relative imports (e.g., ./clsx.js) that bypass import maps, breaking
          // cache busting. Marking them external forces bare specifier imports
          // that the import map intercepts.
          const buildOnly = pkg.canvas?.buildOnly ?? [];
          const importMapLibraries = Object.keys(pkg.dependencies)
            .filter(dep => !buildOnly.includes(dep));

          // Check if id matches an import map library. Handle:
          // - Bare specifiers: "clsx", "preact"
          // - Subpath imports: "preact/hooks", "drupal-canvas/utils"
          // - Full paths: ".../node_modules/clsx/dist/clsx.mjs"
          const matchedLibrary = importMapLibraries.find(lib =>
            id === lib ||
            id.startsWith(`${lib}/`) ||
            id.includes(`/node_modules/${lib}/`)
          );

          if (matchedLibrary) {
            // The island client renderer must share the page's `drupal-canvas`
            // and `preact` module instances with Code Components, so its
            // imports stay bare and resolve through the import map.
            if (parent?.includes(path.resolve(__dirname, 'src/lib/canvas-client.ts'))) {
              return true;
            }
            // Bundle if imported directly from astro-hydration source.
            if (parent?.includes(path.resolve(__dirname, 'src/'))) {
              return false;
            }
            // Preact subpaths are separate output chunks (each with its own
            // import map entry). Their internal imports must use bare specifiers
            // so they don't bypass the import map. This applies to:
            // - Cross-subpath imports within preact itself (e.g., hooks → preact)
            // - The @astrojs/preact client directive importing preact
            // Without this, Rollup uses relative imports (e.g., ./preact.module.js)
            // that lack the cache-busting query string from the import map,
            // causing the browser to load two separate Preact instances.
            if (matchedLibrary === 'preact' &&
                (parent?.includes('/node_modules/preact/') || parent?.includes('/node_modules/@astrojs/preact/')) &&
                (id === 'preact' || id === 'preact/hooks' || id === 'preact/compat')) {
              return true;
            }
            // Bundle if it's an internal import within the same package (e.g.,
            // swr/dist/_internal imported by swr/dist/index).
            if (parent?.includes(`/node_modules/${matchedLibrary}/`)) {
              return false;
            }
            // Bundle if imported from a build-only package (e.g., astro
            // importing a shared dependency).
            if (buildOnly.some(pkg => parent?.includes(`/node_modules/${pkg}/`))) {
              return false;
            }
            // Bundle if parent is a Vite/Rollup wrapper (e.g., ?commonjs-es-import).
            if (parent?.includes('?')) {
              return false;
            }
            // Mark as external so it uses the import map.
            return true;
          }

          return false;
        };
    options.external = isExternal;
    return options;
  },
  resolveId: {
    // Run before the default resolver so the specifier stays bare.
    order: 'pre',
    handler(source, importer) {
      if (this.environment?.name !== 'client') {
        return null;
      }
      // Skip virtual modules.
      if (source.startsWith('\0')) {
        return null;
      }
      const specifier = resolveDrupalCanvasSubpath(source, importer);
      return specifier ? { id: specifier, external: true } : null;
    },
  },
});

// https://astro.build/config
export default defineConfig({
  cacheDir: '../../.cache/astro',

  // Enable Preact to support Preact JSX components.
  integrations: [preact()],
  vite: {
    plugins: [externalizeImportMapLibraries()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, 'src/'),
      },
    },
    // Astro 6+ scopes client build output configuration to Vite's client
    // environment, so these `output` options must live under
    // `environments.client`. Under `build.rollupOptions.output` Astro ignores
    // them and emits hashed filenames into `dist/_astro/` instead.
    // @see https://docs.astro.build/en/guides/upgrade-to/v6/#changed-rollup-output-file-name-config-path-vite-config
    environments: {
      client: {
        build: {
          rollupOptions: {
            output: {
              // Filename pattern for the output files
              entryFileNames: '[name].js',
              chunkFileNames: (chunkInfo) => {
                // Make sure the output chunks for dependencies have useful file
                // names so we can easily distinguish between them.
                const matches = {
                  'astro-hydration/src/lib/jsx-runtime-default.js': 'jsx-runtime-default.js',
                  'preact-render-to-string': 'preact-render-to-string.js',
                  clsx: 'clsx.js',
                  'class-variance-authority': 'class-variance-authority.js',
                  'tailwind-merge': 'tailwind-merge.js',
                  'astro-hydration/src/lib/jsonapi-params.ts': 'jsonapi-params.js',
                  'astro-hydration/src/lib/swr.ts': 'swr.js',
                  'astro-hydration/src/lib/canvas-client.ts': 'canvas-client.js',
                  'drupal-canvas': 'drupal-canvas.js',
                };
                return Object.entries(matches).reduce((carry, [key, value]) => {
                  if (chunkInfo.facadeModuleId?.includes(`node_modules/${key}`)) {
                    return value;
                  }
                  return carry;
                }, '[name].js');
              },
              assetFileNames: '[name][extname]',
            },
          },
        },
      },
    },
  },
});
