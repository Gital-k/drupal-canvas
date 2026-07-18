// @cspell:ignore efgh
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import layoutFixture from '@tests/fixtures/layout-default.json';
import mockFetch from '@tests/support/mockFetch';

import { makeStore } from '@/app/store';
import {
  deleteNode,
  duplicateNode,
  initialState,
  insertNodes,
  layoutModelSlice,
  moveNode,
  NodeType,
  selectLayoutHistory,
  setLayoutModel,
  shiftNode,
  sortNode,
} from '@/features/layout/layoutModelSlice';
import { setPageData } from '@/features/pageData/pageDataSlice';
import {
  selectUndoItem,
  initialState as uiInitialState,
  UndoRedoActionCreators,
} from '@/features/ui/uiSlice';

import type {
  ComponentNode,
  LayoutModelSliceState,
  RootLayoutModel,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

let layout: RootLayoutModel;

beforeEach(() => {
  mockFetch();
  layout = layoutFixture as RootLayoutModel;
  // The base query resolves {entity_type}/{entity_id} URL placeholders from
  // the current location, so pretend we are in the editor.
  window.history.pushState({}, '', '/canvas/editor/node/1');
});

afterEach(() => {
  vi.unstubAllGlobals();
});

const middlewareDefaultValue = {
  updatePreview: false,
  layout: [
    {
      nodeType: 'region',
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
              components: [],
            } as SlotNode,
          ],
        },
      ],
      id: 'content',
      name: 'Content',
    },
    {
      nodeType: 'region',
      components: [],
      id: 'highlighted',
      name: 'highlighted',
    },
  ],
  model: {
    'static-static-card1ab': {
      text: 'hello, world!',
      href: 'https://drupal.org',
      name: 'My Test CTA Component',
    },
  },
};

const configuration = {
  baseUrl: 'https://example.com/',
  entityType: 'none',
  entity: 'none',
  isNew: true,
  isPublished: false,
  devMode: false,
  homepageStagedConfigExists: false,
};

