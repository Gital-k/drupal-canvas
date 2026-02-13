import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import TextField from '@/components/form/components/TextField';
import { wrapRHF } from '@/components/form/wrapRHF';
import { useEntityTitle } from '@/hooks/useEntityTitle';
import { a2p } from '@/local_packages/utils.js';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import { getDrupalSettings } from '@/utils/drupal-globals';

import type { Attributes } from '@/types/DrupalAttribute';
import type { transliterate as TransliterateType } from '@/types/transliterate';

const getTransliterate = (): TransliterateType => {
  const { transliterate: drupalTransliterate } = window;
  return drupalTransliterate;
};

const drupalSettings = getDrupalSettings();

const getPathAlias = (titleValue: string) => {
  const drupalTransliterate = getTransliterate();
  const alias = titleValue
    .toLowerCase()
    .replace(/\s+/g, '-')
    .replace(/[^\w-]+/g, '')
    .replace(/_+/g, '-')
    .replace(/--+/g, '-')
    .replace(/^-+/, '')
    .replace(/-+$/, '');

  if (alias.length) {
    const langcode = drupalSettings.langcode;
    const languageOverrides =
      drupalSettings?.transliteration_language_overrides?.[langcode];

    const replace: Record<string, string> = {};
    if (languageOverrides) {
      Object.keys(languageOverrides).forEach((key) => {
        replace[String.fromCharCode(parseInt(key, 10))] =
          languageOverrides[key];
      });
    }

    return `/${drupalTransliterate(alias, { replace })}`;
  }

  return '';
};

/**
 * Path widget with automatic URL alias generation from title.
 */
const DrupalPathWidget = ({
  attributes = {},
}: {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  };
  children?: React.ReactNode;
}) => {
  const initialValue = attributes?.value || '';
  const [pathValue, setPathValue] = useState<string>(initialValue.toString());
  const [lastGeneratedPath, setLastGeneratedPath] = useState<string>('');
  const [initialTitle, setInitialTitle] = useState<string | null>(null);
  const [userHasEditedPath, setUserHasEditedPath] = useState(false);
  const [isInitialized, setIsInitialized] = useState(false);

  const { entityId, entityType } = useParams();
  const titleInput = useEntityTitle();
  const { data: fetchedLayout } = useGetPageLayoutQuery(
    entityId && entityType ? { entityId, entityType } : skipToken,
  );
  const isPublished = fetchedLayout?.isPublished;

  // Initialize state on first render by checking if initial path matches generated path
  useEffect(() => {
    if (isInitialized || !titleInput) return;

    setInitialTitle(titleInput);

    const generatedPathFromInitialTitle = getPathAlias(titleInput);
    const initialPathValue = initialValue.toString();

    if (
      initialPathValue &&
      initialPathValue !== generatedPathFromInitialTitle
    ) {
      // Initial path exists but doesn't match what would be generated
      // This means it was manually customized - don't overwrite it
      setUserHasEditedPath(true);
      setLastGeneratedPath(initialPathValue);
    } else if (initialPathValue) {
      // Initial path matches generated path - treat as auto-generated
      setLastGeneratedPath(initialPathValue);
    }

    setIsInitialized(true);
  }, [titleInput, isInitialized, initialValue]);

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      setPathValue(e.target.value);
      if (attributes.onChange) {
        attributes.onChange(e);
      }
    },
    [attributes],
  );

  const handleManualChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      // User manually edited the path - set the flag to stop auto-generation
      setUserHasEditedPath(true);
      handleChange(e);
    },
    [handleChange],
  );

  // Auto-generate path from title
  useEffect(() => {
    if (!titleInput || !isInitialized) return;

    const generatedPath = getPathAlias(titleInput);

    // Never update if published with a path
    if (isPublished && pathValue) {
      return;
    }

    const pathMatchesGenerated = pathValue === lastGeneratedPath;
    const titleHasChanged = titleInput !== initialTitle;

    // If user has manually edited the path, check if they've changed it back to match generated
    if (userHasEditedPath) {
      // If the current path matches what we would generate, resume auto-generation
      if (pathValue === generatedPath) {
        setUserHasEditedPath(false);
      } else {
        // User has diverged from auto-generated path, don't auto-generate
        return;
      }
    }

    let shouldGenerate = false;

    // Only generate if the new path would be different from current
    if (generatedPath !== pathValue) {
      if (pathMatchesGenerated && pathValue) {
        // Path matches what we last generated, so keep auto-generating
        console.log(
          '[DrupalPathWidget] Should generate: path matches previously generated and new path differs',
        );
        shouldGenerate = true;
      } else if (!pathValue && titleHasChanged) {
        // No path exists and title has changed from initial
        console.log(
          '[DrupalPathWidget] Should generate: no path and title changed',
        );
        shouldGenerate = true;
      }
    } else {
      console.log(
        '[DrupalPathWidget] Should NOT generate: generated path matches current path',
        {
          generatedPath,
          pathValue,
        },
      );
    }

    if (shouldGenerate) {
      setLastGeneratedPath(generatedPath);
      const syntheticEvent = {
        target: {
          value: generatedPath,
          name: attributes.name,
        },
      } as React.ChangeEvent<HTMLInputElement>;
      handleChange(syntheticEvent);
    }
  }, [
    titleInput,
    pathValue,
    lastGeneratedPath,
    isPublished,
    initialTitle,
    userHasEditedPath,
    isInitialized,
    handleChange,
    attributes.name,
  ]);

  const processedAttrs = a2p(attributes, {
    onChange: handleManualChange,
    value: pathValue,
  });

  return <TextField attributes={processedAttrs} />;
};

export default wrapRHF(DrupalPathWidget);
