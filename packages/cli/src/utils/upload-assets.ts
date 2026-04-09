import { promises as fs } from 'node:fs';
import path from 'path';

import type { ApiService } from '../services/api';

/**
 * Recursively collect all files in a directory
 */
async function collectFiles(dir: string, baseDir: string = dir): Promise<string[]> {
  const files: string[] = [];
  const entries = await fs.readdir(dir, { withFileTypes: true });

  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      const subFiles = await collectFiles(fullPath, baseDir);
      files.push(...subFiles);
    } else {
      // Store relative path from baseDir
      files.push(path.relative(baseDir, fullPath));
    }
  }

  return files;
}

/**
 * Upload all assets from the asset directory to Drupal
 */
export async function uploadAssets(
  assetDir: string,
  outputDir: string,
  apiService: Pick<ApiService, 'uploadArtifact'>,
  progressCallback?: (current: number, total: number) => void,
): Promise<number> {
  // Check if asset directory exists in output
  const assetPath = path.join(outputDir, 'assets');
  try {
    await fs.access(assetPath);
  } catch {
    // No assets to upload
    return 0;
  }

  // Collect all asset files
  const assetFiles = await collectFiles(assetPath);
  
  if (assetFiles.length === 0) {
    return 0;
  }

  // Upload each asset file
  let uploaded = 0;
  for (const relativeFilePath of assetFiles) {
    const fullPath = path.join(assetPath, relativeFilePath);
    const fileBuffer = await fs.readFile(fullPath);
    
    // Use the relative path as the filename to preserve directory structure
    const filename = relativeFilePath.replace(/\\/g, '/'); // Normalize path separators
    
    await apiService.uploadArtifact(filename, fileBuffer);
    uploaded++;
    
    if (progressCallback) {
      progressCallback(uploaded, assetFiles.length);
    }
  }

  return uploaded;
}
