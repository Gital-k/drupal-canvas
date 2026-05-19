import { describe, expect, it } from 'vitest';

import { NodeType } from '@/features/layout/layoutModelSlice';
import {
  getComponentTypeCount,
  getComponentTypeIndex,
  getDisplayNameForNode,
} from '@/features/layout/layoutUtils';

import type {
  ComponentNode,
  RegionNode,
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

// Auto-numbering: when the full layout is passed, the unnamed-component
// fallback gets a per-page positional index appended. See #3527952.

function makeSectionWrapper(uuid: string): ComponentNode {
  return {
    nodeType: NodeType.Component,
    uuid,
    type: 'test_module:section_wrapper',
    slots: [],
  };
}

function makeHero(uuid: string): ComponentNode {
  return {
    nodeType: NodeType.Component,
    uuid,
    type: 'test_module:hero',
    slots: [],
  };
}

function makeRegion(components: ComponentNode[]): RegionNode {
  return {
    nodeType: NodeType.Region,
    name: 'main',
    id: 'main',
    components,
  };
}

describe('getComponentTypeIndex', () => {
  it('returns 1-based position among same-type siblings in a flat layout', () => {
    const layout = [
      makeRegion([
        makeSectionWrapper('a'),
        makeSectionWrapper('b'),
        makeSectionWrapper('c'),
      ]),
    ];
    expect(
      getComponentTypeIndex(layout, 'a', 'test_module:section_wrapper'),
    ).toBe(1);
    expect(
      getComponentTypeIndex(layout, 'b', 'test_module:section_wrapper'),
    ).toBe(2);
    expect(
      getComponentTypeIndex(layout, 'c', 'test_module:section_wrapper'),
    ).toBe(3);
  });

  it('counts only components of the same type (ignores other types)', () => {
    const layout = [
      makeRegion([
        makeSectionWrapper('a'),
        makeHero('h1'),
        makeSectionWrapper('b'),
        makeHero('h2'),
        makeSectionWrapper('c'),
      ]),
    ];
    expect(
      getComponentTypeIndex(layout, 'b', 'test_module:section_wrapper'),
    ).toBe(2);
    expect(getComponentTypeIndex(layout, 'h2', 'test_module:hero')).toBe(2);
  });

  it('counts across regions (per-page scope, not per-region)', () => {
    const layout = [
      makeRegion([makeSectionWrapper('a'), makeSectionWrapper('b')]),
      makeRegion([makeSectionWrapper('c')]),
    ];
    expect(
      getComponentTypeIndex(layout, 'c', 'test_module:section_wrapper'),
    ).toBe(3);
  });

  it('descends into slots and counts nested components', () => {
    const inner = makeSectionWrapper('inner');
    const outer: ComponentNode = {
      nodeType: NodeType.Component,
      uuid: 'outer',
      type: 'test_module:section_wrapper',
      slots: [
        {
          nodeType: NodeType.Slot,
          id: 'content',
          name: 'content',
          components: [inner],
        },
      ],
    };
    const layout = [makeRegion([outer])];
    expect(
      getComponentTypeIndex(layout, 'outer', 'test_module:section_wrapper'),
    ).toBe(1);
    expect(
      getComponentTypeIndex(layout, 'inner', 'test_module:section_wrapper'),
    ).toBe(2);
  });

  it('strips an @version suffix from the requested type before matching', () => {
    const layout = [
      makeRegion([makeSectionWrapper('a'), makeSectionWrapper('b')]),
    ];
    expect(
      getComponentTypeIndex(layout, 'b', 'test_module:section_wrapper@abc123'),
    ).toBe(2);
  });

  it('does not renumber when a sibling has a custom name (stable positions)', () => {
    const layout = [
      makeRegion([
        makeSectionWrapper('a'),
        { ...makeSectionWrapper('b'), name: 'Mission' },
        makeSectionWrapper('c'),
      ]),
    ];
    expect(
      getComponentTypeIndex(layout, 'a', 'test_module:section_wrapper'),
    ).toBe(1);
    expect(
      getComponentTypeIndex(layout, 'c', 'test_module:section_wrapper'),
    ).toBe(3);
  });

  it('returns 0 when the uuid is not found', () => {
    const layout = [makeRegion([makeSectionWrapper('a')])];
    expect(
      getComponentTypeIndex(
        layout,
        'no-such-uuid',
        'test_module:section_wrapper',
      ),
    ).toBe(0);
  });

  it('returns 0 against an empty layout', () => {
    expect(getComponentTypeIndex([], 'a', 'test_module:section_wrapper')).toBe(
      0,
    );
  });
});

describe('getDisplayNameForNode with layout (auto-numbered fallback)', () => {
  it('appends the per-page index to the type-name fallback', () => {
    const a = makeSectionWrapper('a');
    const b = makeSectionWrapper('b');
    const c = makeSectionWrapper('c');
    const layout = [makeRegion([a, b, c])];

    expect(getDisplayNameForNode(a, null, componentsRegistry, layout)).toBe(
      'Section Wrapper 1',
    );
    expect(getDisplayNameForNode(b, null, componentsRegistry, layout)).toBe(
      'Section Wrapper 2',
    );
    expect(getDisplayNameForNode(c, null, componentsRegistry, layout)).toBe(
      'Section Wrapper 3',
    );
  });

  it('returns the instance name (no index) when a custom name is set', () => {
    const a = makeSectionWrapper('a');
    const b = { ...makeSectionWrapper('b'), name: 'Mission' };
    const c = makeSectionWrapper('c');
    const layout = [makeRegion([a, b, c])];

    expect(getDisplayNameForNode(b, null, componentsRegistry, layout)).toBe(
      'Mission',
    );
  });

  it('still appends the position-based index to unnamed siblings of a named one', () => {
    const a = makeSectionWrapper('a');
    const b = { ...makeSectionWrapper('b'), name: 'Mission' };
    const c = makeSectionWrapper('c');
    const layout = [makeRegion([a, b, c])];

    // Unnamed siblings keep their position-based numbers — they do not renumber
    // around the named one.
    expect(getDisplayNameForNode(a, null, componentsRegistry, layout)).toBe(
      'Section Wrapper 1',
    );
    expect(getDisplayNameForNode(c, null, componentsRegistry, layout)).toBe(
      'Section Wrapper 3',
    );
  });

  it('omits the index when no layout is provided (backward-compat fallback)', () => {
    const a = makeSectionWrapper('a');
    expect(getDisplayNameForNode(a, null, componentsRegistry)).toBe(
      'Section Wrapper',
    );
  });

  it('omits the index when the node is not in the provided layout', () => {
    const orphan = makeSectionWrapper('orphan');
    const layout = [makeRegion([makeSectionWrapper('a')])];
    expect(
      getDisplayNameForNode(orphan, null, componentsRegistry, layout),
    ).toBe('Section Wrapper');
  });

  it('omits the index when only one instance of the type exists on the page', () => {
    // A lone instance shows the bare type name; the disambiguating number
    // appears only when a second sibling of the same type is added.
    const solo = makeSectionWrapper('only-one');
    const layout = [makeRegion([solo, makeHero('h')])];
    expect(getDisplayNameForNode(solo, null, componentsRegistry, layout)).toBe(
      'Section Wrapper',
    );
  });
});

describe('getComponentTypeCount', () => {
  it('returns 0 for a type that does not appear in the layout', () => {
    const layout = [makeRegion([makeHero('h')])];
    expect(getComponentTypeCount(layout, 'test_module:section_wrapper')).toBe(
      0,
    );
  });

  it('returns 1 for a solo instance', () => {
    const layout = [makeRegion([makeSectionWrapper('only-one')])];
    expect(getComponentTypeCount(layout, 'test_module:section_wrapper')).toBe(
      1,
    );
  });

  it('counts all instances across regions and nested slots', () => {
    const inner = makeSectionWrapper('inner');
    const outer: ComponentNode = {
      nodeType: NodeType.Component,
      uuid: 'outer',
      type: 'test_module:section_wrapper',
      slots: [
        {
          nodeType: NodeType.Slot,
          id: 'content',
          name: 'content',
          components: [inner],
        },
      ],
    };
    const layout = [
      makeRegion([outer, makeSectionWrapper('top-level')]),
      makeRegion([makeSectionWrapper('side')]),
    ];
    expect(getComponentTypeCount(layout, 'test_module:section_wrapper')).toBe(
      4,
    );
  });

  it('strips an @version suffix from the requested type before matching', () => {
    const layout = [
      makeRegion([makeSectionWrapper('a'), makeSectionWrapper('b')]),
    ];
    expect(
      getComponentTypeCount(layout, 'test_module:section_wrapper@abc123'),
    ).toBe(2);
  });
});
