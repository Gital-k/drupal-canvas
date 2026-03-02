import { GlobeIcon } from '@radix-ui/react-icons';
import { Flex, Select, Text, Tooltip } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectAvailableLanguages,
  selectCurrentLanguage,
  setCurrentLanguage,
} from '@/features/language/languageSlice';
import { useGetLanguagesQuery } from '@/services/languageApi';

import type React from 'react';

import styles from './LanguageSelector.module.css';
interface LanguageSelectorProps {
  entityType: string;
  entityId: string;
  translatedLanguages?: Set<string>;
}

const LanguageSelector: React.FC<LanguageSelectorProps> = ({
  entityType,
  entityId,
  translatedLanguages,
}) => {
  const dispatch = useAppDispatch();
  const currentLanguage = useAppSelector(selectCurrentLanguage);
  const availableLanguages = useAppSelector(selectAvailableLanguages);

  // Fetch languages for this entity
  useGetLanguagesQuery({ entityType, id: entityId });

  // Hide on single-language sites
  if (!availableLanguages || availableLanguages.length <= 1) return null;

  const currentLabel =
    availableLanguages.find((l) => l.id === currentLanguage)?.label ??
    currentLanguage.toUpperCase();

  return (
    <Flex align="center" gap="1">
      <GlobeIcon aria-hidden="true" />
      <Select.Root
        value={currentLanguage}
        onValueChange={(value) => dispatch(setCurrentLanguage(value))}
        size="1"
      >
        <Select.Trigger aria-label={`Language: ${currentLabel}`} />
        <Select.Content position="popper" sideOffset={4}>
          {availableLanguages.map((lang) => {
            const isTranslated =
              !translatedLanguages || translatedLanguages.has(lang.id);
            return (
              <Select.Item key={lang.id} value={lang.id}>
                <Flex align="center" gap="2">
                  <Text>{lang.label}</Text>
                  {lang.isDefault && <Text>default</Text>}
                  {translatedLanguages && (
                    <span
                      aria-label={
                        isTranslated
                          ? 'Translation exists'
                          : 'No translation yet'
                      }
                      style={{
                        width: '8px',
                        height: '8px',
                        borderRadius: '50%',
                        backgroundColor: isTranslated ? 'green' : 'gray',
                      }}
                    />
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
