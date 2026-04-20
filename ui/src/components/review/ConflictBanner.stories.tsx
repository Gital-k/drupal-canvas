import { fn } from '@storybook/test';

import ConflictBanner from './ConflictBanner';

import type { Meta, StoryObj } from '@storybook/react';

const meta = {
  title: 'Components/Review/ConflictBanner',
  component: ConflictBanner,
  parameters: {
    layout: 'padded',
    docs: {
      description: {
        component: `
ConflictBanner displays a warning message when there are conflicts in unpublished changes.
It shows the number of conflicts and provides a button to resolve them.

**Static UI Implementation:**
This component is currently showing static/mock data for visual demonstration.
The backend developer will integrate real conflict detection logic.

**Dev Mode Gated:**
This component only appears when \`canvas_dev_mode\` is enabled.

**Design:**
- Light cream/yellow background (#FEF9E7)
- Red warning icon and text
- Light blue button with blue text
- Matches Figma designs exactly
        `,
      },
    },
  },
  tags: ['autodocs'],
  argTypes: {
    conflictCount: {
      control: { type: 'number', min: 0, max: 20 },
      description: 'Number of conflicts detected',
      table: {
        type: { summary: 'number' },
        defaultValue: { summary: '0' },
      },
    },
    onResolveClick: {
      description: 'Callback function when the resolve button is clicked',
      table: {
        type: { summary: '() => void' },
      },
    },
    disabled: {
      control: 'boolean',
      description: 'Whether the resolve button is disabled',
      table: {
        type: { summary: 'boolean' },
        defaultValue: { summary: 'false' },
      },
    },
  },
  args: {
    onResolveClick: fn(),
  },
} satisfies Meta<typeof ConflictBanner>;

export default meta;
type Story = StoryObj<typeof meta>;

/**
 * Single conflict scenario
 */
export const SingleConflict: Story = {
  args: {
    conflictCount: 1,
    disabled: false,
  },
  parameters: {
    docs: {
      description: {
        story:
          'Shows the banner with a single conflict. Note the singular "conflict" text.',
      },
    },
  },
};

/**
 * Multiple conflicts (matches static UI mock)
 */
export const FourConflicts: Story = {
  args: {
    conflictCount: 4,
    disabled: false,
  },
  parameters: {
    docs: {
      description: {
        story:
          'Default static UI state showing 4 conflicts. This matches the mock data used in the implementation.',
      },
    },
  },
};

/**
 * Three conflicts (from Figma design)
 */
export const ThreeConflicts: Story = {
  args: {
    conflictCount: 3,
    disabled: false,
  },
  parameters: {
    docs: {
      description: {
        story: 'Shows 3 conflicts as seen in the Figma design screenshots.',
      },
    },
  },
};

/**
 * Many conflicts
 */
export const ManyConflicts: Story = {
  args: {
    conflictCount: 12,
    disabled: false,
  },
  parameters: {
    docs: {
      description: {
        story:
          'Shows the banner with many conflicts to test text wrapping and layout.',
      },
    },
  },
};

/**
 * Disabled state
 */
export const Disabled: Story = {
  args: {
    conflictCount: 4,
    disabled: true,
  },
  parameters: {
    docs: {
      description: {
        story:
          'Shows the disabled state when the system is busy (publishing, fetching, etc.).',
      },
    },
  },
};

/**
 * No conflicts (banner hidden)
 */
export const NoConflicts: Story = {
  args: {
    conflictCount: 0,
    disabled: false,
  },
  parameters: {
    docs: {
      description: {
        story:
          'When there are no conflicts, the banner does not render. This story will appear empty.',
      },
    },
  },
};

/**
 * Interactive example
 */
export const Interactive: Story = {
  args: {
    conflictCount: 4,
    disabled: false,
  },
  parameters: {
    docs: {
      description: {
        story:
          'Interactive example - click the button to see the console log. Check your browser console.',
      },
    },
  },
};
