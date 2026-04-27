import { describe, expect, it } from 'vitest';

import { createComponentPayload } from './process-component-files';

import type { Metadata } from '../types/Metadata';

describe('createComponentPayload', () => {
  const baseMetadata: Metadata = {
    name: 'Card',
    machineName: 'card',
    status: true,
    required: [],
    props: { properties: {} },
    slots: {},
  };

  const baseArgs = {
    machineName: 'card',
    componentName: 'Card',
    sourceCodeJs: 'export default function Card() {}',
    compiledJs: 'compiled',
    sourceCodeCss: '',
    compiledCss: '',
    importedJsComponents: [] as string[],
    dataDependencies: {},
  };

  it('includes folder when metadata has a non-empty folder string', () => {
    const payload = createComponentPayload({
      ...baseArgs,
      metadata: { ...baseMetadata, folder: 'Marketing' },
    });
    expect((payload as { folder?: string }).folder).toBe('Marketing');
  });

  it('includes folder null when metadata.folder is null', () => {
    const payload = createComponentPayload({
      ...baseArgs,
      metadata: { ...baseMetadata, folder: null },
    });
    expect((payload as { folder?: string | null }).folder).toBeNull();
  });

  it('maps empty string folder to null for clearing', () => {
    const payload = createComponentPayload({
      ...baseArgs,
      metadata: { ...baseMetadata, folder: '   ' },
    });
    expect((payload as { folder?: string | null }).folder).toBeNull();
  });

  it('omits folder when metadata has no folder key', () => {
    const payload = createComponentPayload({
      ...baseArgs,
      metadata: { ...baseMetadata },
    });
    expect(Object.prototype.hasOwnProperty.call(payload, 'folder')).toBe(false);
  });

  it('passes through folder object for library and code', () => {
    const payload = createComponentPayload({
      ...baseArgs,
      metadata: {
        ...baseMetadata,
        folder: { library: 'Banners', code: 'UI Components' },
      },
    });
    expect((payload as { folder?: unknown }).folder).toEqual({
      library: 'Banners',
      code: 'UI Components',
    });
  });

  it('passes partial folder object with null code', () => {
    const payload = createComponentPayload({
      ...baseArgs,
      metadata: {
        ...baseMetadata,
        folder: { library: 'OnlyLib', code: null },
      },
    });
    expect((payload as { folder?: unknown }).folder).toEqual({
      library: 'OnlyLib',
      code: null,
    });
  });
});
