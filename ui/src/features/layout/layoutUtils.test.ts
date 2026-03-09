// @cspell:ignore idontexist
import { beforeEach, describe, it } from 'vitest';
import layoutFixture from '@tests/fixtures/layout-default.json';
import regionsLayoutFixture from '@tests/fixtures/layout-regions.json';

import { isSlotNode, NodeType } from '@/features/layout/layoutModelSlice';
import {
  areConsecutiveSiblings,
  componentExistsInLayout,
  findParent,
  findParentInfo,
  findParentRegion,
  findSiblings,
  getNodeAtPath,
  isChildNode,
  replaceUUIDsAndUpdateModel,
} from '@/features/layout/layoutUtils';

import type {
  ComponentNode,
  LayoutNode,
  RegionNode,
  RootLayoutModel,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import type { ParentInfo } from '@/features/layout/layoutUtils';

const sampleLayout: Array<RegionNode> = [
  {
    nodeType: NodeType.Region,
    components: [
      {
        nodeType: NodeType.Component,
        uuid: 'static-static-card1ab',
        type: 'sdc_test:my-cta',
        slots: [
          {
            id: 'static-static-card1ab/column_one',
            name: 'column_one',
            nodeType: NodeType.Slot,
            components: [
              {
                nodeType: NodeType.Component,
                uuid: '4f0fbf7c-64ee-4358-bc76-8f34e1cbdc3f',
                type: 'sdc_test:ponies',
                slots: [
                  {
                    id: '4f0fbf7c-64ee-4358-bc76-8f34e1cbdc3f/bridle',
                    name: 'bridle',
                    nodeType: NodeType.Slot,
                    components: [],
                  } as SlotNode,
                  {
                    id: '4f0fbf7c-64ee-4358-bc76-8f34e1cbdc3f/hooves',
                    name: 'hooves',
                    nodeType: NodeType.Slot,
                    components: [
                      {
                        nodeType: NodeType.Component,
                        uuid: 'd9e4d75c-8432-4fad-8a1a-5c4e99df3c48',
                        type: 'sdc_test:monkeys',
                        slots: [],
                      },
                    ],
                  } as SlotNode,
                ],
              },
            ],
          } as SlotNode,
        ],
      },
    ],
    id: 'content',
    name: 'Content',
  },
  {
    nodeType: NodeType.Region,
    components: [],
    id: 'highlighted',
    name: 'highlighted',
  },
];

describe('getNodeAtPath', () => {
  it('Gets top-level region', () => {
    expect(getNodeAtPath(sampleLayout, [1])).to.deep.eq({
      nodeType: 'region',
      components: [],
      id: 'highlighted',
      name: 'highlighted',
    });
  });

  it('Gets top-level component', () => {
    const component = getNodeAtPath(sampleLayout, [0, 0]) as ComponentNode;
    expect(component.uuid).to.eq('static-static-card1ab');
  });

  it('Gets slots', () => {
    const slot = getNodeAtPath(sampleLayout, [0, 0, 0]) as SlotNode;
    expect(slot.id).to.eq('static-static-card1ab/column_one');
  });

  it('Gets nested components', () => {
    const component = getNodeAtPath(
      sampleLayout,
      [0, 0, 0, 0, 1, 0],
    ) as ComponentNode;
    expect(component.uuid).to.eq('d9e4d75c-8432-4fad-8a1a-5c4e99df3c48');
  });
});

let layout: RootLayoutModel, regionsLayout: RootLayoutModel;

beforeEach(() => {
  layout = layoutFixture as RootLayoutModel;
  regionsLayout = regionsLayoutFixture as RootLayoutModel;
});

describe('isChildNode', () => {
  it('should correctly identify child nodes', () => {
    expect(isChildNode(layout.layout, 'a7470350-deb2-4d9f-982c-464d356403d4'))
      .to.be.false;
    expect(isChildNode(layout.layout, 'static-static-card2df')).to.be.false;
    expect(isChildNode(layout.layout, 'static-static-card1ab')).to.be.true;
    expect(isChildNode(layout.layout, 'idontexist')).to.be.null;
  });
});

describe('replaceUUIDsAndUpdateModel', () => {
  it('should replace UUIDs and update the model correctly', () => {
    const inputLayout = layout.layout;

    const inputModel = layout.model;
    expect(Object.keys(inputModel).length).to.equal(10);

    const inputNode = {
      nodeType: NodeType.Component,
      uuid: '3cf2625f-a0a8-4c97-85c0-06df16239c21',
      type: 'sdc.foo-bar',
      slots: [
        {
          nodeType: NodeType.Slot,
          name: 'mySlot',
          id: '3cf2625f-a0a8-4c97-85c0-06df16239c21/mySlot',
          components: inputLayout[0].components,
        },
      ],
    } as ComponentNode;
    expect(inputNode.uuid).to.equal('3cf2625f-a0a8-4c97-85c0-06df16239c21');

    const { updatedNode, updatedModel, uuidMap } = replaceUUIDsAndUpdateModel(
      inputNode,
      inputModel,
      {
        '3cf2625f-a0a8-4c97-85c0-06df16239c21':
          'ddc4f0c7-026f-4902-9b7e-f6059928c167',
      },
    );

    expect(updatedNode.uuid).not.to.equal(inputNode.uuid);

    function checkUUIDs(oldNode: LayoutNode, newNode: LayoutNode) {
      if (isSlotNode(oldNode)) {
        expect((newNode as SlotNode).id).not.to.equal(oldNode.id);
        expect((newNode as SlotNode).components.length).to.equal(
          oldNode.components.length,
        );
        oldNode.components.forEach((oldSlot, index) => {
          checkUUIDs(oldSlot, (newNode as SlotNode).components[index]);
        });
      } else {
        expect((newNode as ComponentNode).uuid).not.to.equal(
          (oldNode as ComponentNode).uuid,
        );
        expect((newNode as ComponentNode).slots.length).to.equal(
          (oldNode as ComponentNode).slots.length,
        );
        (oldNode as ComponentNode).slots.forEach((oldSlot, index) => {
          checkUUIDs(
            oldSlot as SlotNode,
            (newNode as ComponentNode).slots[index],
          );
        });
      }
    }

    checkUUIDs(inputNode, updatedNode);

    expect(Object.keys(updatedModel).length).to.equal(10);

    Object.keys(updatedModel).forEach((newUUID) => {
      const oldUUID = Object.keys(inputModel).find(
        (oldUUID) =>
          JSON.stringify(updatedModel[newUUID]) ===
          JSON.stringify(inputModel[oldUUID]),
      );
      expect(oldUUID).to.exist;
      expect(newUUID).not.to.equal(oldUUID);
    });

    expect(updatedNode.slots).to.have.length(1);
    expect(updatedNode.slots[0].components).to.have.length(5);
    expect(updatedNode.slots[0].components[0].slots).to.have.length(1);
    expect(updatedNode.slots[0].components[4].slots).to.have.length(2);
    expect(
      updatedNode.slots[0].components[4].slots[1].components,
    ).to.have.length(2);

    // Check if node types and component types are preserved
    expect(updatedNode.type).to.equal('sdc.foo-bar');
    expect(updatedNode.slots[0].components[0].type).to.equal(
      'sdc.canvas_test_sdc.two_column@d99140cbd47c0b51',
    );
    expect(updatedNode.slots[0].components[1].type).to.equal(
      'sdc.canvas_test_sdc.my-cta@e5ef92acda2ee2d1',
    );

    expect(updatedNode.slots[0].components[2].type).to.equal(
      'sdc.canvas_test_sdc.my-cta@e5ef92acda2ee2d1',
    );
    expect(updatedNode.slots[0].components[3].type).to.equal(
      'sdc.canvas_test_sdc.image@c06e0be7dd131740',
    );

    // Check if model data is preserved
    Object.keys(updatedModel).forEach((newUUID) => {
      const componentData = updatedModel[newUUID];
      if (componentData.resolved.image) {
        expect(componentData.resolved.image).to.have.all.keys(
          'src',
          'alt',
          'width',
          'height',
        );
      } else if (componentData.resolved.element) {
        expect(componentData.resolved).to.have.all.keys(
          'text',
          'style',
          'element',
        );
      } else if (uuidMap['6f3224e2-cb61-46e4-a9e4-35b4d18f0a82'] === newUUID) {
        expect(componentData.resolved).to.have.all.keys('text');
      } else if (componentData.resolved.text) {
        expect(componentData.resolved).to.have.all.keys('text', 'href');
      }
    });
  });

  describe('findParentRegion', () => {
    it('should find the correct parent region for a given UUID', () => {
      // Test for a component directly in a region
      const headerRegion = findParentRegion(
        regionsLayout.layout,
        '13ea974f-cf74-406a-9171-dad5f96e805f',
      ) as RegionNode;
      expect(headerRegion.id).to.equal('header');

      // Test for a component in nested slots
      const contentRegion = findParentRegion(
        regionsLayout.layout,
        '8afbb203-ae72-4155-8319-8c7b1915787a',
      ) as RegionNode;
      expect(contentRegion.id).to.equal('content');

      // Test for a non-existent UUID
      const nonExistentRegion = findParentRegion(
        regionsLayout.layout,
        'non-existent-uuid',
      );
      expect(nonExistentRegion).to.be.undefined;
    });
  });

  describe('componentExistsInLayout', () => {
    it('should return true if the component exists in the layout', () => {
      const layout = [
        {
          nodeType: 'region',
          id: 'region-1',
          name: 'Region 1',
          components: [
            {
              nodeType: 'component',
              uuid: 'component-1',
              type: 'js.other',
              slots: [],
            },
          ],
        },
        {
          nodeType: 'region',
          id: 'region-2',
          name: 'Region 2',
          components: [
            {
              nodeType: 'component',
              uuid: 'component-2',
              type: 'js.other',
              slots: [],
            },
            {
              nodeType: 'component',
              uuid: 'component-3',
              type: 'js.counter',
              slots: [],
            },
          ],
        },
      ] as Array<RegionNode>;

      const result = componentExistsInLayout(layout, 'js.counter');
      expect(result).to.be.true;
    });

    it('should return false if the component does not exist in the layout', () => {
      const layout = [
        {
          nodeType: NodeType.Region,
          id: 'region-1',
          name: 'Region 1',
          components: [
            {
              nodeType: NodeType.Component,
              uuid: 'component-1',
              type: 'js.other',
              slots: [],
            },
          ],
        },
        {
          nodeType: NodeType.Region,
          id: 'region-2',
          name: 'Region 2',
          components: [
            {
              nodeType: NodeType.Component,
              uuid: 'component-2',
              type: 'js.other',
              slots: [],
            },
          ],
        },
      ] as Array<RegionNode>;

      const result = componentExistsInLayout(layout, 'js.counter');
      expect(result).to.be.false;
    });

    it('should return true if the component exists in nested slots', () => {
      const layout = [
        {
          nodeType: 'region',
          id: 'region-1',
          name: 'Region 1',
          components: [
            {
              nodeType: 'component',
              uuid: 'component-1',
              type: 'js.other',
              slots: [
                {
                  nodeType: 'slot',
                  id: 'slot-1',
                  name: 'slot-1',
                  components: [
                    {
                      nodeType: 'component',
                      uuid: 'component-2',
                      type: 'js.counter',
                      slots: [],
                    },
                  ],
                },
              ],
            },
          ],
        },
      ] as Array<RegionNode>;
      const result = componentExistsInLayout(layout, 'js.counter');
      expect(result).to.be.true;
    });
  });

  describe('findParentInfo', () => {
    it('should find parent info for a component in a region', () => {
      // Component in header region
      const regionComponent = findParentInfo(
        regionsLayout.layout,
        '13ea974f-cf74-406a-9171-dad5f96e805f',
      ) as ParentInfo;

      expect(regionComponent).to.not.be.null;
      expect(regionComponent?.parentId).to.equal('header');
      expect(regionComponent?.parentType).to.equal('region');
      expect(regionComponent?.childIndex).to.equal(0);
    });

    it('should find parent info for a component in a slot', () => {
      // Component in a slot
      const slotComponent = findParentInfo(
        regionsLayout.layout,
        '8afbb203-ae72-4155-8319-8c7b1915787a',
      ) as ParentInfo;

      expect(slotComponent).to.not.be.null;
      expect(slotComponent?.parentId).to.equal(
        'ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_one',
      );
      expect(slotComponent?.parentType).to.equal('slot');
      expect(slotComponent?.childIndex).to.equal(1); // It's the second component in the slot
    });

    it('should return null for non-existent component', () => {
      const nonExistentComponent = findParentInfo(
        regionsLayout.layout,
        'non-existent-uuid',
      );

      expect(nonExistentComponent).to.be.null;
    });
  });

  describe('areConsecutiveSiblings', () => {
    it('should return true for a single component', () => {
      const result = areConsecutiveSiblings(regionsLayout.layout, [
        '13ea974f-cf74-406a-9171-dad5f96e805f',
      ]);

      expect(result).to.be.true;
    });

    it('should return true for consecutive siblings in a region', () => {
      // Components in the highlighted region
      const result = areConsecutiveSiblings(regionsLayout.layout, [
        '70812c33-8706-4754-b0d4-3467a869bd69', // First component
        'cb3078d3-7295-401a-8623-a838b3ae3350', // Second component
      ]);

      expect(result).to.be.true;
    });

    it('should return true for consecutive siblings in a slot', () => {
      // Components in the column_one slot
      const result = areConsecutiveSiblings(regionsLayout.layout, [
        '9bee944d-a92d-42b9-a0ae-abae0080cdfa', // First component in slot
        '8afbb203-ae72-4155-8319-8c7b1915787a', // Second component in slot
      ]);

      expect(result).to.be.true;
    });

    it('should return false for non-consecutive siblings in a region', () => {
      // First and third components in highlighted region
      const result = areConsecutiveSiblings(regionsLayout.layout, [
        '70812c33-8706-4754-b0d4-3467a869bd69', // First component
        '167aa265-2bb0-45f7-91bb-dedb64dabb3b', // Third component
      ]);

      expect(result).to.be.false;
    });

    it('should return false for components in different regions', () => {
      // Component from header and component from highlighted
      const result = areConsecutiveSiblings(regionsLayout.layout, [
        '13ea974f-cf74-406a-9171-dad5f96e805f', // In header region
        '70812c33-8706-4754-b0d4-3467a869bd69', // In highlighted region
      ]);

      expect(result).to.be.false;
    });

    it('should return false for components in different slots', () => {
      // Component from one slot and component from region
      const result = areConsecutiveSiblings(regionsLayout.layout, [
        '8afbb203-ae72-4155-8319-8c7b1915787a', // In column_one slot
        '13ea974f-cf74-406a-9171-dad5f96e805f', // In header region
      ]);

      expect(result).to.be.false;
    });

    it('should return false if any component does not exist', () => {
      const result = areConsecutiveSiblings(regionsLayout.layout, [
        '13ea974f-cf74-406a-9171-dad5f96e805f', // Existing component
        'non-existent-uuid', // Non-existent component
      ]);

      expect(result).to.be.false;
    });

    it('should return true for empty array of UUIDs', () => {
      const result = areConsecutiveSiblings(regionsLayout.layout, []);
      expect(result).to.be.true;
    });
  });

  describe('findParent', () => {
    it('should return the region for a top-level component', () => {
      const parent = findParent(
        layout.layout,
        'a7470350-deb2-4d9f-982c-464d356403d4',
      );
      expect(parent).to.have.property('id', 'content');
    });

    it('should return the slot for a component inside a slot', () => {
      const parent = findParent(layout.layout, 'static-static-card1ab');
      expect(parent).to.have.property(
        'id',
        'a7470350-deb2-4d9f-982c-464d356403d4/column_one',
      );
    });

    it('should return null for a non-existent component', () => {
      const parent = findParent(layout.layout, 'idontexist');
      expect(parent).to.be.null;
    });
  });

  describe('findSiblings', () => {
    it('should return siblings in the same slot (excluding itself)', () => {
      const siblings = findSiblings(layout.layout, 'static-static-card1ab');
      expect(siblings).to.have.length(1);
      expect(siblings[0].uuid).to.equal('static-image-udf7d');
    });

    it('should return siblings in the same region (excluding itself)', () => {
      const siblings = findSiblings(
        layout.layout,
        'a7470350-deb2-4d9f-982c-464d356403d4',
      );
      expect(siblings).to.have.length(4);
      expect(siblings.map((s) => s.uuid)).to.include('static-static-card2df');
      expect(siblings.map((s) => s.uuid)).to.include('static-static-card3rr');
      expect(siblings.map((s) => s.uuid)).to.include(
        'static-image-static-imageStyle-something7d',
      );
      expect(siblings.map((s) => s.uuid)).to.include(
        'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
      );
    });

    it('should return an empty array if no siblings exist (only child)', () => {
      const siblings = findSiblings(
        layout.layout,
        '6f3224e2-cb61-46e4-a9e4-35b4d18f0a82',
      );
      expect(siblings).to.have.length(0);
    });

    it('should return an empty array for a non-existent component', () => {
      const siblings = findSiblings(layout.layout, 'idontexist');
      expect(siblings).to.have.length(0);
    });
  });
});
