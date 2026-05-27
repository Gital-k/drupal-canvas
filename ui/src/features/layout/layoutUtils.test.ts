import { describe, expect, it } from 'vitest';

import { NodeType } from '@/features/layout/layoutModelSlice';
import { getDisplayNameForNode } from '@/features/layout/layoutUtils';

import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import type { ComponentsList } from '@/types/Component';

// Minimal mock — `getDisplayNameForNode` only reads `.name` and
// `metadata.slots[...]` off the registered component, so we don't need to
// satisfy the full BaseComponent shape.
const componentsRegistry = {
  'test_module:section_wrapper': {
    name: 'Section Wrapper',
    metadata: {
      slots: {
        content: { title: 'Section content' },
      },
    },
  },
} as unknown as ComponentsList;

function makeComponentNode(
  overrides: Partial<ComponentNode> = {},
): ComponentNode {
  return {
    nodeType: NodeType.Component,
    uuid: 'uuid-1',
    type: 'test_module:section_wrapper',
    slots: [],
    ...overrides,
  };
}

describe('getDisplayNameForNode', () => {
  describe('ComponentNode', () => {
    it('returns the instance name when set (issue #3527952)', () => {
      const node = makeComponentNode({ name: 'Mission section' });
      expect(getDisplayNameForNode(node, null, componentsRegistry)).toBe(
        'Mission section',
      );
    });

    it('falls back to component-type name when name is undefined', () => {
      const node = makeComponentNode(); // no name
      expect(getDisplayNameForNode(node, null, componentsRegistry)).toBe(
        'Section Wrapper',
      );
    });

    it('falls back to component-type name when name is null', () => {
      const node = makeComponentNode({ name: null });
      expect(getDisplayNameForNode(node, null, componentsRegistry)).toBe(
        'Section Wrapper',
      );
    });

    it('falls back to component-type name when name is an empty string', () => {
      const node = makeComponentNode({ name: '' });
      expect(getDisplayNameForNode(node, null, componentsRegistry)).toBe(
        'Section Wrapper',
      );
    });

    it('falls back to "Component" when the component is not in the registry', () => {
      const node = makeComponentNode({ type: 'not_a_real:component' });
      expect(getDisplayNameForNode(node, null, componentsRegistry)).toBe(
        'Component',
      );
    });

    it('strips the @version suffix from the component type when resolving', () => {
      const node = makeComponentNode({
        type: 'test_module:section_wrapper@abc123',
      });
      expect(getDisplayNameForNode(node, null, componentsRegistry)).toBe(
        'Section Wrapper',
      );
    });
  });

  describe('SlotNode (regression guard — instance-name change must not leak into slot path)', () => {
    const parent = makeComponentNode({ name: 'Mission section' });

    it('returns the slot title from the parent component definition when available', () => {
      const slot: SlotNode = {
        nodeType: NodeType.Slot,
        id: 'slot-1',
        name: 'content',
        components: [],
      };
      expect(getDisplayNameForNode(slot, parent, componentsRegistry)).toBe(
        'Section content',
      );
    });

    it("returns the slot's own name when the parent component is not in the registry", () => {
      const slot: SlotNode = {
        nodeType: NodeType.Slot,
        id: 'slot-1',
        name: 'cta',
        components: [],
      };
      expect(getDisplayNameForNode(slot, parent, componentsRegistry)).toBe(
        'cta',
      );
    });
  });

  it('returns an empty string for a null node', () => {
    expect(getDisplayNameForNode(null, null, componentsRegistry)).toBe('');
  });
});