describe('Layout model slice', () => {
  it('insertNodes ThunkAction populates additional values for use in middleware - region', async () => {
    const store = makeStore({
      layoutModel: {
        present: middlewareDefaultValue,
        past: [initialState],
        future: [],
      },
      ui: uiInitialState,
      configuration,
    });
    const newComponents = [
      {
        slots: [],
        nodeType: NodeType.Component,
        type: 'some.block',
        uuid: 'abc1234',
      },
      {
        slots: [
          {
            nodeType: NodeType.Slot,
            id: 'this.will.get.updated/first',
            name: 'first',
            components: [
              {
                slots: [],
                nodeType: NodeType.Component,
                type: 'some.block',
                uuid: 'efgh5678',
              },
            ],
          },
        ],
        nodeType: NodeType.Component,
        type: 'some.block',
        uuid: 'this.will.get.updated',
      },
    ] as Array<ComponentNode>;
    const newItem = {
      layout: newComponents,
      model: {
        abc1234: { resolved: { title: 'New component' } },
      },
    };

    const action = insertNodes({
      to: [0, 0],
      layoutModel: newItem,
      useUUID: 'abc1234',
    });
    await store.dispatch(action);
    await vi.advanceTimersToNextTimer();
    await expect.poll(() => vi.mocked(fetch).mock.calls.length).to.eq(2);
    const last = vi.mocked(fetch).mock.calls[
      vi.mocked(fetch).mock.calls.length - 1
    ] as [Request];
    const requestBody = await last[0].clone().json();
    expect(requestBody.parentRegionOrUuid).to.eq('content');
    expect(requestBody.slot).to.eq(null);
    expect(requestBody.fractal).to.eq(0.5);
    expect(requestBody.layoutModel.layout[1].uuid).not.to.eq(
      'this.will.get.updated',
    );
    const newUuid = requestBody.layoutModel.layout[1].uuid;
    expect(requestBody.layoutModel.layout[1].slots[0].id).to.eq(
      `${newUuid}/first`,
    );
    expect(
      requestBody.layoutModel.layout[1].slots[0].components[0].uuid,
    ).not.to.eq('efgh5678');
  });

  it('insertNodes ThunkAction populates additional values for use in middleware - slot', async () => {
    const store = makeStore({
      layoutModel: {
        present: middlewareDefaultValue,
        past: [initialState],
        future: [],
      },
      ui: uiInitialState,
      configuration,
    });
    const newComponents = [
      {
        slots: [],
        nodeType: NodeType.Component,
        type: 'some.block',
        uuid: 'abc1234',
      },
      {
        slots: [],
        nodeType: NodeType.Component,
        type: 'some.block',
        uuid: 'this.will.get.updated',
      },
    ] as Array<ComponentNode>;
    const newItem = {
      layout: newComponents,
      model: {
        abc1234: { resolved: { title: 'New component' } },
      },
    };

    const action = insertNodes({
      to: [0, 0, 0, 0],
      layoutModel: newItem,
      useUUID: 'abc1234',
    });
    await store.dispatch(action);
    await vi.advanceTimersToNextTimer();
    await expect.poll(() => vi.mocked(fetch).mock.calls.length).to.eq(2);
    const last = vi.mocked(fetch).mock.calls.pop() as [Request];
    const requestBody = await last[0].json();
    expect(requestBody.layoutModel.layout[1].uuid).not.to.eq(
      'this.will.get.updated',
    );
    expect(requestBody.parentRegionOrUuid).to.eq('static-static-card1ab');
    expect(requestBody.slot).to.eq('column_one');
    expect(requestBody.fractal).to.eq(0.5);
  });

  it('moveNode ThunkAction populates additional values for use in middleware', async () => {
    const store = makeStore({
      layoutModel: {
        present: middlewareDefaultValue,
        past: [initialState],
        future: [],
      },
      ui: uiInitialState,
      configuration,
    });
    const action = moveNode({
      to: [1, 0],
      uuid: 'static-static-card1ab',
    });
    await store.dispatch(action);
    await vi.advanceTimersToNextTimer();
    await expect.poll(() => vi.mocked(fetch).mock.calls.length).to.eq(2);
    const last = vi.mocked(fetch).mock.calls.pop() as [Request];
    const requestBody = await last[0].json();
    expect(requestBody.parentRegionOrUuid).to.eq('highlighted');
    expect(requestBody.slot).to.eq(null);
    expect(requestBody.fractal).to.eq(0.5);
  });

  it('duplicateNode ThunkAction populates additional values for use in middleware', async () => {
    const store = makeStore({
      layoutModel: {
        present: middlewareDefaultValue,
        past: [initialState],
        future: [],
      },
      ui: uiInitialState,
      configuration,
    });
    const action = duplicateNode({
      uuid: 'static-static-card1ab',
    });
    await store.dispatch(action);
    await vi.advanceTimersToNextTimer();
    await expect.poll(() => vi.mocked(fetch).mock.calls.length).to.eq(2);
    const last = vi.mocked(fetch).mock.calls.pop() as [Request];
    const requestBody = await last[0].json();
    expect(Object.keys(requestBody.uuidMap)).to.deep.eq([
      'static-static-card1ab',
    ]);
    expect(requestBody.uuidMap['static-static-card1ab'].length).to.be.gt(0);
  });
});

describe('Set layout model', () => {
  it('Should set model and layout', () => {
    const state = layoutModelSlice.reducer(
      initialState,
      setLayoutModel({ ...layout, updatePreview: false }),
    );
    expect(state.layout).to.eq(layout.layout);
    expect(state.model).to.eq(layout.model);
  });
});

