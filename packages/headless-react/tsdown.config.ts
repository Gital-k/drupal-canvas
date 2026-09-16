import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: ['dist'],
  entry: {
    index: 'src/index.ts',
  },
  format: ['es'],
  // Emit .js and .d.ts (not .mjs/.d.mts) to match the repository's published
  // package convention regardless of the tsdown version's default.
  fixedExtension: false,
  platform: 'node',
  dts: {
    eager: true,
  },
  outDir: 'dist',
});
