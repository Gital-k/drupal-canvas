import path from 'path';
import { basename } from 'path/win32';
import { ESLint } from 'eslint';
import {
  required as drupalCanvasRequired,
  requiredDeprecated as drupalCanvasRequiredDeprecated,
} from '@drupal-canvas/eslint-config';

import {
  findMetadataPath,
  readComponentMetadata,
} from './process-component-files';
import { validateContentEntityReferencePropExpressions } from './validate-content-entity-reference';

import type { Result } from '../types/Result';
import type { ContentEntityReferenceValidationApi } from './validate-content-entity-reference';

export async function validateComponent(
  componentDir: string,
  fix: boolean = false,
  deprecated?: boolean,
  apiService?: ContentEntityReferenceValidationApi,
): Promise<Result> {
  const eslint = new ESLint({
    overrideConfigFile: true,
    overrideConfig: deprecated
      ? drupalCanvasRequiredDeprecated
      : drupalCanvasRequired,
    fix,
  });
  const eslintResults = await eslint.lintFiles(componentDir + '/**/*');
  if (fix) {
    await ESLint.outputFixes(eslintResults);
  }
  const details: { heading: string; content: string }[] = [];
  eslintResults
    .filter((result) => result.errorCount > 0)
    .forEach((result) => {
      const messages = result.messages.map(
        (msg) =>
          `Line ${msg.line}, Column ${msg.column}: ` +
          msg.message +
          (msg.ruleId ? ` (${msg.ruleId})` : ''),
      );

      details.push({
        heading: path.relative(process.cwd(), result.filePath),
        content: messages.join('\n\n'),
      });
    });

  const metadataPath = findMetadataPath(componentDir);
  if (metadataPath) {
    const metadata = await readComponentMetadata(metadataPath);
    if (metadata) {
      details.push(
        ...(await validateContentEntityReferencePropExpressions(
          metadata,
          apiService,
        )),
      );
    }
  }

  const success =
    eslintResults.every((result) => result.errorCount === 0) &&
    details.length === 0;

  return {
    itemName: basename(componentDir),
    success,
    details,
  };
}
