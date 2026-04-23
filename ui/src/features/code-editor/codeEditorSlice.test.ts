import { describe, expect, it } from 'vitest';

import { makeStore } from '@/app/store';
import {
  initialState,
  removeProp,
  selectCodeComponentProperty,
  updateProp,
} from '@/features/code-editor/codeEditorSlice';

import type { AppStore } from '@/app/store';

const baseProp = {
  type: 'string' as const,
  example: '',
  derivedType: 'text' as const,
};

function storeWithCodeComponent(
  override: typeof initialState['codeComponent'],
): AppStore {
  return makeStore({
    codeEditor: {
      ...initialState,
      codeComponent: {
        ...initialState.codeComponent,
        ...override,
      },
    },
  });
}

describe('codeEditorSlice', () => {
  describe('updateProp and required (schema key migration)', () => {
    it('migrates required from old to new key when machineName changes', () => {
      const propId = 'prop-1';
      const store = storeWithCodeComponent({
        machineName: 'x',
        name: 'X',
        props: [
          {
            id: propId,
            name: 'Field title',
            machineName: 'oldKey',
            ...baseProp,
          },
        ],
        required: ['oldKey'],
        slots: [],
        sourceCodeJs: '',
        sourceCodeCss: '',
        compiledJs: '',
        compiledCss: '',
        importedJsComponents: [],
        dataFetches: {},
        dataDependencies: {},
        status: false,
      });

      store.dispatch(
        updateProp({
          id: propId,
          updates: { machineName: 'newKey' },
        }),
      );

      const state = store.getState();
      expect(selectCodeComponentProperty('required')(state)).toEqual(['newKey']);
      expect(
        selectCodeComponentProperty('props')(state)[0].machineName,
      ).toBe('newKey');
    });

    it('migrates required when key was camelCase(name) and machineName is set for the first time', () => {
      const propId = 'p2';
      const store = storeWithCodeComponent({
        machineName: 'c',
        name: 'C',
        props: [
          {
            id: propId,
            name: 'Hero text',
            machineName: '',
            ...baseProp,
          },
        ],
        // camelCase("Hero text") === "heroText"
        required: ['heroText'],
        slots: [],
        sourceCodeJs: '',
        sourceCodeCss: '',
        compiledJs: '',
        compiledCss: '',
        importedJsComponents: [],
        dataFetches: {},
        dataDependencies: {},
        status: false,
      });

      store.dispatch(
        updateProp({
          id: propId,
          updates: { machineName: 'hero_text' },
        }),
      );

      const state = store.getState();
      expect(selectCodeComponentProperty('required')(state)).toEqual([
        'hero_text',
      ]);
    });
  });

  describe('removeProp and required', () => {
    it('removes the schema key from required when prop has explicit machineName', () => {
      const propId = 'p-remove';
      const store = storeWithCodeComponent({
        machineName: 'c',
        name: 'C',
        props: [
          {
            id: propId,
            name: 'Any title',
            machineName: 'custom_storage_key',
            ...baseProp,
          },
        ],
        required: ['custom_storage_key', 'otherKey'],
        slots: [],
        sourceCodeJs: '',
        sourceCodeCss: '',
        compiledJs: '',
        compiledCss: '',
        importedJsComponents: [],
        dataFetches: {},
        dataDependencies: {},
        status: false,
      });

      store.dispatch(removeProp({ propId }));

      const state = store.getState();
      expect(selectCodeComponentProperty('required')(state)).toEqual(['otherKey']);
      expect(selectCodeComponentProperty('props')(state)).toHaveLength(0);
    });
  });
});
