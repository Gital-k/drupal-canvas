import { memo } from 'react';
import clsx from 'clsx';

import { a2p } from '@/local_packages/utils';

import styles from './MediaLibraryWidgetContainer.module.css';

interface MediaLibraryWidgetContainerProps {
  attributes: {
    class?: string;
    [key: string]: any;
  };
  renderChildren: React.ReactNode;
}

const MediaLibraryWidgetContainer = memo(
  ({ attributes, renderChildren }: MediaLibraryWidgetContainerProps) => {
    const classes = clsx(attributes.class, styles.container);

    return (
      <div
        {...a2p(attributes, {}, { skipAttributes: ['class'] })}
        className={classes}
      >
        {renderChildren}
      </div>
    );
  },
  (prevProps, nextProps) => {
    if (typeof document !== 'undefined') {
      const selection = document.querySelector(
        '[data-canvas-media-library-selection]',
      );

      // Always block re-renders during active reordering
      if (selection?.hasAttribute('data-canvas-reordering')) {
        return true;
      }

      // After reordering completes, only block re-renders if props haven't changed
      // This prevents auto-save from causing re-renders, but allows remove button to work
      const lastReorderTime = selection?.getAttribute('data-last-reorder-time');
      if (lastReorderTime) {
        const elapsed = Date.now() - parseInt(lastReorderTime, 10);
        if (elapsed < 15000) {
          // Only block if props are the same (auto-save scenario)
          // If props changed (user removed item), allow re-render
          return (
            prevProps.renderChildren === nextProps.renderChildren &&
            prevProps.attributes === nextProps.attributes
          );
        }
      }
    }
    return (
      prevProps.renderChildren === nextProps.renderChildren &&
      prevProps.attributes === nextProps.attributes
    );
  },
);

MediaLibraryWidgetContainer.displayName = 'MediaLibraryWidgetContainer';

export default MediaLibraryWidgetContainer;
