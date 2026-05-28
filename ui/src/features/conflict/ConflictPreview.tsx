import { useMemo, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { Spinner } from '@radix-ui/themes';

import { useGetConflictPageLayoutQuery } from '@/services/componentAndLayout';
import { getViewportSizes } from '@/utils/viewports';

import styles from './ConflictPreview.module.css';

/**
 * A standalone conflict version preview component.
 *
 * Unlike the main PagePreview, this component is fully isolated from the
 * editor's Redux preview-update cycle. It fetches the layout HTML directly
 * via `useGetConflictPageLayoutQuery` (which has no side-effects) and renders
 * it in an iframe. The `?version=published` search param controls whether the
 * published version or the autosave (new) version is displayed.
 */
const ConflictPreview = () => {
  const { entityId, entityType, width } = useParams<{
    entityId: string;
    entityType: string;
    width?: string;
  }>();
  const [searchParams] = useSearchParams();
  const isPublished = searchParams.get('version') === 'published';
  const [widthVal, setWidthVal] = useState('100%');
  const viewportSizes = useMemo(() => getViewportSizes(), []);

  const { data, isFetching, isError } = useGetConflictPageLayoutQuery(
    entityId && entityType
      ? {
          entityId,
          entityType: entityType as 'canvas_page',
          publishedVersion: isPublished,
        }
      : { entityId: '', entityType: 'canvas_page', publishedVersion: false },
    { skip: !entityId || !entityType },
  );

  // Handle viewport width from URL param.
  useMemo(() => {
    if (!width || width === 'full') {
      setWidthVal('100%');
    } else {
      const vs = viewportSizes.find((v) => v.id === width);
      if (vs) {
        setWidthVal(`${vs.width}px`);
      }
    }
  }, [width, viewportSizes]);

  if (isError) {
    return (
      <div className={styles.container}>
        <p className={styles.error}>
          Unable to load the {isPublished ? 'published' : 'autosave'} version
          preview.
        </p>
      </div>
    );
  }

  if (isFetching || !data?.html) {
    return (
      <div className={styles.container}>
        <Spinner />
      </div>
    );
  }

  return (
    <div className={styles.container}>
      <iframe
        title={`Conflict ${isPublished ? 'published' : 'new'} version preview`}
        style={{ width: widthVal }}
        srcDoc={data.html}
        className={styles.iframe}
      />
    </div>
  );
};

export default ConflictPreview;