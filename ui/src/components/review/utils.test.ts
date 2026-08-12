import { describe, expect, it, vi } from 'vitest';

import { FallbackColor } from '@/types/Review';

import { getAvatarInitialColor } from './utils';

import type { Owner } from '@/services/pendingChangesApi';

const makeOwner = (overrides: Partial<Owner> = {}): Owner => ({
  name: 'Garrett Bobby Ferguson, Sr',
  avatar: null,
  uri: '/user/13',
  id: 13,
  ...overrides,
});

describe('getAvatarInitialColor', () => {
  const palette = Object.values(FallbackColor);

  it('always returns a color from the palette', () => {
    for (let id = 0; id <= 50; id++) {
      const owner = makeOwner({ id, uri: `/user/${id}`, name: `User ${id}` });
      expect(palette).toContain(getAvatarInitialColor(owner));
    }
  });

  it('returns the same color for the same owner', () => {
    expect(getAvatarInitialColor(makeOwner())).toBe(
      getAvatarInitialColor(makeOwner()),
    );
  });

  it('does not depend on the order owners are encountered in', () => {
    const owners = [13, 14, 15].map((id) =>
      makeOwner({ id, uri: `/user/${id}` }),
    );

    const ascending = owners.map(getAvatarInitialColor);
    const descending = [...owners].reverse().map(getAvatarInitialColor);

    expect(ascending).toEqual([...descending].reverse());
  });

  it('does not depend on the key order of the decoded JSON', () => {
    const owner = makeOwner();
    const reordered = {
      id: owner.id,
      uri: owner.uri,
      avatar: owner.avatar,
      name: owner.name,
    };

    expect(getAvatarInitialColor(reordered)).toBe(getAvatarInitialColor(owner));
  });

  // The palette is smaller than the number of users a site can have, so two
  // owners can legitimately share a color. These two do not.
  it('tells the two authors from the issue report apart', () => {
    const garrettSenior = makeOwner();
    const garrettJunior = makeOwner({
      name: 'Garrett Bobby Ferguson, Jr',
      uri: '/user/12',
      id: 12,
    });

    expect(getAvatarInitialColor(garrettSenior)).not.toBe(
      getAvatarInitialColor(garrettJunior),
    );
  });

  // Regression test for https://www.drupal.org/i/3536227: colors used to be
  // handed out from a randomly seeded index, so the same author was shown a
  // different color on every page load and in every browser.
  it('assigns an owner the same color in a freshly loaded module', async () => {
    const colorsPerLoad: FallbackColor[] = [];

    for (const randomValue of [0, 0.35, 0.99]) {
      vi.resetModules();
      vi.spyOn(Math, 'random').mockReturnValue(randomValue);

      const { getAvatarInitialColor: freshlyLoaded } = await import('./utils');
      colorsPerLoad.push(freshlyLoaded(makeOwner()));

      vi.mocked(Math.random).mockRestore();
    }

    expect(new Set(colorsPerLoad).size).toBe(1);
  });
});
