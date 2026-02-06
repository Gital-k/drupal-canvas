import { describe, expect, it } from 'vitest';
import {
  isFieldItemEditorEnabled,
  isFieldTypeSupported,
  shouldUseFieldItemEditor,
} from './featureFlags';

describe('featureFlags', () => {
  describe('isFieldItemEditorEnabled', () => {
    it('returns false when window is undefined', () => {
      expect(isFieldItemEditorEnabled()).toBe(false);
    });

    it('returns false when drupalSettings is undefined', () => {
      (global as any).window = {};
      expect(isFieldItemEditorEnabled()).toBe(false);
    });

    it('returns false when canvas settings are missing', () => {
      (global as any).window = {
        drupalSettings: {},
      };
      expect(isFieldItemEditorEnabled()).toBe(false);
    });

    it('returns false when enabled is false', () => {
      (global as any).window = {
        drupalSettings: {
          canvas: {
            fieldItemEditor: {
              enabled: false,
            },
          },
        },
      };
      expect(isFieldItemEditorEnabled()).toBe(false);
    });

    it('returns true when enabled is true', () => {
      (global as any).window = {
        drupalSettings: {
          canvas: {
            fieldItemEditor: {
              enabled: true,
            },
          },
        },
      };
      expect(isFieldItemEditorEnabled()).toBe(true);
    });
  });

  describe('isFieldTypeSupported', () => {
    it('returns false when window is undefined', () => {
      delete (global as any).window;
      expect(isFieldTypeSupported('string')).toBe(false);
    });

    it('returns false when enabledFieldTypes is not an array', () => {
      (global as any).window = {
        drupalSettings: {
          canvas: {
            fieldItemEditor: {
              enabledFieldTypes: null,
            },
          },
        },
      };
      expect(isFieldTypeSupported('string')).toBe(false);
    });

    it('returns true when field type is in the list', () => {
      (global as any).window = {
        drupalSettings: {
          canvas: {
            fieldItemEditor: {
              enabledFieldTypes: ['string', 'string_long'],
            },
          },
        },
      };
      expect(isFieldTypeSupported('string')).toBe(true);
      expect(isFieldTypeSupported('string_long')).toBe(true);
    });

    it('returns false when field type is not in the list', () => {
      (global as any).window = {
        drupalSettings: {
          canvas: {
            fieldItemEditor: {
              enabledFieldTypes: ['string'],
            },
          },
        },
      };
      expect(isFieldTypeSupported('integer')).toBe(false);
    });
  });

  describe('shouldUseFieldItemEditor', () => {
    it('returns false when feature is disabled', () => {
      (global as any).window = {
        drupalSettings: {
          canvas: {
            fieldItemEditor: {
              enabled: false,
            },
          },
        },
      };
      expect(shouldUseFieldItemEditor()).toBe(false);
    });

    it('returns true when feature is enabled and no fieldType provided', () => {
      (global as any).window = {
        drupalSettings: {
          canvas: {
            fieldItemEditor: {
              enabled: true,
            },
          },
        },
      };
      expect(shouldUseFieldItemEditor()).toBe(true);
    });

    it('returns true when feature is enabled and fieldType is supported', () => {
      (global as any).window = {
        drupalSettings: {
          canvas: {
            fieldItemEditor: {
              enabled: true,
              enabledFieldTypes: ['string'],
            },
          },
        },
      };
      expect(shouldUseFieldItemEditor('string')).toBe(true);
    });

    it('returns false when feature is enabled but fieldType is not supported', () => {
      (global as any).window = {
        drupalSettings: {
          canvas: {
            fieldItemEditor: {
              enabled: true,
              enabledFieldTypes: ['string'],
            },
          },
        },
      };
      expect(shouldUseFieldItemEditor('integer')).toBe(false);
    });
  });
});