describe('Delete node', () => {
  it('Should delete node', () => {
    expect(layout.layout[0].components).to.have.length(5);
    expect(layout.layout[0].components.map((item) => item.uuid)).to.deep.equal([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      'static-static-card2df',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ]);
    let state = layoutModelSlice.reducer(
      { ...layout, updatePreview: false },
      deleteNode('static-static-card2df'),
    );
    expect(state.layout[0].components.length).to.eq(4);
    expect(state.layout[0].components.map((item) => item.uuid)).to.deep.equal([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ]);

    // Delete node with children
    // Check if all UUIDs of nested component(Two Column) exists before deletion
    const childUuids = [
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
      '6f3224e2-cb61-46e4-a9e4-35b4d18f0a82',
      '3b709ed2-99d3-4db2-869d-ca426f69fbb9',
      'eaa37ee1-7d50-4041-b04c-c80bdbac3412',
    ];
    expect(
      Object.keys(state.model).filter((item) => childUuids.includes(item))
        .length,
    ).to.eq(4);
    state = layoutModelSlice.reducer(
      { ...layout, updatePreview: false },
      deleteNode('ee07d472-a754-4427-b6d4-acfc6f92bbdc'),
    );
    expect(state.layout[0].components.length).to.eq(4);
    expect(state.layout[0].components.map((item) => item.uuid)).to.deep.equal([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      'static-static-card2df',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
    ]);
    // To be double sure: check if all UUIDs of nested component(Two Column) should not exist after deletion
    expect(
      Object.keys(state.model).filter((item) => childUuids.includes(item))
        .length,
    ).to.eq(0);
    expect(Object.keys(state.model)).to.deep.equal([
      'static-image-udf7d',
      'static-static-card1ab',
      'static-static-card2df',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
      'a7470350-deb2-4d9f-982c-464d356403d4',
    ]);
  });
});

describe('Move node', () => {
  it('Should move node', () => {
    expect(layout.layout[0].components[0].slots[0].components.length).to.eq(2);
    expect(layout.layout[0].components[4].slots[0].components.length).to.eq(1);
    expect(
      layout.layout[0].components[0].slots[0].components[0].uuid,
    ).to.deep.equal('static-static-card1ab');
    const action = layoutModelSlice.actions.moveNode({
      uuid: 'static-static-card1ab',
      to: [0, 4, 0, 1],
      parentRegionOrUuid: 'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
      slot: 'column_one',
      fractal: 1.5,
    });
    const state = layoutModelSlice.reducer(
      { ...layout, updatePreview: false },
      action,
    );
    expect(state.layout[0].components[0].slots[0].components.length).to.eq(1);
    expect(state.layout[0].components[4].slots[0].components.length).to.eq(2);
    expect(
      state.layout[0].components[4].slots[0].components.map(
        (item) => item.uuid,
      ),
    ).to.deep.equal([
      '6f3224e2-cb61-46e4-a9e4-35b4d18f0a82',
      'static-static-card1ab',
    ]);
  });
});

describe('Sort node', () => {
  // sortNode is a thunk that delegates to moveNode (a sort is a move within
  // the current parent), so it needs a store to dispatch through.
  it('Should sort node', () => {
    expect(layout.layout[0].components[0].slots[0].components[0].uuid).to.eq(
      'static-static-card1ab',
    );
    expect(layout.layout[0].components[0].slots[0].components[1].uuid).to.eq(
      'static-image-udf7d',
    );
    const store = makeStore({
      layoutModel: {
        present: { ...layout, updatePreview: false },
        past: [],
        future: [],
      },
      ui: uiInitialState,
    });
    store.dispatch(
      sortNode({
        uuid: 'static-static-card1ab',
        to: 1,
      }),
    );
    const state = selectLayoutHistory(store.getState()).present;
    expect(state.layout[0].components[0].slots[0].components[0].uuid).to.eq(
      'static-image-udf7d',
    );
    expect(state.layout[0].components[0].slots[0].components[1].uuid).to.eq(
      'static-static-card1ab',
    );
    expect(
      state.layout[0].components[0].slots[0].components.map(
        (item) => item.uuid,
      ),
    ).to.deep.equal(['static-image-udf7d', 'static-static-card1ab']);
  });
});

describe('Shift node down', () => {
  it('Should shift node', () => {
    expect(layout.layout[0].components[0].slots[0].components[0].uuid).to.eq(
      'static-static-card1ab',
    );
    expect(layout.layout[0].components[0].slots[0].components[1].uuid).to.eq(
      'static-image-udf7d',
    );
    const state = layoutModelSlice.reducer(
      { ...layout, updatePreview: false },
      shiftNode({
        uuid: 'static-static-card1ab',
        direction: 'down',
      }),
    );
    expect(state.layout[0].components[0].slots[0].components[0].uuid).to.eq(
      'static-image-udf7d',
    );
    expect(state.layout[0].components[0].slots[0].components[1].uuid).to.eq(
      'static-static-card1ab',
    );
    expect(
      state.layout[0].components[0].slots[0].components.map(
        (item) => item.uuid,
      ),
    ).to.deep.equal(['static-image-udf7d', 'static-static-card1ab']);
  });
});

