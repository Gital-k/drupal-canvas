import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests Canvas AI page builder task features: question answering,
 * metadata generation, and title generation in context.
 */

test.use({ modules: ['canvas_ai_test'], enableTestExtensions: true });

test.describe('AI Page Builder Tasks', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.createRole({ name: 'ai_editor' });
    await drupal.createUser({
      email: `ai_editor@example.com`,
      username: 'ai_editor',
      password: 'ai_editor',
      roles: ['ai_editor'],
    });
    await drupal.addPermissions({
      role: 'ai_editor',
      permissions: [
        'create canvas_page',
        'edit canvas_page',
        'publish auto-saves',
        'administer code components',
        'use drupal canvas ai',
        'create media',
      ],
    });
    await drupal.logout();
  });

  test('AI can answer questions about CMS', async ({
    page,
    drupal,
    canvas,
    ai,
  }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await ai.openPanel();
    await ai.submitQuery('What is a CMS');

    // Verify the mocked AI response text appears in the panel.
    await expect(
      page.getByText(
        'A Content Management System (CMS) is a software application',
      ),
    ).toBeVisible();
  });

  test('AI generates metadata and title in same session', async ({
    page,
    drupal,
    canvas,
    ai,
  }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas({ title: 'Session Test Page' });
    await ai.openPanel();

    // Generate metadata first.
    await ai.submitQuery('Generate metadata');
    await expect(
      page.getByRole('textbox', { name: 'Meta description' }),
    ).toHaveValue(
      'Experience a journey through our interactive digital space, designed to engage and inspire visitors with immersive content and seamless navigation.',
    );

    // Then generate title in the same session.
    await ai.submitQuery('Generate title');
    await expect(page.getByRole('textbox', { name: 'Title*' })).toHaveValue(
      'Welcome to Our Interactive Experience',
    );
  });
});
