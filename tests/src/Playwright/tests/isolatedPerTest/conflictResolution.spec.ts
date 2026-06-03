import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page, Route } from '@playwright/test';

type PendingConflict = {
  pointer: string;
  entityId: string;
  label: string;
  dataHash: string;
  conflictId: string;
  updated: number;
};

const CONFLICT_CODE_DETECTED = 4;
const SECOND_CONFLICT_ENTITY_ID = '98765';

test.use({
  modules: ['canvas_dev_mode'],
  enableTestExtensions: true,
});

const fulfillJson = async (
  route: Route,
  status: number,
  body: Record<string, unknown>,
) =>
  route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  });

const getConflictEntityIdFromUrl = (url: string): string | undefined => {
  const match = url.match(/\/canvas_page\/([^/?]+)/);
  return match?.[1];
};

const pendingChangeFor = (conflict: PendingConflict) => ({
  owner: {
    name: 'Editor',
    avatar: null,
    uri: '/user/2',
    id: 2,
  },
  entity_type: 'canvas_page',
  entity_id: conflict.entityId,
  data_hash: conflict.dataHash,
  langcode: 'en',
  label: conflict.label,
  updated: conflict.updated,
});

const pendingResponseFor = (conflicts: PendingConflict[]) => ({
  data: Object.fromEntries(
    conflicts.map((conflict) => [conflict.pointer, pendingChangeFor(conflict)]),
  ),
  errors: conflicts.map((conflict) => ({
    code: CONFLICT_CODE_DETECTED,
    detail: `${conflict.label} has a resolvable conflict.`,
    source: {
      pointer: conflict.pointer,
    },
    meta: {
      entity_type: 'canvas_page',
      entity_id: conflict.entityId,
      label: conflict.label,
      api_auto_save_key: conflict.pointer,
      conflict_id: conflict.conflictId,
    },
  })),
});

const routePendingConflicts = async (
  page: Page,
  getConflicts: () => PendingConflict[],
) => {
  await page.route('**/canvas/api/v0/auto-saves/pending', async (route) => {
    const conflicts = getConflicts();
    await fulfillJson(
      route,
      conflicts.length > 0 ? 409 : 200,
      pendingResponseFor(conflicts),
    );
  });
};

const routeConflictLayouts = async (page: Page) => {
  await page.route('**/canvas/api/v0/layout/canvas_page/*', async (route) => {
    const url = new URL(route.request().url());
    const entityId = url.pathname.split('/').at(-1) ?? '';
    const isPublished = url.searchParams.get('autoSaved') === 'false';
    const versionLabel = isPublished ? 'Published' : 'New';

    await fulfillJson(route, 200, {
      html: `<!doctype html><html><body><main><h1>${versionLabel} page ${entityId} content</h1></main></body></html>`,
      layout: [],
      model: {},
      entity_form_fields: {},
      isNew: false,
      isPublished,
      autoSaves: {},
      translations: {},
      updated: isPublished ? 1_777_777_777 : 1_777_888_888,
    });
  });
};

