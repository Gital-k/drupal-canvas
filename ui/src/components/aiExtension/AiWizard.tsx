/**
 * ⚠️ This is highly experimental and *will* be refactored.
 */
import {
  memo,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  useSyncExternalStore,
} from 'react';
import { DeepChat } from 'deep-chat-react';
import { useParams } from 'react-router';
import { useNavigate } from 'react-router-dom';
import AiWelcome from '@assets/icons/ai-welcome.svg?react';
import { Box, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector, useAppStore } from '@/app/hooks';
import {
  selectCodeComponentProperty,
  setCodeComponentProperty,
} from '@/features/code-editor/codeEditorSlice';
import {
  deserializeProps,
  deserializeSlots,
} from '@/features/code-editor/utils/utils';
import { FORM_TYPES } from '@/features/form/constants';
import { setFieldValue } from '@/features/form/formStateSlice';
import {
  selectModel,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import {
  selectPageData,
  updatePageDataExternally,
} from '@/features/pageData/pageDataSlice';
import {
  useCreateCodeComponentMutation,
  useGetComponentsQuery,
} from '@/services/componentAndLayout';
import { isPropSourceComponent } from '@/types/Component';
import { getBaseUrl, getDrupalSettings } from '@/utils/drupal-globals';

import fixtureProps from '../../../../modules/canvas_ai/src/PropsSchema.json';

import type {
  ComponentModels,
  ComponentNode,
  LayoutModelSliceState,
} from '@/features/layout/layoutModelSlice';
import type { CodeComponent } from '@/types/CodeComponent';
import type { CanvasComponent, PropSourceComponent } from '@/types/Component';

import styles from './AiWizard.module.css';

const DB_NAME = 'aiWizardDB';
const STORE_NAME = 'chatHistory';
const KEY = 'history';

const withStore = (
  type: IDBTransactionMode,
  callback: (store: IDBObjectStore) => void,
): Promise<void> => {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () =>
      request.result.createObjectStore(STORE_NAME);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const db = request.result;
      const tx = db.transaction(STORE_NAME, type);
      callback(tx.objectStore(STORE_NAME));
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    };
  });
};

const db = {
  get: (): Promise<any[]> =>
    new Promise((resolve) => {
      let req: IDBRequest;
      withStore('readonly', (store) => {
        req = store.get(KEY);
      }).then(() => resolve(req?.result || []));
    }),
  set: (data: any[]) => withStore('readwrite', (store) => store.put(data, KEY)),
  clear: () => withStore('readwrite', (store) => store.clear()),
};

const createHistoryStore = () => {
  let history: any[] = [];
  const subscribers = new Set<() => void>();

  db.get().then((initialHistory) => {
    history = initialHistory;
    subscribers.forEach((callback) => callback());
  });

  return {
    addMessage(message: any) {
      history = [...history, message];
      db.set(history);
    },
    clearHistory() {
      history = [];
      db.clear();
      subscribers.forEach((callback) => callback());
    },
    // @todo No subscribers remain since the history prop is frozen at mount; remove subscribe and the subscribers Set in https://git.drupalcode.org/project/canvas/-/work_items/3591731.
    subscribe(callback: () => void) {
      subscribers.add(callback);
      return () => subscribers.delete(callback);
    },
    getSnapshot() {
      return history;
    },
  };
};
const historyStore = createHistoryStore();

// DeepChat is rendered through `memo` so that re-renders of AiWizard (e.g. when
// the layout model changes as components are placed) do not re-seed DeepChat
// from its `history` prop, which would wipe imperatively-added progress
// messages. For the memo to hold, every object prop must be a stable reference,
// so the static configuration objects live at module scope.
const MemoDeepChat = memo(DeepChat);

const DEEP_CHAT_IMAGES = {
  files: {
    acceptedFormats: '.jpg, .png, .jpeg',
    // For now we just support uploading 1 image at a time
    // if the user tries to upload another image the already
    // added image is replaced.
    maxNumberOfFiles: 1,
  },
  button: {
    position: 'inside-start' as const,
    styles: {
      container: {
        default: {
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          marginLeft: '8px',
          marginBottom: '12px',
          backgroundColor: '#F0F0F3',
        },
      },
      svg: {
        content: `
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="16" height="16" fill="white" fill-opacity="0.01"/>
        <path fill-rule="evenodd" clip-rule="evenodd" d="M8.53324 2.93324C8.53324 2.63869 8.29445 2.3999 7.9999 2.3999C7.70535 2.3999 7.46657 2.63869 7.46657 2.93324V7.46657H2.93324C2.63869 7.46657 2.3999 7.70535 2.3999 7.9999C2.3999 8.29445 2.63869 8.53324 2.93324 8.53324H7.46657V13.0666C7.46657 13.3611 7.70535 13.5999 7.9999 13.5999C8.29445 13.5999 8.53324 13.3611 8.53324 13.0666V8.53324H13.0666C13.3611 8.53324 13.5999 8.29445 13.5999 7.9999C13.5999 7.70535 13.3611 7.46657 13.0666 7.46657H8.53324V2.93324Z" fill="#60646C"/>
        </svg>
      `,
      },
    },
  },
};

