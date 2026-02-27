import type React from 'react';
import { GlobeIcon } from '@radix-ui/react-icons';
import { Flex, Select, Text, Tooltip } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectAvailableLanguages,
  selectCurrentLanguage,
  setCurrentLanguage,
} from '@/features/language/languageSlice';
import { useGetLanguagesQuery } from '@/services/languageApi';

import styles from './LanguageSelector.module.css';

interface LanguageSelectorProps {
  /**
   * Optional set of langcodes that have existing translations for the current
   * entity. When provided, each language shows a filled dot (translated) or
   * empty dot (not yet translated).
   */
  translatedLanguages?: Set<string>;
}

const LanguageSelector: React.FC<LanguageSelectorProps> = ({
  translatedLanguages,
}) => {
  const dispatch = useAppDispatch();
  const currentLanguage = useAppSelector(selectCurrentLanguage);
  const availableLanguages = useAppSelector(selectAvailableLanguages);

  // Fetch languages and hydrate the store. Cached after first call.
  useGetLanguagesQuery();

  // Hide on single-language sites.
  if (availableLanguages.length <= 1) {
    return null;
  }

  const currentLabel =
    availableLanguages.find((l) => l.id === currentLanguage)?.label ??
    currentLanguage.toUpperCase();

  return (
    <Flex align="center" gap="1" className={styles.wrapper}>
      <GlobeIcon className={styles.globeIcon} aria-hidden="true" />
      <Select.Root
        value={currentLanguage}
        onValueChange={(value) => dispatch(setCurrentLanguage(value))}
        size="1"
      >
        <Tooltip content="Switch language">
          <Select.Trigger
            className={styles.trigger}
            aria-label={`Language: ${currentLabel}`}
          />
        </Tooltip>
        <Select.Content position="popper" sideOffset={4}>
          {availableLanguages.map((lang) => {
            const isTranslated =
              !translatedLanguages || translatedLanguages.has(lang.id);

            return (
              <Select.Item key={lang.id} value={lang.id}>
                <Flex align="center" gap="2">
                  <Text size="2">{lang.label}</Text>
                  {lang.isDefault && (
                    <Text size="1" className={styles.defaultBadge}>
                      default
                    </Text>
                  )}
                  {translatedLanguages && (
                    <Tooltip
                      content={
                        isTranslated
                          ? 'Translation exists'
                          : 'No translation yet'
                      }
                    >
                      <span
                        className={
                          isTranslated
                            ? styles.dotTranslated
                            : styles.dotMissing
                        }
                        aria-label={
                          isTranslated
                            ? 'Translation exists'
                            : 'No translation yet'
                        }
                      />
                    </Tooltip>
                  )}
                </Flex>
              </Select.Item>
            );
          })}
        </Select.Content>
      </Select.Root>
    </Flex>
  );
};

export default LanguageSelector;
