import { Component, useEffect, useRef, useState } from 'react';
import { resolvePreviewComponent } from '@wb/lib/preview-component-resolver';
import { isPreviewRenderRequest } from '@wb/lib/preview-contract';

import type { ErrorInfo, ReactNode } from 'react';
import type { ComponentType } from 'react';
import type {
  PreviewFrameError,
  PreviewFrameReady,
  PreviewFrameRendered,
  PreviewRenderRequest,
} from '@wb/lib/preview-contract';

function postFrameMessage(
  message: PreviewFrameReady | PreviewFrameRendered | PreviewFrameError,
): void {
  window.parent.postMessage(message, window.location.origin);
}

function applyComponentStylesheet(cssUrl: string | null): void {
  const existing = document.querySelector<HTMLLinkElement>(
    'link[data-canvas-preview-css="component"]',
  );

  if (!cssUrl) {
    existing?.remove();
    return;
  }

  if (existing) {
    existing.href = cssUrl;
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = cssUrl;
  link.dataset.canvasPreviewCss = 'component';
  document.head.appendChild(link);
}

interface RenderableState {
  componentId: string;
  component: ComponentType;
  props: Record<string, unknown>;
}

function RenderSignal({
  componentId,
  onRendered,
}: {
  componentId: string;
  onRendered: (componentId: string) => void;
}) {
  useEffect(() => {
    onRendered(componentId);
  }, [componentId, onRendered]);

  return null;
}

class FrameRenderBoundary extends Component<
  {
    componentId: string | null;
    onError: (message: string, componentId: string | null) => void;
    children: ReactNode;
  },
  { hasError: boolean }
> {
  state = {
    hasError: false,
  };

  static getDerivedStateFromError(): { hasError: boolean } {
    return { hasError: true };
  }

  componentDidCatch(error: unknown, _errorInfo: ErrorInfo): void {
    this.props.onError(
      error instanceof Error ? error.message : String(error),
      this.props.componentId,
    );
  }

  componentDidUpdate(
    prevProps: Readonly<{ componentId: string | null }>,
  ): void {
    if (
      prevProps.componentId !== this.props.componentId &&
      this.state.hasError
    ) {
      this.setState({ hasError: false });
    }
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <div>Component rendering failed. See parent status for details.</div>
      );
    }

    return this.props.children;
  }
}

export function PreviewFrameApp() {
  const [activeRender, setActiveRender] = useState<RenderableState | null>(
    null,
  );
  const [status, setStatus] = useState('Waiting for component selection...');
  const loadedGlobalCssUrlRef = useRef<string | null>(null);

  useEffect(() => {
    postFrameMessage({
      source: 'canvas-workbench-frame',
      type: 'preview:ready',
    });

    const handleMessage = (event: MessageEvent<unknown>) => {
      if (event.origin !== window.location.origin) {
        return;
      }

      if (!isPreviewRenderRequest(event.data)) {
        return;
      }

      void handleRenderRequest(event.data);
    };

    window.addEventListener('message', handleMessage);
    return () => {
      window.removeEventListener('message', handleMessage);
    };
  }, []);

  async function handleRenderRequest(
    request: PreviewRenderRequest,
  ): Promise<void> {
    try {
      setStatus(`Loading ${request.payload.componentId}...`);
      if (
        request.payload.globalCssUrl &&
        loadedGlobalCssUrlRef.current !== request.payload.globalCssUrl
      ) {
        await import(/* @vite-ignore */ request.payload.globalCssUrl);
        loadedGlobalCssUrlRef.current = request.payload.globalCssUrl;
      }
      applyComponentStylesheet(request.payload.cssUrl);

      const loadedModule = (await import(
        /* @vite-ignore */ request.payload.moduleUrl
      )) as Record<string, unknown>;
      const { component: renderableComponent, reason } =
        resolvePreviewComponent(loadedModule);
      if (!renderableComponent) {
        throw new Error(
          reason ?? 'Module does not export a renderable component.',
        );
      }

      setActiveRender({
        componentId: request.payload.componentId,
        component: renderableComponent,
        props: request.payload.props,
      });
      setStatus(`Loaded ${request.payload.componentId}, rendering...`);
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : `Unknown render error: ${String(error)}`;
      setStatus(message);
      setActiveRender(null);
      postFrameMessage({
        source: 'canvas-workbench-frame',
        type: 'preview:error',
        payload: {
          componentId: request.payload.componentId,
          message,
        },
      });
    }
  }

  const ActiveComponent = activeRender?.component ?? null;

  return (
    <main style={{ padding: '1rem' }}>
      <p style={{ opacity: 0.75, fontSize: '0.875rem' }}>{status}</p>
      <FrameRenderBoundary
        componentId={activeRender?.componentId ?? null}
        onError={(message, componentId) => {
          postFrameMessage({
            source: 'canvas-workbench-frame',
            type: 'preview:error',
            payload: {
              componentId,
              message,
            },
          });
          setStatus(message);
        }}
      >
        {ActiveComponent && activeRender ? (
          <>
            <RenderSignal
              componentId={activeRender.componentId}
              onRendered={(componentId) => {
                setStatus(`Rendered ${componentId}.`);
                postFrameMessage({
                  source: 'canvas-workbench-frame',
                  type: 'preview:rendered',
                  payload: {
                    componentId,
                  },
                });
              }}
            />
            <ActiveComponent {...activeRender.props} />
          </>
        ) : (
          <div>No preview rendered yet. Select a component from Workbench.</div>
        )}
      </FrameRenderBoundary>
    </main>
  );
}

export default PreviewFrameApp;
