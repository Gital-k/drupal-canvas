import type { CanvasComponentTreeElement } from '@drupal-canvas/headless';

export function makeTree(url: URL): CanvasComponentTreeElement {
  const draft = url.pathname !== '/published' ? true : undefined;
  const revision = url.searchParams.get('revision') ?? '1';
  const label = url.searchParams.get('label') ?? 'Request';
  if (url.pathname === '/empty-top')
    return { element: 'div', canvasDraftMode: draft };
  const counter = (uuid: string): CanvasComponentTreeElement => ({
    element: 'js-counter',
    props: { canvasUuid: uuid, label: `${label} ${uuid} v${revision}` },
  });
  return {
    element: 'div',
    canvasDraftMode: draft,
    slots: {
      default: [
        {
          element: 'drupal-markup',
          slots: { default: '<header id="chrome">Page chrome</header>' },
        },
        {
          element: 'canvas-preview-content-region',
          slots: {
            default:
              url.pathname === '/empty'
                ? []
                : [
                    {
                      element: 'js-frame',
                      props: {
                        canvasUuid: 'frame-uuid',
                        title: `Outer v${revision}`,
                      },
                      slots: {
                        default: [
                          ...(revision === '2' ? [counter('added-uuid')] : []),
                          counter('primary-uuid'),
                        ],
                        aside: {
                          element: 'js-frame',
                          props: { canvasUuid: 'nested-uuid', title: 'Nested' },
                          slots: {
                            default: counter('secondary-uuid'),
                            aside: [],
                            footer: [],
                          },
                        },
                        footer:
                          '<strong id="default-markup" style="color: rgb(1, 2, 3)">Default trusted markup</strong>',
                      },
                    },
                  ],
          },
        },
        '<p id="trusted" style="color: rgb(4, 5, 6)">Trusted <em>HTML</em></p>',
      ],
    },
  };
}