// Setting to -1 to allow sending the entire conversation history.
// @see https://deepchat.dev/docs/connect/#requestBodyLimits
const DEEP_CHAT_REQUEST_BODY_LIMITS = {
  maxMessages: -1,
};

const DEEP_CHAT_TEXT_INPUT = {
  placeholder: { text: 'Build me a ...' },
  styles: {
    text: {
      padding: '16px',
    },
    container: {
      height: '167px',
      width: '100%',
      padding: '0 0 40px 0',
    },
  },
};

const DEEP_CHAT_STYLE = {
  width: '283px',
  height: '100%',
};

const DEEP_CHAT_MESSAGE_STYLES = {
  default: {
    shared: {
      bubble: {
        width: '100%',
        maxWidth: '100%',
        color: 'var(--black-12)',
        fontSize: '14px',
        fontWeight: '400',
        lineHeight: '1.26',
        padding: '8px',
        textAlign: 'left',
      },
    },
    user: {
      bubble: {
        backgroundColor: '#F0F0F3',
      },
    },
    ai: {
      bubble: {
        backgroundColor: 'white',
      },
    },
    error: {
      bubble: {
        color: '#FF3333',
      },
    },
  },
};

const DEEP_CHAT_SUBMIT_BUTTON_STYLES = {
  disabled: {
    container: {
      default: {
        display: 'none',
      },
    },
  },
  submit: {
    container: {
      default: {
        display: 'inherit',
        marginRight: '8px',
        marginBottom: '12px',
      },
    },
    svg: {
      content: `
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M0 3C0 1.34315 1.34315 0 3 0H21C22.6569 0 24 1.34315 24 3V21C24 22.6569 22.6569 24 21 24H3C1.34315 24 0 22.6569 0 21V3Z" fill="#0090FF"/>
        <rect width="16" height="16" transform="translate(4 4)" fill="white" fill-opacity="0.01"/>
        <path fill-rule="evenodd" clip-rule="evenodd" d="M11.6228 6.28952C11.8311 6.08123 12.1688 6.08123 12.3771 6.28952L16.6438 10.5562C16.852 10.7645 16.852 11.1021 16.6438 11.3104C16.4355 11.5187 16.0978 11.5187 15.8894 11.3104L12.5333 7.95422V17.3333C12.5333 17.6278 12.2945 17.8666 12 17.8666C11.7054 17.8666 11.4666 17.6278 11.4666 17.3333V7.95422L8.11041 11.3104C7.90213 11.5187 7.56444 11.5187 7.35617 11.3104C7.14788 11.1021 7.14788 10.7645 7.35617 10.5562L11.6228 6.28952Z" fill="white"/>
      </svg>
    `,
    },
  },
  stop: {
    svg: {
      content: `
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M0 3C0 1.34315 1.34315 0 3 0H21C22.6569 0 24 1.34315 24 3V21C24 22.6569 22.6569 24 21 24H3C1.34315 24 0 22.6569 0 21V3Z" fill="#0090FF"/>
        <rect width="16" height="16" transform="translate(4 4)" fill="white" fill-opacity="0.01"/>
        <path fill-rule="evenodd" clip-rule="evenodd" d="M6.1333 7.19997C6.1333 6.61087 6.61087 6.1333 7.19997 6.1333H16.8C17.3891 6.1333 17.8666 6.61087 17.8666 7.19997V16.8C17.8666 17.3891 17.3891 17.8666 16.8 17.8666H7.19997C6.61087 17.8666 6.1333 17.3891 6.1333 16.8V7.19997ZM16.8 7.19997H7.19997V16.8H16.8V7.19997Z" fill="white"/>
      </svg>
    `,
    },
  },
};

