import { defineConfig } from 'tsup';

export default defineConfig({
  entry: ['src/*'],
  bundle: false,
  format: ['esm'],
  clean: true,
  sourcemap: process.env.NODE_ENV === 'development',
  splitting: false,
  treeshake: true,
  minify: true,
});
