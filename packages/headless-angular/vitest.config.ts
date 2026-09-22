import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  resolve: {
    alias: {
      '@drupal-canvas/headless-angular/contracts': fileURLToPath(
        new URL('./contracts/index.ts', import.meta.url),
      ),
    },
  },
  test: { include: ['tests/**/*.test.ts'], environment: 'node' },
});
