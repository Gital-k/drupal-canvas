import { promises as fs } from 'node:fs';
import path from 'path';

import type { Result } from '../types/Result';

/**
 * Recursively copy a directory
 */
async function copyDirectory(src: string, dest: string): Promise<void> {
  await fs.mkdir(dest, { recursive: true });
  const entries = await fs.readdir(src, { withFileTypes: true });

  for (const entry of entries) {
    const srcPath = path.join(src, entry.name);
    const destPath = path.join(dest, entry.name);

    if (entry.isDirectory()) {
      await copyDirectory(srcPath, destPath);
    } else {
      await fs.copyFile(srcPath, destPath);
    }
  }
}

/**
 * Copy asset directory to build output
 */
export async function copyAssets(
  assetDir: string,
  outputDir: string,
): Promise<Result> {
  const result: Result = {
    itemName: 'Assets',
    success: true,
  };

  try {
    // Check if asset directory exists
    const assetPath = path.resolve(process.cwd(), assetDir);
    try {
      await fs.access(assetPath);
    } catch {
      // Asset directory doesn't exist, skip silently
      return result;
    }

    // Copy assets to output/assets
    const destPath = path.join(outputDir, 'assets');
    await copyDirectory(assetPath, destPath);
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    result.success = false;
    result.details = [
      { heading: 'Error copying assets', content: errorMessage },
    ];
  }

  return result;
}
