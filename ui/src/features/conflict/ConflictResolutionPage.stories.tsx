import { useMemo } from 'react';
import { Provider } from 'react-redux';
import { configureStore } from '@reduxjs/toolkit';
import { fn } from '@storybook/test';

import { uiSliceReducer } from '@/features/ui/uiSlice';

import {
  ConflictResolutionView,
  PageConflictComparisonView,
} from './ConflictResolutionView';

import type React from 'react';
import type { Meta, StoryObj } from '@storybook/react';

const publishedPageHtml = `
  <!doctype html>
  <html>
    <head>
      <style>
        body { margin: 0; font-family: Inter, system-ui, sans-serif; color: #24262f; }
        main { padding: 48px 64px; }
        .hero { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 0.8fr); gap: 32px; align-items: center; }
        .image { height: 280px; border-radius: 8px; background: linear-gradient(135deg, #d5e8ff, #7ba5b8 46%, #334155); }
        h1 { margin: 0 0 16px; font-size: 40px; line-height: 1.05; }
        p { margin: 0; color: #6b7280; font-size: 18px; line-height: 1.6; }
      </style>
    </head>
    <body>
      <main>
        <section class="region-content hero">
          <div class="image"></div>
          <div>
            <h1>Welcome to our site</h1>
            <p>This is the currently published page content.</p>
          </div>
        </section>
      </main>
    </body>
  </html>
`;

const newPageHtml = `
  <!doctype html>
  <html>
    <head>
      <style>
        body { margin: 0; font-family: Inter, system-ui, sans-serif; color: #24262f; }
        main { padding: 48px 64px; }
        .hero { display: grid; grid-template-columns: minmax(0, 0.8fr) minmax(0, 1fr); gap: 32px; align-items: center; }
        .image { height: 280px; border-radius: 8px; background: linear-gradient(135deg, #ffe8b5, #d48b40 48%, #1f2937); }
        h1 { margin: 0 0 16px; font-size: 40px; line-height: 1.05; }
        p { margin: 0; color: #6b7280; font-size: 18px; line-height: 1.6; }
      </style>
    </head>
    <body>
      <main>
        <section class="region-content hero">
          <div class="image"></div>
          <div>
            <h1>Welcome to our new site</h1>
            <p>This is the conflicting auto-saved version.</p>
          </div>
        </section>
      </main>
    </body>
  </html>
`;

const publishedVersion = {
  html: publishedPageHtml,
  props: {
    entity_form_fields: {
      title: 'Homepage',
      status: true,
    },
    layout: [{ uuid: 'published-hero', type: 'example:hero' }],
    model: {
      'published-hero': {
        title: 'Welcome to our site',
      },
    },
  },
};

const newVersion = {
  html: newPageHtml,
  props: {
    entity_form_fields: {
      title: 'Homepage',
      status: true,
    },
    layout: [{ uuid: 'new-hero', type: 'example:hero' }],
    model: {
      'new-hero': {
        title: 'Welcome to our new site',
      },
    },
  },
  changed: true,
};

const createStoryStore = () =>
  configureStore({
    reducer: {
      ui: uiSliceReducer,
    },
  });

const StoryFrame = ({ children }: { children: React.ReactNode }) => {
  const store = useMemo(() => createStoryStore(), []);

  return (
    <Provider store={store}>
      <div style={{ width: '100%', height: '760px', background: '#fff' }}>
        {children}
      </div>
    </Provider>
  );
};

const PageComparison = ({ loading = false }: { loading?: boolean }) => (
  <PageConflictComparisonView
    publishedVersion={{ ...publishedVersion, loading }}
    newVersion={{ ...newVersion, loading }}
  />
);

const meta = {
  title: 'Features/Conflict Resolution/Page Comparison',
  component: ConflictResolutionView,
  parameters: {
    layout: 'fullscreen',
    docs: {
      description: {
        component: `
The Page conflict comparison screen is available in development mode for conflicting \`canvas_page\` changes.

It compares the published response from \`GET /canvas/api/v0/layout/canvas_page/{id}?status=true\` with the auto-saved response from \`GET /canvas/api/v0/layout/canvas_page/{id}\`.

The user can select **Use published version** to discard the conflicting auto-save, or **Use new version** to resolve the active conflict by sending \`conflictToResolve\` while keeping the auto-saved changes.
        `,
      },
    },
  },
  args: {
    label: 'Homepage',
    comparison: <PageComparison />,
    currentIndex: 0,
    reviewIndex: 0,
    reviewTotal: 1,
    unresolvedTotal: 1,
    isDiscarding: false,
    isResolving: false,
    onPrevious: fn(),
    onNext: fn(),
    onUsePublishedVersion: fn(),
    onUseNewVersion: fn(),
    onClose: fn(),
  },
  argTypes: {
    comparison: {
      control: false,
      description: 'PageConflictComparisonView content for both Page versions.',
    },
    label: {
      control: 'text',
      description: 'Label of the conflicted Page.',
    },
    currentIndex: {
      control: 'number',
      description: 'Zero-based position in the unresolved Page conflict queue.',
    },
    reviewIndex: {
      control: 'number',
      description: 'Zero-based position displayed in the footer.',
    },
    reviewTotal: {
      control: 'number',
      description: 'Number of conflicts in the initial review queue.',
    },
    unresolvedTotal: {
      control: 'number',
      description: 'Number of unresolved Page conflicts remaining.',
    },
    isDiscarding: {
      control: 'boolean',
      description:
        'Whether the Use published version discard request is in progress.',
    },
    isResolving: {
      control: 'boolean',
      description:
        'Whether the Use new version resolve request is in progress.',
    },
  },
} satisfies Meta<typeof ConflictResolutionView>;

export default meta;

type Story = StoryObj<typeof meta>;

export const PageConflict: Story = {
  render: (args) => (
    <StoryFrame>
      <ConflictResolutionView {...args} comparison={<PageComparison />} />
    </StoryFrame>
  ),
};

export const LoadingComparison: Story = {
  render: (args) => (
    <StoryFrame>
      <ConflictResolutionView
        {...args}
        comparison={<PageComparison loading />}
      />
    </StoryFrame>
  ),
};

export const UsingPublishedVersion: Story = {
  args: {
    isDiscarding: true,
  },
  render: (args) => (
    <StoryFrame>
      <ConflictResolutionView {...args} comparison={<PageComparison />} />
    </StoryFrame>
  ),
};

export const UsingNewVersion: Story = {
  args: {
    isResolving: true,
  },
  render: (args) => (
    <StoryFrame>
      <ConflictResolutionView {...args} comparison={<PageComparison />} />
    </StoryFrame>
  ),
};
