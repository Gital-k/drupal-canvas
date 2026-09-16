import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: ['dist'],
  entry: {
    index: 'src/index.ts',
    'client/index': 'src/client/index.ts',
    'config/index': 'src/config/index.ts',
    'canvas-component-tree': 'src/canvas-component-tree.tsx',
    'component-preview-page': 'src/component-preview-page.tsx',
  },
  format: ['es'],
  // Emit .js and .d.ts (not .mjs/.d.mts) to match the repository's published
  // package convention regardless of the tsdown version's default.
  fixedExtension: false,
  platform: 'node',
  deps: {
    neverBundle: [
      // Resolved by the consuming app's own bundler config (the alias
      // withCanvas() installs into webpack/turbopack), never by this
      // package's build. There is no real module behind this specifier
      // until an app supplies one.
      '@drupal-canvas/headless-next-generated-components',
    ],
  },
  dts: {
    eager: true,
  },
  outDir: 'dist',
});
