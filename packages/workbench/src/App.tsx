import { useEffect, useState } from 'react';

import { fetchDiscoveryResult } from '@/lib/discovery-client';

import type { DiscoveryResult } from '@/lib/discovery-client';

export function App() {
  const [result, setResult] = useState<DiscoveryResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let isMounted = true;

    void fetchDiscoveryResult()
      .then((response) => {
        if (!isMounted) {
          return;
        }
        setResult(response);
      })
      .catch((fetchError: unknown) => {
        if (!isMounted) {
          return;
        }

        setError(
          fetchError instanceof Error
            ? fetchError.message
            : 'Unknown discovery error.',
        );
      });

    return () => {
      isMounted = false;
    };
  }, []);

  if (error) {
    return <div>Discovery failed: {error}</div>;
  }

  if (!result) {
    return <div>Loading discovery...</div>;
  }

  return (
    <main>
      <h1>Canvas Workbench discovery prototype</h1>

      <p>
        Scan root: <code>{result.scanRoot}</code>
      </p>

      <p>
        Found {result.components.length} component
        {result.components.length === 1 ? '' : 's'} and {result.warnings.length}{' '}
        warning{result.warnings.length === 1 ? '' : 's'}.
      </p>

      <p>
        Scanned metadata files: {result.stats.scannedFiles}. Ignored by
        .gitignore: {result.stats.ignoredFiles}.
      </p>

      <h2>Components</h2>
      <ul>
        {result.components.map((component) => (
          <li key={component.id}>
            <strong>{component.name}</strong> ({component.kind})
            <div>Directory: {component.relativeDirectory}</div>
            <div>Metadata: {component.metadataPath}</div>
            <div>JS: {component.jsEntryPath ?? 'none'}</div>
            <div>CSS: {component.cssEntryPath ?? 'none'}</div>
          </li>
        ))}
      </ul>

      <h2>Warnings</h2>
      <ul>
        {result.warnings.map((warning, index) => (
          <li key={`${warning.code}-${warning.path ?? 'none'}-${index}`}>
            <strong>{warning.code}</strong>: {warning.message}
            {warning.path ? <div>Path: {warning.path}</div> : null}
          </li>
        ))}
      </ul>
    </main>
  );
}

export default App;
