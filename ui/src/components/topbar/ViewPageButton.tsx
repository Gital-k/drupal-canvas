import clsx from 'clsx';
import { useLocation, useParams } from 'react-router-dom';
import { OpenInNewWindowIcon } from '@radix-ui/react-icons';
import { Tooltip } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { CANVAS_PAGE_ENTITY_TYPE } from '@/constants/canvasEntityTypes';
import {
  useGetPageLayoutQuery,
  useGetTemplateLayoutQuery,
} from '@/services/componentAndLayout';
import { getBaseUrl } from '@/utils/drupal-globals';

import bellStyles from '@/features/notifications/NotificationBell.module.css';
import styles from './ViewPageButton.module.css';

const PATH_ALIAS_KEY = 'path[0][alias]' as const;
const ARIA_LABEL = 'View page';

const TOOLTIP = {
  enabled: 'View page in a new tab',
  loading: 'Loading page data...',
  error: 'Unable to load page data',
  unpublishedCanvas: 'Publish the page to view it outside the editor.',
} as const;

type ViewPageRouteParams = {
  entityType?: string;
  entityId?: string;
  bundle?: string;
  viewMode?: string;
  previewEntityId?: string;
};

type ViewPageContext =
  | { kind: 'none' }
  | { kind: 'entity'; entityType: string; entityId: string }
  | {
      kind: 'template';
      entityType: string;
      bundle: string;
      viewMode: string;
      previewEntityId: string;
    };

function resolveViewPageContext(
  pathname: string,
  params: ViewPageRouteParams,
): ViewPageContext {
  if (pathname.includes('/preview')) {
    return { kind: 'none' };
  }

  const { entityType, entityId, bundle, viewMode, previewEntityId } = params;

  if (entityType && entityId && pathname.includes('/editor/')) {
    return { kind: 'entity', entityType, entityId };
  }

  if (
    entityType &&
    bundle &&
    previewEntityId &&
    viewMode === 'full' &&
    pathname.includes('/template/')
  ) {
    return {
      kind: 'template',
      entityType,
      bundle,
      viewMode,
      previewEntityId,
    };
  }

  return { kind: 'none' };
}

function disabledTooltip(
  loading: boolean,
  errored: boolean,
  published: boolean,
  isCanvasPage: boolean,
): string {
  if (loading) return TOOLTIP.loading;
  if (errored) return TOOLTIP.error;
  if (!published && isCanvasPage) return TOOLTIP.unpublishedCanvas;
  return '';
}

/**
 * Builds the public site URL for the given entity using the path alias when present.
 */
export function buildViewPageHref(
  entityType: string,
  targetEntityId: string,
  entityFormFields: Record<string, unknown> | undefined,
): string {
  const originAndBase = `${window.location.origin}${getBaseUrl() || '/'}`;
  const alias = entityFormFields?.[PATH_ALIAS_KEY];
  if (typeof alias === 'string' && alias.trim() !== '') {
    return `${originAndBase}${alias.replace(/^\//, '')}`;
  }
  return `${originAndBase}${entityType}/${targetEntityId}`;
}

const ViewPageButton = () => {
  const { pathname } = useLocation();
  const params = useParams() as ViewPageRouteParams;
  const ctx = resolveViewPageContext(pathname, params);

  const pageArg =
    ctx.kind === 'entity'
      ? { entityId: ctx.entityId, entityType: ctx.entityType }
      : skipToken;
  const templateArg =
    ctx.kind === 'template'
      ? {
          entityType: ctx.entityType,
          bundle: ctx.bundle,
          viewMode: ctx.viewMode,
          previewEntityId: ctx.previewEntityId,
        }
      : skipToken;

  const pageQuery = useGetPageLayoutQuery(pageArg);
  const templateQuery = useGetTemplateLayoutQuery(templateArg);

  if (ctx.kind === 'none') {
    return null;
  }

  const layout = ctx.kind === 'entity' ? pageQuery.data : templateQuery.data;
  const loading =
    ctx.kind === 'entity' ? pageQuery.isLoading : templateQuery.isLoading;
  const errored =
    ctx.kind === 'entity' ? pageQuery.isError : templateQuery.isError;

  const targetId = ctx.kind === 'entity' ? ctx.entityId : ctx.previewEntityId;
  const href = buildViewPageHref(
    ctx.entityType,
    targetId,
    layout?.entity_form_fields,
  );

  const published = layout?.isPublished === true;
  const isCanvasPage =
    ctx.kind === 'entity' && ctx.entityType === CANVAS_PAGE_ENTITY_TYPE;
  const canOpen = !loading && !errored && (published || !isCanvasPage);
  const tooltip = canOpen
    ? TOOLTIP.enabled
    : disabledTooltip(loading, errored, published, isCanvasPage);

  return (
    <Tooltip content={tooltip}>
      {canOpen ? (
        <a
          href={href}
          className={clsx(bellStyles.bellButton, styles.linkAsIcon)}
          target="_blank"
          rel="noopener noreferrer"
          aria-label={ARIA_LABEL}
        >
          <OpenInNewWindowIcon width={16} height={16} />
        </a>
      ) : (
        <span className={styles.triggerWrap}>
          <button
            type="button"
            className={clsx(bellStyles.bellButton, styles.disabledState)}
            disabled
            aria-label={ARIA_LABEL}
          >
            <OpenInNewWindowIcon width={16} height={16} />
          </button>
        </span>
      )}
    </Tooltip>
  );
};

export default ViewPageButton;
