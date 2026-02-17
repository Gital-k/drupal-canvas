import { useEffect, useMemo, useRef, useState } from 'react';
import {
  fetchDiscoveryResult,
  fetchPreviewManifest,
} from '@wb/lib/discovery-client';
import { isPreviewFrameEvent } from '@wb/lib/preview-contract';

import type { DiscoveryResult } from '@wb/lib/discovery-client';
import type {
  PreviewManifest,
  PreviewManifestComponent,
  PreviewRenderRequest,
} from '@wb/lib/preview-contract';

function pickInitialSelection(manifest: PreviewManifest): string | null {
  const firstPreviewable = manifest.components.find(
    (component) => component.previewable,
  );
  if (firstPreviewable) {
    return firstPreviewable.id;
  }

  return manifest.components[0]?.id ?? null;
}

export function App() {
  const [discoveryResult, setDiscoveryResult] =
    useState<DiscoveryResult | null>(null);
  const [previewManifest, setPreviewManifest] =
    useState<PreviewManifest | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [selectedComponentId, setSelectedComponentId] = useState<string | null>(
    null,
  );
  const [isFrameReady, setIsFrameReady] = useState(false);
  const [frameStatus, setFrameStatus] = useState('Waiting for frame.');
  const [iframeKey, setIframeKey] = useState(0);
  const iframeRef = useRef<HTMLIFrameElement | null>(null);

  const selectedComponent = useMemo<PreviewManifestComponent | null>(() => {
    if (!previewManifest || !selectedComponentId) {
      return null;
    }

    return (
      previewManifest.components.find(
        (component) => component.id === selectedComponentId,
      ) ?? null
    );
  }, [previewManifest, selectedComponentId]);

  useEffect(() => {
    let isMounted = true;

    void Promise.all([fetchDiscoveryResult(), fetchPreviewManifest()])
      .then(([discovery, manifest]) => {
        if (!isMounted) {
          return;
        }

        setDiscoveryResult(discovery);
        setPreviewManifest(manifest);
        setSelectedComponentId(pickInitialSelection(manifest));
      })
      .catch((fetchError: unknown) => {
        if (!isMounted) {
          return;
        }

        setError(
          fetchError instanceof Error
            ? fetchError.message
            : 'Unknown workbench loading error.',
        );
      });

    return () => {
      isMounted = false;
    };
  }, []);

  useEffect(() => {
    const handleFrameMessage = (event: MessageEvent<unknown>) => {
      if (event.origin !== window.location.origin) {
        return;
      }

      if (!isPreviewFrameEvent(event.data)) {
        return;
      }

      if (event.data.type === 'preview:ready') {
        setIsFrameReady(true);
        setFrameStatus('Frame ready.');
        return;
      }

      if (event.data.type === 'preview:rendered') {
        setFrameStatus(`Rendered component ${event.data.payload.componentId}.`);
        return;
      }

      if (event.data.type === 'preview:error') {
        setFrameStatus(event.data.payload.message);
      }
    };

    window.addEventListener('message', handleFrameMessage);
    return () => {
      window.removeEventListener('message', handleFrameMessage);
    };
  }, []);

  useEffect(() => {
    if (!selectedComponent || !selectedComponent.previewable || !isFrameReady) {
      return;
    }

    const frameWindow = iframeRef.current?.contentWindow;
    if (!frameWindow || !selectedComponent.moduleUrl) {
      return;
    }

    const message: PreviewRenderRequest = {
      source: 'canvas-workbench-parent',
      type: 'preview:render',
      payload: {
        componentId: selectedComponent.id,
        moduleUrl: selectedComponent.moduleUrl,
        cssUrl: selectedComponent.cssUrl,
        globalCssUrl: previewManifest?.globalCssUrl ?? null,
        props: selectedComponent.exampleProps,
      },
    };

    setFrameStatus(`Rendering component ${selectedComponent.name}...`);
    frameWindow.postMessage(message, window.location.origin);
  }, [isFrameReady, previewManifest, selectedComponent]);

  if (error) {
    return <div>Workbench failed: {error}</div>;
  }

  if (!discoveryResult || !previewManifest) {
    return <div>Loading Workbench preview data...</div>;
  }

  return (
    <main className="grid min-h-screen grid-cols-[320px_1fr]">
      <aside className="border-r p-4">
        <h1 className="text-xl font-semibold">Canvas Workbench</h1>
        <p className="text-sm opacity-75">
          {previewManifest.components.length} discovered components
        </p>
        <p className="text-sm opacity-75">
          Scanned: {discoveryResult.stats.scannedFiles}, ignored:{' '}
          {discoveryResult.stats.ignoredFiles}
        </p>

        <div className="mt-4 flex flex-col gap-2">
          {previewManifest.components.map((component) => (
            <button
              key={component.id}
              type="button"
              className="rounded border p-2 text-left"
              data-selected={component.id === selectedComponentId}
              onClick={() => setSelectedComponentId(component.id)}
            >
              <div className="font-medium">{component.name}</div>
              <div className="text-xs opacity-70">
                {component.relativeDirectory}
              </div>
              <div className="text-xs">
                {component.previewable
                  ? 'Previewable'
                  : `Unsupported: ${component.ineligibilityReason}`}
              </div>
            </button>
          ))}
        </div>

        {previewManifest.warnings.length > 0 ? (
          <section className="mt-4">
            <h2 className="text-sm font-semibold">Warnings</h2>
            <ul className="mt-2 text-xs">
              {previewManifest.warnings.map((warning, index) => (
                <li key={`${warning.code}-${warning.path ?? 'none'}-${index}`}>
                  <strong>{warning.code}</strong>: {warning.message}
                </li>
              ))}
            </ul>
          </section>
        ) : null}
      </aside>

      <section className="flex min-h-screen flex-col p-4">
        <header className="mb-2 flex items-center justify-between">
          <div>
            <h2 className="text-lg font-semibold">
              {selectedComponent ? selectedComponent.name : 'No selection'}
            </h2>
            <p className="text-sm opacity-70">{frameStatus}</p>
          </div>
          <button
            type="button"
            className="rounded border px-3 py-1"
            onClick={() => {
              setIsFrameReady(false);
              setFrameStatus('Reloading frame...');
              setIframeKey((value) => value + 1);
            }}
          >
            Reload frame
          </button>
        </header>

        {selectedComponent ? (
          <section className="mb-3 rounded border p-3">
            <h3 className="text-sm font-semibold">
              Example props (first examples)
            </h3>
            <pre className="mt-2 overflow-auto rounded bg-black/5 p-2 text-xs">
              {JSON.stringify(selectedComponent.exampleProps, null, 2)}
            </pre>
          </section>
        ) : null}

        <section className="mb-3 rounded border p-3">
          <h3 className="text-sm font-semibold">Preview runtime debug</h3>
          <pre className="mt-2 overflow-auto rounded bg-black/5 p-2 text-xs">
            {JSON.stringify(
              {
                globalCssUrl: previewManifest.globalCssUrl,
                selectedComponentId: selectedComponent?.id ?? null,
                selectedModuleUrl: selectedComponent?.moduleUrl ?? null,
                selectedComponentCssUrl: selectedComponent?.cssUrl ?? null,
                frameReady: isFrameReady,
                frameStatus,
              },
              null,
              2,
            )}
          </pre>
        </section>

        {!selectedComponent ? (
          <div className="rounded border p-4">
            No components were discovered.
          </div>
        ) : !selectedComponent.previewable ? (
          <div className="rounded border p-4">
            This component is not previewable in strict mode. Reason:{' '}
            {selectedComponent.ineligibilityReason}
          </div>
        ) : (
          <iframe
            key={iframeKey}
            ref={iframeRef}
            title="Canvas component preview"
            src="/__canvas/preview-frame"
            className="h-[80vh] w-full rounded border"
            sandbox="allow-scripts allow-same-origin"
          />
        )}
      </section>
    </main>
  );
}

export default App;