const DEEP_CHAT_AUXILIARY_STYLE = `
:host {
  border: none !important;
}
.aiLoader, .aiCompletedIcon {
  display: inline-block;
  box-sizing: border-box;
  vertical-align: middle;
  margin-right: 8px;
}
.aiLoader {
  width: 12px;
  height: 12px;
  border: 2px solid #8B8D98;
  border-bottom-color: transparent;
  border-radius: 50%;
  animation: ai-wizard-rotation 0.8s linear infinite;
}
@keyframes ai-wizard-rotation {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
.aiCompletedIcon {
  position: relative;
  width: 12px;
  height: 12px;
  border: 1.5px solid #30A46C;
  border-radius: 50%;
}
.aiCompletedIcon::after {
  content: '';
  position: absolute;
  top: 0px;
  left: 3px;
  width: 3px;
  height: 6px;
  border: solid #30A46C;
  border-width: 0 1.5px 1.5px 0;
  transform: rotate(45deg);
}
#chat-view:has(#messages:empty) {
  display: block;
}
#chat-view:has(#messages:empty) #input:has(#file-attachment-container[style*='display: block']) {
  margin-top: 40px;
}
.text-message h1 {
  font-size: var(--font-size-5);
}
.text-message h2 {
  font-size: var(--font-size-4);
}
.text-message h3 {
  font-size: var(--font-size-3);
}
.text-message h4 {
  font-size: var(--font-size-2);
}
.text-message h5 {
  font-size: var(--font-size-1);
}
`;

const simplePropertyHandler = (
  property: string,
  propKey: keyof CodeComponent,
) => ({
  canHandle: (msg: any) => property in msg && msg[property],
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    dispatch(setCodeComponentProperty([propKey, message[property]]));
  },
});

const cssStructureHandler = simplePropertyHandler(
  'css_structure',
  'sourceCodeCss',
);
const jsStructureHandler = simplePropertyHandler(
  'js_structure',
  'sourceCodeJs',
);

const componentStructureHandler = {
  canHandle: (msg: any) =>
    'component_structure' in msg && msg.component_structure,
  handle: async ({
    message,
    createCodeComponent,
    navigate,
  }: {
    message: any;
    createCodeComponent: any;
    navigate: any;
  }) => {
    const component = message.component_structure;
    await createCodeComponent(component).unwrap();
    navigate(`/code-editor/component/${component.machineName}`);
  },
};

const propsMetadataHandler = {
  canHandle: (msg: any) => 'props_metadata' in msg && msg.props_metadata,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const parsedProps = JSON.parse(message.props_metadata);
    // Deserialize from Record format to Array format.
    const deserializedProps = deserializeProps(parsedProps);
    dispatch(setCodeComponentProperty(['props', deserializedProps]));
  },
};

const slotsMetadataHandler = {
  canHandle: (msg: any) => 'slots_metadata' in msg && msg.slots_metadata,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const parsedSlots = JSON.parse(message.slots_metadata);
    // Deserialize from Record format to Array format.
    const deserializedSlots = deserializeSlots(parsedSlots);
    dispatch(setCodeComponentProperty(['slots', deserializedSlots]));
  },
};

const requiredPropsHandler = {
  canHandle: (msg: any) =>
    'required_props' in msg && Array.isArray(msg.required_props),
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    dispatch(setCodeComponentProperty(['required', message.required_props]));
  },
};

const canvasPageDataHandler = {
  canHandle: (msg: any) => 'canvas_page_data' in msg && msg.canvas_page_data,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const updates = message.canvas_page_data;
    if (Object.keys(updates).length > 0) {
      // Keep the formState slice in sync, mirroring what enhancedOnChange does
      // for a real keystroke (see docs/component-and-entity-forms.md). Without
      // this the formState slice retains the stale value, and the next field
      // change or AJAX event rebuilds pageData from it
      // (createPageDataFormStateHandler / PageDataForm's AJAX listener),
      // clobbering the AI value before it is auto-saved or published.
      // @todo Multi-value fields are written as raw arrays here, bypassing the
      //   multi-select normalization in createPageDataFormStateHandler. Only
      //   single-value fields (title, description) are set today. Normalize
      //   when extending to multi-value fields, see
      //   https://www.drupal.org/i/3587609.
      Object.entries(updates).forEach(([fieldName, value]) => {
        dispatch(
          setFieldValue({
            formId: FORM_TYPES.ENTITY_FORM,
            fieldName,
            value,
          }),
        );
      });
      // Flag the preview/auto-save POST and mirror the value into the RHF form
      // display via useRespondToPageDataStoreUpdates.
      dispatch(setUpdatePreview(true));
      dispatch(updatePageDataExternally(updates));
    }
  },
};

