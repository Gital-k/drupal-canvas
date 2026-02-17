import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router';
import { Badge } from '@wb/components/ui/badge';
import { Button } from '@wb/components/ui/button';
import { Separator } from '@wb/components/ui/separator';
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarInset,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarProvider,
  SidebarRail,
  SidebarTrigger,
} from '@wb/components/ui/sidebar';
import { fetchDiscoveryResult } from '@wb/lib/discovery-client';
import { fetchPreviewManifest } from '@wb/lib/preview-client';
import { isPreviewFrameEvent } from '@wb/lib/preview-contract';

import type { DiscoveryResult } from '@wb/lib/discovery-client';
import type {
  PreviewManifest,
  PreviewManifestComponent,
  PreviewRenderRequest,
} from '@wb/lib/preview-contract';

const SIDEBAR_COOKIE_NAME = 'sidebar_state';

function getSidebarDefaultOpen(): boolean {
  if (typeof document === 'undefined') {
    return true;
  }

  const cookieEntry = document.cookie
    .split('; ')
    .find((entry) => entry.startsWith(`${SIDEBAR_COOKIE_NAME}=`));
  if (!cookieEntry) {
    return true;
  }

  return cookieEntry.split('=')[1] !== 'false';
}

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
  const [searchParams, setSearchParams] = useSearchParams();
  const [discoveryResult, setDiscoveryResult] =
    useState<DiscoveryResult | null>(null);
  const [previewManifest, setPreviewManifest] =
    useState<PreviewManifest | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isFrameReady, setIsFrameReady] = useState(false);
  const [frameStatus, setFrameStatus] = useState('Waiting for frame.');
  const [iframeKey, setIframeKey] = useState(0);
  const iframeRef = useRef<HTMLIFrameElement | null>(null);
  const [sidebarDefaultOpen] = useState(getSidebarDefaultOpen);

  const selectedComponentId = searchParams.get('id');

  const loadWorkbenchData = useCallback(async (): Promise<{
    discovery: DiscoveryResult;
    manifest: PreviewManifest;
  }> => {
    const [discovery, manifest] = await Promise.all([
      fetchDiscoveryResult(),
      fetchPreviewManifest(),
    ]);

    return {
      discovery,
      manifest,
    };
  }, []);

  const selectedComponent = useMemo<PreviewManifestComponent | null>(() => {
    if (!previewManifest) {
      return null;
    }

    if (selectedComponentId) {
      const routedComponent = previewManifest.components.find(
        (component) => component.id === selectedComponentId,
      );
      if (routedComponent) {
        return routedComponent;
      }
    }

    const fallbackId = pickInitialSelection(previewManifest);
    if (!fallbackId) {
      return null;
    }

    return (
      previewManifest.components.find(
        (component) => component.id === fallbackId,
      ) ?? null
    );
  }, [previewManifest, selectedComponentId]);

  const sortedComponents = useMemo<PreviewManifestComponent[]>(() => {
    if (!previewManifest) {
      return [];
    }

    return [...previewManifest.components].sort((componentA, componentB) =>
      componentA.name.localeCompare(componentB.name),
    );
  }, [previewManifest]);

  useEffect(() => {
    let isMounted = true;

    void loadWorkbenchData()
      .then(({ discovery, manifest }) => {
        if (!isMounted) {
          return;
        }

        setDiscoveryResult(discovery);
        setPreviewManifest(manifest);
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
  }, [loadWorkbenchData]);

  useEffect(() => {
    if (!import.meta.hot) {
      return;
    }

    const onWorkbenchUpdate = (
      payload:
        | {
            reloadFrameOnly?: boolean;
          }
        | undefined,
    ) => {
      if (payload?.reloadFrameOnly) {
        setIsFrameReady(false);
        setFrameStatus('Detected source change. Reloading frame...');
        setIframeKey((value) => value + 1);
        return;
      }

      setFrameStatus('Detected metadata change. Refreshing Workbench...');
      void loadWorkbenchData()
        .then(({ discovery, manifest }) => {
          setDiscoveryResult(discovery);
          setPreviewManifest(manifest);
          setIsFrameReady(false);
          setIframeKey((value) => value + 1);
        })
        .catch((refreshError: unknown) => {
          setError(
            refreshError instanceof Error
              ? refreshError.message
              : 'Unknown workbench loading error.',
          );
        });
    };

    import.meta.hot.on('canvas:workbench:update', onWorkbenchUpdate);
    return () => {
      import.meta.hot?.off('canvas:workbench:update', onWorkbenchUpdate);
    };
  }, [loadWorkbenchData]);

  useEffect(() => {
    if (!previewManifest) {
      return;
    }

    const hasSelectedRoute = Boolean(
      selectedComponentId &&
      previewManifest.components.some(
        (component) => component.id === selectedComponentId,
      ),
    );
    if (hasSelectedRoute) {
      return;
    }

    const fallbackId = pickInitialSelection(previewManifest);
    const nextParams = new URLSearchParams(searchParams);
    if (!fallbackId) {
      if (nextParams.has('id')) {
        nextParams.delete('id');
        setSearchParams(nextParams, { replace: true });
      }
      return;
    }

    nextParams.set('id', fallbackId);
    setSearchParams(nextParams, { replace: true });
  }, [previewManifest, searchParams, selectedComponentId, setSearchParams]);

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
    <SidebarProvider defaultOpen={sidebarDefaultOpen}>
      <Sidebar>
        <SidebarHeader className="border-b">
          <h1 className="px-2 text-sm font-semibold">Canvas Workbench</h1>
          <p className="px-2 text-xs text-muted-foreground">
            {previewManifest.components.length} discovered components
          </p>
          <p className="px-2 text-xs text-muted-foreground">
            Scanned: {discoveryResult.stats.scannedFiles}, ignored:{' '}
            {discoveryResult.stats.ignoredFiles}
          </p>
        </SidebarHeader>

        <SidebarContent>
          <SidebarGroup>
            <SidebarGroupLabel>Components</SidebarGroupLabel>
            <SidebarGroupContent>
              <SidebarMenu>
                {sortedComponents.map((component) => (
                  <SidebarMenuItem key={component.id}>
                    <SidebarMenuButton
                      isActive={component.id === selectedComponent?.id}
                      onClick={() => {
                        const nextParams = new URLSearchParams(searchParams);
                        nextParams.set('id', component.id);
                        setSearchParams(nextParams);
                      }}
                    >
                      <span>{component.name}</span>
                      {!component.previewable ? (
                        <Badge variant="destructive">No preview</Badge>
                      ) : null}
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                ))}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>

          {previewManifest.warnings.length > 0 ? (
            <SidebarGroup>
              <SidebarGroupLabel>Warnings</SidebarGroupLabel>
              <SidebarGroupContent>
                <ul className="space-y-1 px-2 text-xs text-muted-foreground">
                  {previewManifest.warnings.map((warning, index) => (
                    <li
                      key={`${warning.code}-${warning.path ?? 'none'}-${index}`}
                    >
                      <strong>{warning.code}</strong>: {warning.message}
                    </li>
                  ))}
                </ul>
              </SidebarGroupContent>
            </SidebarGroup>
          ) : null}
        </SidebarContent>

        <SidebarRail />
      </Sidebar>

      <SidebarInset className="min-w-0">
        <header className="flex h-14 shrink-0 items-center justify-between border-b px-4">
          <div className="flex min-w-0 items-center gap-2">
            <SidebarTrigger className="-ml-1" />
            <Separator orientation="vertical" className="h-4" />
            <div className="min-w-0">
              <h2 className="truncate text-sm font-semibold">
                {selectedComponent ? selectedComponent.name : 'No selection'}
              </h2>
              <p className="truncate text-xs text-muted-foreground">
                {frameStatus}
              </p>
            </div>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              setIsFrameReady(false);
              setFrameStatus('Reloading frame...');
              setIframeKey((value) => value + 1);
            }}
          >
            Reload frame
          </Button>
        </header>

        <section className="flex min-w-0 flex-1 flex-col gap-3 overflow-y-auto p-4">
          {!selectedComponent ? (
            <div className="rounded-none border p-4">
              No components were discovered.
            </div>
          ) : !selectedComponent.previewable ? (
            <div className="rounded-none border p-4">
              This component is not previewable in strict mode. Reason:{' '}
              {selectedComponent.ineligibilityReason}
            </div>
          ) : (
            <div className="min-h-[50vh] rounded-none border">
              <iframe
                key={iframeKey}
                ref={iframeRef}
                title="Canvas component preview"
                src="/__canvas/preview-frame"
                className="h-full w-full"
                sandbox="allow-scripts allow-same-origin"
              />
            </div>
          )}

          {selectedComponent ? (
            <section className="rounded-none border p-3">
              <h3 className="text-sm font-semibold">
                Example props (first examples)
              </h3>
              <pre className="mt-2 overflow-auto rounded-none bg-black/5 p-2 text-xs">
                {JSON.stringify(selectedComponent.exampleProps, null, 2)}
              </pre>
            </section>
          ) : null}

          <section className="rounded-none border p-3">
            <h3 className="text-sm font-semibold">Preview runtime debug</h3>
            <pre className="mt-2 overflow-auto rounded-none bg-black/5 p-2 text-xs">
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
        </section>
      </SidebarInset>
    </SidebarProvider>
  );
}

export default App;