describe('Shift node up', () => {
  it('Should shift node', () => {
    expect(layout.layout[0].components[3].uuid).to.eq(
      'static-image-static-imageStyle-something7d',
    );
    expect(layout.layout[0].components[2].uuid).to.eq('static-static-card3rr');
    const state = layoutModelSlice.reducer(
      { ...layout, updatePreview: false },
      shiftNode({
        uuid: 'static-image-static-imageStyle-something7d',
        direction: 'up',
      }),
    );
    expect(state.layout[0].components[3].uuid).to.eq('static-static-card3rr');
    expect(state.layout[0].components[2].uuid).to.eq(
      'static-image-static-imageStyle-something7d',
    );
    expect(state.layout[0].components.map((item) => item.uuid)).to.deep.equal([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      'static-static-card2df',
      'static-image-static-imageStyle-something7d',
      'static-static-card3rr',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ]);
  });
});

describe('Undo/redo', () => {
  it('Should support undo when past state exists', () => {
    const store = makeStore({
      layoutModel: { present: layout, past: [initialState], future: [] },
      ui: uiInitialState,
    });
    let state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(layout);
    expect(state.past.length).to.eq(1);
    expect(state.future.length).to.eq(0);
    store.dispatch(UndoRedoActionCreators.undo('layoutModel'));

    state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(initialState);
    expect(state.past.length).to.eq(0);
    expect(state.future.length).to.eq(1);
  });

  it('Should support redo when future state exists', () => {
    const store = makeStore({
      layoutModel: { present: layout, past: [initialState], future: [] },
      ui: uiInitialState,
    });
    let state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(layout);
    store.dispatch(UndoRedoActionCreators.undo('layoutModel'));

    state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(initialState);
    expect(state.past.length).to.eq(0);
    expect(state.future.length).to.eq(1);
    store.dispatch(UndoRedoActionCreators.redo('layoutModel'));

    state = selectLayoutHistory(store.getState());
    expect(state.present).to.deep.eq({ ...layout, updatePreview: true });
    expect(state.past.length).to.eq(1);
    expect(state.future.length).to.eq(0);
  });

  it('Should not support undo of initial load', () => {
    const store = makeStore({
      layoutModel: { present: initialState, past: [], future: [] },
      ui: uiInitialState,
    });
    let state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(initialState);
    expect(state.past.length).to.eq(0);
    expect(state.future.length).to.eq(0);
    store.dispatch(setLayoutModel({ ...layout, updatePreview: false }));
    const undoItem = selectUndoItem(store.getState());
    expect(undoItem?.targetSlice).to.eq('layoutModel');

    state = selectLayoutHistory(store.getState());
    expect(state.present.layout).to.deep.equal(layout.layout);
    expect(state.present.model).to.deep.equal(layout.model);
    expect(state.past.length).to.eq(0);
    expect(state.future.length).to.eq(0);
  });

  it('Should prune future state if undo type changes', () => {
    const store = makeStore({
      layoutModel: { present: layout, past: [initialState], future: [] },
      pageData: {
        present: { title: [{ value: 'Title' }] },
        past: [{}],
        future: [],
      },
      ui: uiInitialState,
    });
    let state = selectLayoutHistory(store.getState());
    expect(state.present).to.deep.equal(layout);

    state = selectLayoutHistory(store.getState());
    expect(state.past.length).to.eq(1);
    expect(state.future.length).to.eq(0);

    store.dispatch(UndoRedoActionCreators.undo('layoutModel'));
    state = selectLayoutHistory(store.getState());
    expect(state.present).to.deep.equal(initialState);
    expect(state.past.length).to.eq(0);
    expect(state.future.length).to.eq(1);

    store.dispatch(setPageData({}));
    const undoItem = selectUndoItem(store.getState());
    expect(undoItem?.targetSlice).to.eq('pageData');

    state = selectLayoutHistory(store.getState());
    expect(state.present).to.deep.equal(initialState);
    expect(state.past.length).to.eq(0);
    expect(state.future.length).to.eq(0);
  });
});