// Filters out 'media' fields from a js component instance's fieldValues based on the
// component definition's propSources, forcing the component to use the example
// image from its definition.
// Block components do not have propSources, so we cannot set field values while
// placing them - return unchanged in that case.
// @todo Refactor this after https://www.drupal.org/i/3552000 is fixed.
function removeMediaFields(componentDef: CanvasComponent, componentInst: any) {
  if (!isPropSourceComponent(componentDef)) {
    return componentInst;
  }
  const newFieldValues = {} as any;
  const fieldValues = componentInst.fieldValues || {};
  for (const [key, value] of Object.entries(fieldValues)) {
    const prop = (componentDef as PropSourceComponent).propSources[key];
    const isMedia =
      (prop?.sourceTypeSettings?.storage as any)?.target_type === 'media';
    if (!isMedia) {
      newFieldValues[key] = value;
    }
  }
  return {
    ...componentInst,
    fieldValues: newFieldValues,
  };
}

// Helper to delay the placement of components.
const delay = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const operationsHandler = {
  canHandle: (msg: any) => 'operations' in msg && msg.operations,
  handle: async ({
    message,
    dispatch,
    availableComponents,
    layoutUtils,
  }: {
    message: any;
    dispatch: any;
    availableComponents: any;
    layoutUtils: any;
  }) => {
    // Logic for placing components (SDCs/Blocks/Code components) to the editor frame.
    for (const op of message.operations) {
      // Only 'Add' operation is supported for now.
      if (
        op.operation === 'ADD' &&
        op.components &&
        Array.isArray(op.components) &&
        availableComponents
      ) {
        for (const component of op.components) {
          if (component.id && availableComponents[component.id]) {
            const componentToUse: CanvasComponent =
              availableComponents[component.id];
            const componentAfterFilteringImageProps = removeMediaFields(
              componentToUse,
              component,
            );
            // No selection callback is passed: auto-selecting a placed
            // component navigates the URL to …/component/<uuid>, which remounts
            // AiWizard (each route mounts its own UiShell) and wipes the live
            // progress and answer messages. AI batch placement leaves the
            // selection untouched.
            dispatch(
              layoutUtils.addNewComponentToLayout({
                component: componentToUse,
                withValues: componentAfterFilteringImageProps.fieldValues,
                to: component.nodePath,
                uuid: component.uuid,
              }),
            );
            // Wait for a second before placing the next component, for the UI to render the component.
            await delay(1000);
          }
        }
      }
    }
  },
};

const messageHandlers = [
  canvasPageDataHandler,
  cssStructureHandler,
  jsStructureHandler,
  componentStructureHandler,
  propsMetadataHandler,
  slotsMetadataHandler,
  requiredPropsHandler,
  operationsHandler,
];

// Renders agent/tool progress for a turn into a single HTML chat message. The
// progress items come from each `/ai` hop response (no separate poll channel),
// so the hop loop is the only writer of this message. The orchestrator entry is
// shown last: sub-agent narration reads above the orchestrator's running
// commentary.
const buildProgressHtml = (items: any[]): string => {
  if (!items?.length) return '';

  const rest = items.filter((i) => i.id !== 'canvas_ai_orchestrator');
  const orchestrator = items.filter((i) => i.id === 'canvas_ai_orchestrator');
  const ordered = [...rest, ...orchestrator];

  let html = '<div style="margin-top: 10px;">';
  ordered.forEach((item) => {
    const icon =
      item.status === 'completed'
        ? '<span class="aiCompletedIcon"></span>'
        : '<span class="aiLoader"></span>';

    // Narration first, then the icon + label row beneath it. This keeps the
    // active spinner as the last line of each agent's block — and, since the
    // orchestrator ("Thinking") is ordered last, the last line of the whole
    // block. As narration grows, the "still running" cue stays pinned at the
    // bottom instead of being pushed out of view at the top.
    if (item.type === 'agent' && item.generated_text?.trim()) {
      html += `<div style="padding: 8px; background-color: white; font-size: 14px; line-height: 1.26;">
          ${item.generated_text.replace(/\n/g, '<br>')}
        </div>`;
    }

    html += `<div style="display: flex; align-items: center; padding: 8px; background-color: white;">
        <span style="margin-right: 8px;">${icon}</span>
        <span style="font-weight: 400;">${item.description}</span>
      </div>`;
  });

  return html + '</div>';
};

function getHandlersForMessage(message: any) {
  return messageHandlers.filter((handler) => handler.canHandle(message));
}

