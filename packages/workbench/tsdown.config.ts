import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: false,
  dts: false,
  entry: ['vite.published.config.ts'],
  external: [
    'vite',
    '@vitejs/plugin-react',
    '@tailwindcss/vite',
    'vite-plugin-svgr',
    'react',
    'react-dom',
    'react/jsx-runtime',
    'react/jsx-dev-runtime',
  ],
  format: ['es'],
  noExternal: ['@drupal-canvas/discovery', '@drupal-canvas/vite-compat'],
  outDir: 'dist/server',
  platform: 'node',
});
