import { transformSync } from '@swc/wasm';

import type { Options as SwcOptions } from '@swc/wasm';

// @see src/features/code-editor/hooks/useCompileJavaScript.ts
const SWC_OPTIONS: SwcOptions = {
  jsc: {
    parser: {
      syntax: 'typescript',
      tsx: true,
    },
    target: 'es2015',
    transform: {
      react: {
        pragmaFrag: 'Fragment',
        throwIfNamespace: true,
        development: false,
        runtime: 'automatic',
      },
    },
  },
  module: {
    type: 'es6',
  },
} as const;

/**
 * Replace asset paths in source code before compilation.
 * Replaces references to the asset directory with the actual Drupal path.
 * 
 * @param source - Source code to process
 * @param assetDir - Local asset directory name (e.g., 'public')
 * @param assetBasePath - Drupal base path for assets (e.g., '/sites/default/files/canvas-artifacts/')
 * @returns Source code with replaced asset paths
 */
function replaceAssetPaths(
  source: string,
  assetDir: string,
  assetBasePath: string,
): string {
  // Match import statements and string literals that reference the asset directory
  // Patterns to match:
  // - import ... from '/public/...'
  // - import ... from './public/...'
  // - '/public/...' or "./public/..." in code
  // - `/public/...` in template literals
  
  const patterns = [
    // Import statements with quotes
    new RegExp(`(import\\s+[^'"]*['"])(\\.?\\.?/${assetDir}/[^'"]+)(['"])`, 'g'),
    // String literals with single quotes
    new RegExp(`(['"])(\\.?\\.?/${assetDir}/[^'"]+)(['"])`, 'g'),
    // Template literals
    new RegExp(`(\`)([^`]*\\.?\\.?/${assetDir}/[^\`]+)(\`)`, 'g'),
  ];

  let result = source;
  for (const pattern of patterns) {
    result = result.replace(pattern, (match, prefix, path, suffix) => {
      // Remove leading ./ or ../ and the asset directory name
      const cleanPath = path.replace(new RegExp(`^\\.?\\.?/${assetDir}/`), '');
      // Construct the new path with the Drupal base path
      const newPath = `${assetBasePath}${cleanPath}`;
      return `${prefix}${newPath}${suffix}`;
    });
  }

  return result;
}

export function compileJS(
  source: string,
  assetDir?: string,
  assetBasePath?: string,
): string {
  let processedSource = source;
  
  // Replace asset paths if configuration is provided
  if (assetDir && assetBasePath) {
    processedSource = replaceAssetPaths(source, assetDir, assetBasePath);
  }
  
  const { code } = transformSync(processedSource, SWC_OPTIONS);
  return code;
}
