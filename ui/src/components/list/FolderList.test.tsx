import { describe, expect, it } from 'vitest';

import { folderfyComponents } from '@/components/list/FolderList';

const component = (id: string) => ({ id, name: id });

const foldersFixture = {
  folders: {
    'uuid-visible': {
      name: 'Base',
      weight: 0,
      type: 'component',
      items: ['heading'],
    },
    'uuid-all-disabled': {
      name: 'Forms',
      weight: 0,
      type: 'component',
      items: ['search_form'],
    },
    'uuid-user-made': {
      name: 'My folder',
      weight: 0,
      type: 'component',
      items: [],
    },
  },
  componentIndexedFolders: {
    heading: 'uuid-visible',
    search_form: 'uuid-all-disabled',
  },
};

describe('folderfyComponents', () => {
  it('hides folders whose items are all filtered out, keeps deliberately empty ones', () => {
    // `search_form` is absent: the Components endpoint filters out disabled
    // Components, so its folder has nothing the user can see.
    const { folderComponents, topLevelComponents } = folderfyComponents(
      { heading: component('heading') } as any,
      foldersFixture,
      false,
      false,
      'component',
    );

    expect(Object.keys(folderComponents).sort()).toEqual([
      'uuid-user-made',
      'uuid-visible',
    ]);
    expect(folderComponents['uuid-all-disabled']).toBeUndefined();
    expect(topLevelComponents).toEqual({});
  });

  it('keeps a folder as soon as one of its items is visible', () => {
    const { folderComponents } = folderfyComponents(
      {
        heading: component('heading'),
        search_form: component('search_form'),
      } as any,
      foldersFixture,
      false,
      false,
      'component',
    );

    expect(folderComponents['uuid-all-disabled'].items).toHaveProperty(
      'search_form',
    );
  });

  it('ignores folders of a different type', () => {
    const { folderComponents } = folderfyComponents(
      {},
      foldersFixture,
      false,
      false,
      'pattern',
    );

    expect(folderComponents).toEqual({});
  });
});