// Runs `mutate` (an imperative add/update on DeepChat's message list) while
// preserving the user's scroll position: if they were pinned to the bottom we
// follow the new content, but if they scrolled up to read earlier output we
// leave them be. The "pinned" check must run BEFORE the mutation (otherwise the
// grown content has already changed scrollHeight); the scroll runs AFTER, in a
// macrotask, so DeepChat has painted the new message. DeepChat's scroll
// container lives in its shadow root as `#messages`.
function withStickyScroll(chatEl: any, mutate: () => void) {
  const container = chatEl?.shadowRoot?.querySelector('#messages');
  // No container yet (first message) → treat as pinned so we scroll into view.
  const pinned = container
    ? container.scrollHeight - container.scrollTop - container.clientHeight < 5
    : true;
  mutate();
  if (container && pinned) {
    setTimeout(() => {
      container.scrollTop = container.scrollHeight;
    }, 0);
  }
}

// Recursively flattens a region's component tree into the wire shape the
// backend expects (name + uuid + resolved props + nested slots). Pure — kept
// at module scope so it carries no render-cycle identity.
function processComponents(
  components: ComponentNode[] | undefined,
  model: ComponentModels,
): any[] {
  if (!components) return [];
  return components.map((component) => {
    const transformedComponent: any = {
      name: component.type?.split('@')[0],
      uuid: component.uuid,
      props: model[component.uuid]?.resolved ?? {},
    };
    if (component.slots && component.slots.length > 0) {
      transformedComponent.slots = {};
      component.slots.forEach((slot) => {
        transformedComponent.slots[slot.id] = {
          components: processComponents(slot.components, model),
        };
      });
    }
    return transformedComponent;
  });
}

