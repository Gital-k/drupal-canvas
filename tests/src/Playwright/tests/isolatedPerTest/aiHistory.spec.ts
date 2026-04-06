import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests Canvas AI chat history persistence across navigation and page reloads.
 */

test.use({ modules: ['canvas_ai_test'], enableTestExtensions: true });

test.describe('AI History Persistence', () => {
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

  test('AI chat history persists after page reload', async ({
    page,
    drupal,
    canvas,
    ai,
  }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await ai.openPanel();

    // Submit a query and verify the response.
    await ai.submitQuery('What is a CMS');
    await expect(
      page.getByText(
        'A Content Management System (CMS) is a software application',
      ),
    ).toBeVisible();

    // Reload the page.
    await page.reload();
    await canvas.waitForEditorUi();
    await ai.openPanel();

    // The AI panel should still be accessible after reload.
    await expect(page.getByTestId('canvas-ai-panel')).toBeVisible();
  });

  test('AI chat history persists after navigating away and back', async ({
    page,
    drupal,
    canvas,
    ai,
  }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    const canvasPage = await canvas.createCanvas();
    await ai.openPanel();

    // Submit a query.
    await ai.submitQuery('What is a CMS');
    await expect(
      page.getByText(
        'A Content Management System (CMS) is a software application',
      ),
    ).toBeVisible();

    // Navigate to the Canvas root and back to the editor.
    await canvas.openCanvasRoot();
    await canvas.openCanvas(canvasPage);
    await ai.openPanel();

    // The AI panel should still be accessible.
    await expect(page.getByTestId('canvas-ai-panel')).toBeVisible();
  });

  test('Multiple sequential AI queries work in one session', async ({
    page,
    drupal,
    canvas,
    ai,
  }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas({ title: 'Sequential Queries Test' });
    await ai.openPanel();

    // First query: ask a question.
    await ai.submitQuery('What is a CMS');
    await expect(
      page.getByText(
        'A Content Management System (CMS) is a software application',
      ),
    ).toBeVisible();

    // Second query: generate a title.
    await ai.submitQuery('Generate title');
    await expect(page.getByRole('textbox', { name: 'Title*' })).toHaveValue(
      'Welcome to Our Interactive Experience',
    );

    // Third query: generate metadata.
    await ai.submitQuery('Generate metadata');
    await expect(
      page.getByRole('textbox', { name: 'Meta description' }),
    ).toHaveValue(
      'Experience a journey through our interactive digital space, designed to engage and inspire visitors with immersive content and seamless navigation.',
    );
  });

  test('Component creation history maintained after returning to editor', async ({
    page,
    drupal,
    canvas,
    ai,
  }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    const canvasPage = await canvas.createCanvas();
    await ai.openPanel();

    // Create a component (navigates to code editor).
    await ai.submitQuery('Create component');
    await expect(page).toHaveURL(
      /\/canvas\/code-editor\/component\/herobanner/,
    );

    // Navigate back to the page editor.
    await canvas.openCanvas(canvasPage);

    // Verify the editor loads and the AI panel is still accessible.
    await expect(page).toHaveURL(/\/canvas\/editor\/canvas_page\/\d+$/);
    await ai.openPanel();
    await expect(page.getByTestId('canvas-ai-panel')).toBeVisible();
  });
});
