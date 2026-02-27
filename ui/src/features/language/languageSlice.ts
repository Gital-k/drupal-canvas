import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

export interface Language {
  id: string;
  label: string;
  isDefault: boolean;
}

export interface LanguageState {
  currentLanguage: string;
  availableLanguages: Language[];
}

const SESSION_KEY = 'canvas_current_language';

const getPersistedLanguage = (): string => {
  try {
    return sessionStorage.getItem(SESSION_KEY) || '';
  } catch {
    return '';
  }
};

export const initialState: LanguageState = {
  currentLanguage: getPersistedLanguage(),
  availableLanguages: [],
};

export const languageSlice = createSlice({
  name: 'language',
  initialState,
  reducers: (create) => ({
    setCurrentLanguage: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.currentLanguage = action.payload;
        try {
          sessionStorage.setItem(SESSION_KEY, action.payload);
        } catch {
          // sessionStorage unavailable — silently ignore.
        }
      },
    ),
    setAvailableLanguages: create.reducer(
      (state, action: PayloadAction<Language[]>) => {
        state.availableLanguages = action.payload;
        // If no language is persisted yet, default to the site default.
        if (!state.currentLanguage) {
          const defaultLang = action.payload.find((l) => l.isDefault);
          if (defaultLang) {
            state.currentLanguage = defaultLang.id;
            try {
              sessionStorage.setItem(SESSION_KEY, defaultLang.id);
            } catch {
              // sessionStorage unavailable — silently ignore.
            }
          }
        }
      },
    ),
  }),
  selectors: {
    selectCurrentLanguage: (state): string => state.currentLanguage,
    selectAvailableLanguages: (state): Language[] => state.availableLanguages,
  },
});

export const { setCurrentLanguage, setAvailableLanguages } =
  languageSlice.actions;

export const { selectCurrentLanguage, selectAvailableLanguages } =
  languageSlice.selectors;