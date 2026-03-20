import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: ['dist'],
  copy: [
    { from: 'public/**/*', to: 'dist/client', flatten: false },
    { from: 'src/client/**/*', to: 'dist/client/src', flatten: false },
    { from: 'src/lib/**/*', to: 'dist/client/src', flatten: false },
  ],
  deps: {
    alwaysBundle: ['@drupal-canvas/discovery', '@drupal-canvas/vite-compat'],
    neverBundle: [
      'vite',
      '@vitejs/plugin-react',
      '@tailwindcss/vite',
      'vite-plugin-svgr',
      'react',
      'react-dom',
      'react/jsx-runtime',
      'react/jsx-dev-runtime',
    ],
  },
  dts: false,
  entry: ['vite.published.config.ts'],
  format: ['es'],
  outDir: 'dist/server',
  platform: 'node',
});
