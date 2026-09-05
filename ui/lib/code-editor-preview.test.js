import { describe, expect, it } from 'vitest';

import {
  getCompletePreviewPayload,
  isFromParentWindow,
  rewriteComponentImports,
} from './code-editor-preview.js';

describe('rewriteComponentImports', () => {
  it('rewrites a single-quoted bare specifier to its blob URL', () => {
    const js = "import Foo from '@/components/foo';";
    expect(rewriteComponentImports(js, { foo: 'blob:https://example/1' })).toBe(
      "import Foo from 'blob:https://example/1';",
    );
  });

  it('rewrites a double-quoted bare specifier to its blob URL', () => {
    const js = 'import Foo from "@/components/foo";';
    expect(rewriteComponentImports(js, { foo: 'blob:https://example/1' })).toBe(
      'import Foo from "blob:https://example/1";',
    );
  });

  it('rewrites every occurrence of multiple imported components', () => {
    const js =
      "import Foo from '@/components/foo';\nimport Bar from '@/components/bar';";
    const result = rewriteComponentImports(js, {
      foo: 'blob:1',
      bar: 'blob:2',
    });
    expect(result).toBe("import Foo from 'blob:1';\nimport Bar from 'blob:2';");
  });

  it('leaves code unchanged when no specifier matches', () => {
    const js = "import Foo from '@/components/foo';";
    expect(rewriteComponentImports(js, { bar: 'blob:1' })).toBe(js);
  });

  it('leaves code unchanged given no other components', () => {
    const js = "import Foo from '@/components/foo';";
    expect(rewriteComponentImports(js, {})).toBe(js);
  });
});

describe('isFromParentWindow', () => {
  it('is true for an event sourced from window.parent', () => {
    expect(isFromParentWindow({ source: window.parent })).toBe(true);
  });

  it('is false for an event sourced from anything else', () => {
    expect(isFromParentWindow({ source: {} })).toBe(false);
    expect(isFromParentWindow({ source: null })).toBe(false);
  });
});

describe('getCompletePreviewPayload', () => {
  const completeData = {
    compiledJs: 'export default () => null;',
    compiledJsForSlots: 'export const slot = () => null;',
    propValues: {},
    slotNames: [],
    drupalSettings: {},
  };

  it('returns the event data when every required property is present', () => {
    expect(getCompletePreviewPayload({ data: completeData })).toBe(
      completeData,
    );
  });

  it.each(Object.keys(completeData))(
    'returns null when %s is missing',
    (key) => {
      const { [key]: _omitted, ...incomplete } = completeData;
      expect(getCompletePreviewPayload({ data: incomplete })).toBeNull();
    },
  );

  it('returns null when data is absent entirely', () => {
    expect(getCompletePreviewPayload({})).toBeNull();
  });

  it('returns the event data when compiledJsForSlots is an empty string', () => {
    // A component with no slots compiles its slots preview module to an
    // empty string; that is complete data, not missing data.
    const data = { ...completeData, compiledJsForSlots: '' };
    expect(getCompletePreviewPayload({ data })).toBe(data);
  });
});