test.describe('Conflict resolution', () => {
  test('shows conflicted changes and resolves both version choices', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas({
      title: 'Conflict resolution page',
    });
    const currentEntityId = String(canvasPage.entity_id);
    const futureTimestamp = Math.floor(Date.now() / 1000) + 60;
    let conflicts: PendingConflict[] = [
      {
        pointer: `canvas_page:${currentEntityId}:en`,
        entityId: currentEntityId,
        label: 'Conflict resolution page',
        dataHash: 'current-conflict-hash',
        conflictId: 'current-conflict-revision',
        updated: futureTimestamp,
      },
      {
        pointer: `canvas_page:${SECOND_CONFLICT_ENTITY_ID}:en`,
        entityId: SECOND_CONFLICT_ENTITY_ID,
        label: 'Queued conflict page',
        dataHash: 'queued-conflict-hash',
        conflictId: 'queued-conflict-revision',
        updated: futureTimestamp - 1,
      },
    ];
    const resolveRequests: Array<{
      entityId: string | undefined;
      body: unknown;
    }> = [];
    const discardRequests: Array<{ entityId: string | undefined }> = [];

    await routePendingConflicts(page, () => conflicts);

    await page.route(
      '**/canvas/api/v0/content/auto-save/canvas_page/*',
      async (route) => {
        const entityId = getConflictEntityIdFromUrl(route.request().url());
        resolveRequests.push({
          entityId,
          body: route.request().postDataJSON(),
        });
        conflicts = conflicts.filter(
          (conflict) => conflict.entityId !== entityId,
        );
        await fulfillJson(route, 200, { message: 'Conflict resolved.' });
      },
    );

    await page.route(
      '**/canvas/api/v0/auto-saves/canvas_page/*',
      async (route) => {
        const entityId = getConflictEntityIdFromUrl(route.request().url());
        discardRequests.push({ entityId });
        conflicts = conflicts.filter(
          (conflict) => conflict.entityId !== entityId,
        );
        await fulfillJson(route, 200, { message: 'Conflict discarded.' });
      },
    );

    await canvas.openCanvas(canvasPage);

    await expect(
      page.getByRole('button', { name: 'Resolve conflicts', exact: true }),
    ).toBeVisible();

    await page.getByTestId('canvas-publish-review').click();
    const review = page.getByTestId('canvas-publish-reviews-content');

    await expect(review.getByTestId('conflict-banner')).toContainText(
      '2 conflicts to resolve',
    );
    await expect(review.getByText('0 of 2 changes selected')).toBeVisible();
    await expect(
      review.getByLabel('Select change Conflict resolution page'),
    ).toBeDisabled();
    await expect(
      review.getByLabel('Select change Queued conflict page'),
    ).toBeDisabled();
    await expect(review.getByTestId('change-conflict-icon')).toHaveCount(2);

    const conflictRow = review
      .getByTestId('pending-change-row')
      .filter({ hasText: 'Conflict resolution page' });
    await conflictRow.getByRole('button', { name: 'More options' }).click();
    const resolveConflictMenuItem = page.getByRole('menuitem', {
      name: 'Resolve conflict',
    });
    await expect(resolveConflictMenuItem).toBeVisible();

    await routeConflictLayouts(page);

    await resolveConflictMenuItem.click();

    await expect(page).toHaveURL(
      new RegExp(`/canvas/conflict/canvas_page/${currentEntityId}$`),
    );

    const resolver = page.getByTestId('conflict-resolution-page');
    const resolveConflictButton = resolver.getByRole('button', {
      name: 'Resolve conflict',
      exact: true,
    });

    await expect(resolver).toBeVisible();
    await expect(page.getByText('Review 1 of 2')).toBeVisible();
    await expect(page.getByText('Published version').first()).toBeVisible();
    await expect(page.getByText('New version').first()).toBeVisible();
    await expect(
      page.getByText(/Updated \d{1,2}\/\d{1,2}\/\d{2} at/).first(),
    ).toBeVisible();
    await expect(resolveConflictButton).toBeDisabled();

    await page.getByRole('tab', { name: 'Text' }).click();
    await expect(
      page.getByText(`Published page ${currentEntityId} content`),
    ).toBeVisible();
    await expect(
      page.getByText(`New page ${currentEntityId} content`),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Select New version' }).click();
    await resolveConflictButton.click();

    await expect(async () => {
      expect(resolveRequests).toEqual([
        expect.objectContaining({
          entityId: currentEntityId,
          body: expect.objectContaining({
            conflictToResolve: 'current-conflict-revision',
          }),
        }),
      ]);
    }).toPass();

    await expect(page).toHaveURL(
      new RegExp(`/canvas/conflict/canvas_page/${SECOND_CONFLICT_ENTITY_ID}$`),
    );
    await expect(page.getByText('Review 2 of 2')).toBeVisible();
    await expect(resolveConflictButton).toBeDisabled();

    await page
      .getByRole('button', { name: 'Select Published version' })
      .click();
    await resolveConflictButton.click();

    await expect(async () => {
      expect(discardRequests).toEqual([
        { entityId: SECOND_CONFLICT_ENTITY_ID },
      ]);
    }).toPass();

    await expect(page).toHaveURL(/\/canvas\/conflict$/);
    await expect(page.getByText('Everything is up to date')).toBeVisible();
  });
});
