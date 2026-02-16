import path from 'path';
import { defineConfig } from 'vite';
import { discoverCodeComponents } from '@drupal-canvas/component-discovery';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

import type { DiscoveryResult } from '@drupal-canvas/component-discovery';
import type { Plugin } from 'vite';

const hostProjectRoot = process.cwd();

function isDiscoveryRelevantPath(filePath: string): boolean {
  const normalizedPath = filePath.replaceAll('\\', '/');
  return (
    /(^|\/)component\.yml$/.test(normalizedPath) ||
    /(^|\/)[^/]+\.component\.yml$/.test(normalizedPath) ||
    /\.(js|jsx|ts|tsx|css)$/.test(normalizedPath)
  );
}

function canvasWorkbenchDiscovery(): Plugin {
  let cachedResult: DiscoveryResult | null = null;
  let refreshTask: Promise<void> | null = null;

  const refresh = async () => {
    if (refreshTask) {
      await refreshTask;
      return;
    }

    refreshTask = (async () => {
      cachedResult = await discoverCodeComponents({ scanRoot: process.cwd() });
    })();

    try {
      await refreshTask;
    } finally {
      refreshTask = null;
    }
  };

  return {
    name: 'canvas-workbench-discovery',
    async configureServer(server) {
      await refresh();

      server.watcher.add(hostProjectRoot);

      server.middlewares.use('/__canvas/discovery', (_req, res) => {
        void (async () => {
          await refresh();

          res.statusCode = 200;
          res.setHeader('Content-Type', 'application/json');
          res.end(JSON.stringify(cachedResult));
        })().catch((error) => {
          res.statusCode = 500;
          res.setHeader('Content-Type', 'application/json');
          res.end(
            JSON.stringify({
              error: error instanceof Error ? error.message : String(error),
            }),
          );
        });
      });

      server.watcher.on('all', (event, filePath) => {
        if (!['add', 'change', 'unlink'].includes(event)) {
          return;
        }

        if (!isDiscoveryRelevantPath(filePath)) {
          return;
        }

        void refresh()
          .then(() => {
            server.ws.send({ type: 'full-reload' });
          })
          .catch((error) => {
            server.config.logger.error(
              `Failed to refresh component discovery: ${String(error)}`,
            );
          });
      });
    },
  };
}

// https://vite.dev/config/
export default defineConfig({
  root: __dirname,
  server: {
    fs: {
      allow: [hostProjectRoot, __dirname],
    },
  },
  plugins: [react(), tailwindcss(), canvasWorkbenchDiscovery()] as any,
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
});
