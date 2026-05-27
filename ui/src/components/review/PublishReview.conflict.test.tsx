import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';

import PublishReview from './PublishReview';

import type { UnpublishedChange } from '@/types/Review';

vi.mock('@/utils/drupal-globals', () => ({
  getDrupal: () => undefined,
  getDrupalSettings: () => ({ canvas: { loginUrl: '/user/login' } }),
  getCanvasSettings: () => ({ devMode: true }),
  getBaseUrl: () => '/',
  getCanvasPermissions: () => ({ publishChanges: true }),
  getCanvasModuleBaseUrl: () => '/modules/contrib/canvas/',
  setCanvasDrupalSetting: vi.fn(),
}));

const owner = {
  name: 'Builder',
  avatar: null,
  uri: '/user/1',
  id: 1,
};

const makeChange = (
  pointer: string,
  hasConflict = false,
): UnpublishedChange => ({
  pointer,
  owner,
  entity_type: 'canvas_page',
  entity_id: pointer,
  data_hash: pointer,
  langcode: 'en',
  label: pointer,
  updated: 100,
  hasConflict,
});

describe('PublishReview conflict selection', () => {
  it('does not submit a conflicted Page when selecting all publishable changes', async () => {
    const user = userEvent.setup();
    const onPublishClick = vi.fn();
    const cleanChange = makeChange('clean');
    const store = makeStore();

    render(
      <AppWrapper store={store} location="/" path="*">
        <PublishReview
          changes={[cleanChange, makeChange('conflicted', true)]}
          onPublishClick={onPublishClick}
          onDiscardClick={vi.fn()}
          onOpenChangeCallback={vi.fn()}
          isPublishing={false}
          isDiscarding={false}
          isUpdating={false}
        />
      </AppWrapper>,
    );

    await user.click(screen.getByTestId('canvas-publish-review'));
    await user.click(screen.getByTestId('canvas-publish-review-select-all'));
    await user.click(
      screen.getByRole('button', { name: 'Publish 1 selected' }),
    );

    expect(onPublishClick).toHaveBeenCalledWith([cleanChange]);
  });
});