describe('Duplicate node', () => {
  it('Should duplicate a node correctly with a new UUID and duplicate its children nodes', () => {
    // Initialize state with a layout
    const initialStateWithLayout = layoutModelSlice.reducer(
      initialState,
      setLayoutModel({
        layout: [
          {
            nodeType: 'region',
            name: 'content',
            id: 'content',
            components: [
              {
                uuid: 'original-node',
                nodeType: 'component',
                type: 'Original Node',
                slots: [
                  {
                    id: 'original-node/child1',
                    nodeType: 'slot',
                    name: 'Slot 1',
                    components: [
                      {
                        uuid: 'child-component',
                        nodeType: 'component',
                        type: 'Original Node',
                        slots: [],
                      },
                    ],
                  },
                  {
                    id: 'original-node/child2',
                    nodeType: 'slot',
                    name: 'Slot 2',
                    components: [],
                  },
                ],
              },
            ],
          },
        ],
        model: {},
        updatePreview: true,
      } as LayoutModelSliceState),
    );

    const nodeToDuplicateUUID = 'original-node';
    const expectedUuidMap: Record<string, string> = {
      'original-node': 'd79a46fc-4dbf-4317-b665-f07847114a7d',
      'child-component': 'c2bb2db1-e550-4755-baea-6527783308c8',
    };
    const action = layoutModelSlice.actions.duplicateNode({
      uuid: nodeToDuplicateUUID,
      uuidMap: expectedUuidMap,
    });
    const stateAfterDuplication = layoutModelSlice.reducer(
      initialStateWithLayout,
      action,
    );

    const originalNode = initialStateWithLayout.layout[0].components.find(
      (node) => node.uuid === nodeToDuplicateUUID,
    ) as ComponentNode;
    const newNode = stateAfterDuplication.layout[0].components.find(
      (node) => node.uuid !== nodeToDuplicateUUID,
    ) as ComponentNode;

    // Ensure the new node is a duplicate and has a different UUID
    expect(newNode).to.not.be.undefined;
    expect(newNode.uuid).to.not.equal(nodeToDuplicateUUID);
    expect(newNode.type).to.equal(originalNode.type);
    expect(newNode.nodeType).to.equal(originalNode.nodeType);
    expect(newNode.slots.length).to.equal(originalNode.slots.length);

    // Verify each child node's UUID in the new node
    const actualUuidMap: Record<string, string> = {
      [nodeToDuplicateUUID]: newNode.uuid,
    };
    originalNode.slots.forEach((originalChild, index) => {
      const newChild = newNode.slots[index];
      expect(newChild).to.not.be.undefined;
      expect(newChild.id).to.not.equal(originalChild.id);
      expect(newChild.name).to.equal(originalChild.name);
      expect(newChild.nodeType).to.equal(originalChild.nodeType);
      if (originalChild.components.length > 0) {
        originalChild.components.forEach(
          (originalChildComponent, componentIndex) => {
            const newChildComponent =
              newNode.slots[index].components[componentIndex];
            actualUuidMap[originalChildComponent.uuid] = newChildComponent.uuid;
            // eslint-disable-next-line @typescript-eslint/no-unused-vars
            const { uuid: _, ...originalRest } = originalChildComponent;
            // eslint-disable-next-line @typescript-eslint/no-unused-vars
            const { uuid: __, ...newRest } = newChildComponent;
            expect(newRest).to.deep.equal(originalRest);
          },
        );
        return;
      }
      expect(newChild.components).to.deep.equal(originalChild.components);
    });

    // Verify the model for the new node and its children
    expect(stateAfterDuplication.model[newNode.uuid]).to.deep.equal(
      stateAfterDuplication.model[nodeToDuplicateUUID],
    );

    expect(actualUuidMap).to.deep.equal(expectedUuidMap);
  });
});