const AiWizard = () => {
  const dispatch = useAppDispatch();
  const drupalSettings = getDrupalSettings();
  const chatElementRef = useRef<any>(null);
  const [csrfToken, setCsrfToken] = useState<string | null>(null);
  const [createCodeComponent] = useCreateCodeComponentMutation();
  const navigate = useNavigate();
  const params = useParams();
  const codeComponentName = useAppSelector(
    selectCodeComponentProperty('machineName'),
  );
  const codeComponentRequiredProps = useAppSelector(
    selectCodeComponentProperty('required'),
  );
  const store = useAppStore();
  const chatHistory = useSyncExternalStore(
    historyStore.subscribe,
    historyStore.getSnapshot,
  );
  // `history` only seeds DeepChat (initial mount + reload restore). During a
  // live turn the progress message is added to DeepChat's own list imperatively
  // and addressed by index — it is not in historyStore until the final hop. If
  // the `history` prop reference changes mid-turn DeepChat re-seeds and wipes
  // that message, invalidating the cached index ("Message index not found").
  // `historyStore.addMessage` swaps the snapshot array without notifying, so a
  // mid-turn re-render (a parent re-rendering as components are placed) surfaces
  // a new `chatHistory` reference. Freeze the reference for the whole turn so
  // DeepChat never re-seeds while progress is live.
  const turnActiveRef = useRef(false);
  const frozenHistoryRef = useRef(chatHistory);
  if (!turnActiveRef.current) {
    frozenHistoryRef.current = chatHistory;
  }
  const hasRenderedRef = useRef(false);
  const welcomeTextRef = useRef<HTMLSpanElement>(null);
  // AbortController to cancel ongoing requests when component unmounts
  const abortControllerRef = useRef<AbortController | null>(null);

  // Create a ref to store current values for Deep Chat's connect prop.
  // Accessing these ensures we're working with fresh values even after the Deep
  // Chat component has been mounted. Layout, page data, and selection are read
  // straight from the Redux store at request time instead — a ref lags the
  // store by a render cycle, which is too late between hops.
  const currentValuesRef = useRef({
    codeComponentName,
    params,
    codeComponentRequiredProps,
  });

  // Update the ref whenever tracked values change.
  useEffect(() => {
    currentValuesRef.current = {
      codeComponentName,
      params,
      codeComponentRequiredProps,
    };
  }, [codeComponentName, params, codeComponentRequiredProps]);
  // Access layoutUtils from drupalSettings.canvas
  const layoutUtils = drupalSettings.canvas?.layoutUtils as any;

  const { data: availableComponents } = useGetComponentsQuery();
  const componentsRef = useRef<any>(null);

  useEffect(() => {
    if (availableComponents && !componentsRef.current) {
      componentsRef.current = availableComponents;
    }
  }, [availableComponents]);

  // Helper to transform the current layout into a JSON representation. Reads
  // the layout straight from the Redux store: ref- and render-based snapshots
  // lag by a render cycle, which is too late when the next hop request is
  // built right after a handler dispatched layout changes.
  const transformLayout = useCallback(() => {
    const theLayout = store.getState()?.layoutModel?.present as
      | LayoutModelSliceState
      | undefined;
    if (!theLayout?.layout) return null;
    const result: any = { regions: {} };
    theLayout.layout.forEach((region, regionIndex) => {
      result.regions[region.id] = {
        nodePathPrefix: [regionIndex],
        components: processComponents(region.components, theLayout.model),
      };
    });
    return result;
  }, [store]);

  // Cleanup effect to abort requests when component unmounts. Aborting the
  // in-flight fetch rejects the hop loop, which ends it.
  useEffect(() => {
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  }, []);

  // Fetch CSRF token on mount.
  useEffect(() => {
    const fetchToken = async () => {
      try {
        const baseUrl = getBaseUrl();
        const response = await fetch(`${baseUrl}admin/api/canvas/token`, {
          credentials: 'same-origin',
        });
        if (!response.ok) {
          throw new Error(
            `HTTP error: ${response.status} ${response.statusText}`,
          );
        }
        const token = await response.text();
        setCsrfToken(token);
      } catch (error) {
        console.error('Failed to fetch CSRF token:', error);
        const event = new CustomEvent('canvas-csrf-token-error', {
          detail: {
            error,
            time: new Date(),
          },
        });
        window.dispatchEvent(event);
      }
    };

    fetchToken();
  }, []);

  // Function to handle message response from AI. Handlers are awaited
  // sequentially so the hop loop only sends the next request after every side
  // effect (component placement, page data updates) has been applied. Errors
  // propagate to the hop loop, which aborts the conversation.
  const receiveMessage = useCallback(
    async (message: any) => {
      const handlers = getHandlersForMessage(message);
      for (const handler of handlers) {
        await handler.handle({
          message,
          dispatch,
          createCodeComponent,
          navigate,
          availableComponents: componentsRef.current,
          layoutUtils,
        });
      }
      return { text: message.message };
    },
    [dispatch, layoutUtils, navigate, createCodeComponent],
  );

  useEffect(() => {
    const chatEl = chatElementRef.current;
    if (!chatEl) return;
    const handler = (event: { detail: { message: any; isHistory: any } }) => {
      const { message, isHistory } = event.detail;
      if (!isHistory) {
        if (welcomeTextRef.current) {
          welcomeTextRef.current.style.display = 'none';
        }
        historyStore.addMessage(message);
        const event = new CustomEvent('canvas-message', {
          detail: {
            message: message,
            time: new Date(),
          },
        });
        window.dispatchEvent(event);
      }
    };
    chatEl.addEventListener('message', handler);
    return () => {
      chatEl.removeEventListener('message', handler);
    };
  }, [csrfToken]);

  // Handle text input changes to enable/disable submit button. Memoized so its
  // identity stays stable across renders, which keeps MemoDeepChat from
  // re-rendering (and clearing the typed prompt) when page metadata changes.
  const handleTextInput = useCallback(() => {
    const chatEl = chatElementRef.current;
    const deepChatEl = document.querySelector('deep-chat') as any;
    const inputText =
      deepChatEl?.shadowRoot?.querySelector('#text-input')?.textContent || '';
    if (inputText.trim().length > 0) {
      chatEl.disableSubmitButton(false);
    } else {
      chatEl.disableSubmitButton();
    }
  }, []);

  // Reset chat state the first time DeepChat renders. Ref-guarded so this only
  // runs once and the callback identity stays stable for `memo`.
  const handleComponentRender = useCallback(() => {
    if (!hasRenderedRef.current) {
      chatElementRef.current.clearMessages();
      historyStore.clearHistory();
      chatElementRef.current.disableSubmitButton();
      hasRenderedRef.current = true;
    }
  }, []);

  // DeepChat's connect handler. Defining a handler instead of an object keeps us
  // working with up-to-date data; everything mutable is read from refs or the
  // Redux store at request time. Wrapped in useMemo so the `connect` object is a
  // stable reference for `MemoDeepChat`.
  // @see https://deepchat.dev/docs/connect/#Handler
  const connect = useMemo(
    () => ({
      handler: async (body: any, signals: any) => {
        try {
          const hasFiles = body instanceof FormData;
          let requestBody: FormData | string = '';
          const headers: Record<string, string> = {
            'X-CSRF-Token': csrfToken as string,
          };
          // Generate a unique request ID. It stays constant across hops:
          // the backend resolves the serialized orchestrator state by it.
          const requestId = `req_${Date.now()}_${Math.random().toString(36).substring(2, 11)}`;

          if (hasFiles) {
            const files = body.getAll('files');
            const MAX_FILE_SIZE = drupalSettings?.canvas?.canvasAiMaxFileSize;

            for (const file of files) {
              if (file instanceof File && file.size > MAX_FILE_SIZE) {
                signals.onResponse({
                  text: `File is too large. Maximum allowed size is ${MAX_FILE_SIZE / (1024 * 1024)}MB.`,
                  role: 'error',
                });
                return;
              }
            }
            requestBody = body as FormData;
            requestBody.append(
              'entity_type',
              currentValuesRef.current.params.entityType || '',
            );
            requestBody.append(
              'entity_id',
              currentValuesRef.current.params.entityId || '',
            );
            requestBody.append(
              'selected_component',
              // Prefer the code-editor route param: it identifies the open
              // component immediately after navigation (e.g. right after
              // creating one), whereas the Redux machineName is only set once
              // the editor finishes its async data load.
              currentValuesRef.current.params.codeComponentId ||
                currentValuesRef.current.codeComponentName,
            );
            requestBody.append(
              'selected_component_required_props',
              JSON.stringify(
                currentValuesRef.current.codeComponentRequiredProps || [],
              ),
            );
            requestBody.append(
              'layout',
              JSON.stringify(
                Object.fromEntries(
                  Object.entries(selectModel(store.getState())).map(
                    ([uuid, comp]) => [uuid, comp.resolved],
                  ),
                ),
              ),
            );
            requestBody.append(
              'derived_proptypes',
              JSON.stringify(fixtureProps),
            );
            requestBody.append('request_id', requestId);
          } else {
            headers['Content-Type'] = 'application/json';
          }

          // Create a new AbortController for this request.
          const abortController = new AbortController();
          abortControllerRef.current = abortController;

          // Live progress is rendered into a single chat message that the hop
          // loop updates in place. Each hop's response carries the cumulative
          // narration (`data.progress`), so this is the only writer of the
          // message — no concurrent poller, hence no stale-index races.
          let progressIndex = -1;
          const renderProgress = (items: any[]) => {
            const chatEl = chatElementRef.current;
            const html = buildProgressHtml(items);
            if (!chatEl || !html) return;
            withStickyScroll(chatEl, () => {
              if (progressIndex < 0) {
                chatEl.addMessage({ html, role: 'ai' });
                progressIndex = chatEl.getMessages().length - 1;
              } else {
                try {
                  chatEl.updateMessage({ html }, progressIndex);
                } catch (error) {
                  console.warn('UpdateMessage failed:', error);
                  chatEl.addMessage({ html, role: 'ai' });
                  progressIndex = chatEl.getMessages().length - 1;
                }
              }
            });
          };
          const removeProgress = () => {
            const chatEl = chatElementRef.current;
            if (chatEl && progressIndex >= 0) {
              try {
                chatEl.updateMessage({ html: '' }, progressIndex);
                progressIndex = -1;
              } catch (error) {
                console.warn('Failed to remove progress message:', error);
              }
            }
          };

          // The orchestrator pauses after each tool decision and returns
          // `should_continue: true`. We re-POST the same conversation
          // (identified by request_id) to resume it, applying each hop's
          // result, until the orchestrator is finished.
          const runHops = async () => {
            let data: any;
            do {
              if (!hasFiles) {
                // Rebuild the body every hop: the previous hop's handlers may
                // have placed components or set page data, and the backend
                // needs the resulting state. Read from the Redux store — refs
                // and render-time values lag by a render cycle.
                const state = store.getState();
                const freshPageData = selectPageData(state);
                const freshModel = selectModel(state);
                requestBody = JSON.stringify({
                  ...body,
                  entity_type: currentValuesRef.current.params.entityType,
                  entity_id: currentValuesRef.current.params.entityId,
                  selected_component:
                    currentValuesRef.current.params.codeComponentId ||
                    currentValuesRef.current.codeComponentName,
                  selected_component_required_props:
                    currentValuesRef.current.codeComponentRequiredProps || [],
                  layout: JSON.stringify(
                    Object.fromEntries(
                      Object.entries(freshModel).map(([uuid, comp]) => [
                        uuid,
                        comp.resolved,
                      ]),
                    ),
                  ),
                  active_component_uuid:
                    (state as any)?.ui?.selection?.items[0] ?? '',
                  current_layout: transformLayout(),
                  derived_proptypes: fixtureProps,
                  page_title: freshPageData['title[0][value]'],
                  page_description: freshPageData['description[0][value]'],
                  request_id: requestId,
                });
              }
              const response = await fetch('/admin/api/canvas/ai', {
                method: 'POST',
                headers,
                body: requestBody,
                signal: abortController.signal,
              });
              if (!response.ok) {
                throw new Error(`HTTP error. Status: ${response.status}`);
              }
              data = await response.json();
              if (data.status === false) {
                throw new Error(
                  data.message ||
                    'An error occurred while processing your request. Please try again.',
                );
              }
              // Update the live progress message with this hop's narration.
              if (data.progress?.length) {
                renderProgress(data.progress);
              }
              // Apply this hop's side effects (component placement, page
              // data, etc.).
              const processedMessage = await receiveMessage(data);
              if (!data.should_continue) {
                // Final hop: persist the progress block to history (before the
                // answer, so a reload shows user → progress → answer), then
                // show the orchestrator's answer.
                if (data.progress?.length) {
                  const html = buildProgressHtml(data.progress);
                  if (html) {
                    historyStore.addMessage({ html, role: 'ai' });
                  }
                }
                await signals.onResponse(processedMessage);
                chatElementRef.current?.disableSubmitButton();
              }
            } while (data.should_continue);
          };

          // Freeze the history prop for the duration of the turn so mid-turn
          // re-renders cannot re-seed DeepChat and wipe the live progress
          // message. Released in `finally` once the hop loop settles.
          turnActiveRef.current = true;
          runHops()
            .catch((error) => {
              removeProgress();
              // Don't show error if request was aborted intentionally
              if (error.name === 'AbortError') {
                console.log('AI request was aborted');
                return;
              }
              console.error('AI request failed:', error);
              signals.onResponse({
                text: error.message
                  ? error.message
                  : 'An error occurred while processing your request. Please try again.',
                role: 'error',
              });
              setTimeout(() => {
                chatElementRef.current?.disableSubmitButton();
              }, 0);
            })
            .finally(() => {
              turnActiveRef.current = false;
            });
        } catch (error: any) {
          // Don't show error if request was aborted intentionally
          if (error.name === 'AbortError') {
            console.log('AI request was aborted');
            return;
          }
          console.error('AI request failed:', error);
          await signals.onResponse({
            text: 'An error occurred while processing your request. Please try again.',
            role: 'error',
          });
          setTimeout(() => {
            chatElementRef.current?.disableSubmitButton();
          }, 0);
        }
        setTimeout(() => {
          chatElementRef.current?.disableSubmitButton();
        }, 0);
      },
    }),
    [csrfToken, drupalSettings, store, transformLayout, receiveMessage],
  );

  return (
    csrfToken && (
      <Flex
        direction="column"
        align="stretch"
        gap="4"
        className={styles.aiWizard}
        onKeyDown={(e) => {
          e.stopPropagation();
        }}
      >
        <Flex direction="column" align="center">
          <Flex align="center">
            <AiWelcome />
          </Flex>
          <Flex direction="row" align="center" gap="0">
            <Box className={styles.aiWizardTitleContainer}>
              <Text className={styles.aiWizardTitle}>Drupal Canvas AI</Text>
              <Text className={styles.aiWizardBeta}>Beta</Text>
            </Box>
          </Flex>
          <Text ref={welcomeTextRef} className={styles.aiWizardSubtitle}>
            Hello, how can I help you today?
          </Text>
        </Flex>
        <MemoDeepChat
          ref={chatElementRef}
          history={frozenHistoryRef.current}
          images={DEEP_CHAT_IMAGES}
          requestBodyLimits={DEEP_CHAT_REQUEST_BODY_LIMITS}
          connect={connect}
          onInput={handleTextInput}
          onComponentRender={handleComponentRender}
          textInput={DEEP_CHAT_TEXT_INPUT}
          style={DEEP_CHAT_STYLE}
          messageStyles={DEEP_CHAT_MESSAGE_STYLES}
          submitButtonStyles={DEEP_CHAT_SUBMIT_BUTTON_STYLES}
          auxiliaryStyle={DEEP_CHAT_AUXILIARY_STYLE}
        />
        <Box className={styles.aiWizardLegalContainer}>
          <Text>
            These responses are generated by AI, which can make mistakes.
          </Text>
        </Box>
      </Flex>
    )
  );
};

export default AiWizard;
