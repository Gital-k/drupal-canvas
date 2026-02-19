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

    }
    return (
      prevProps.renderChildren === nextProps.renderChildren &&
      prevProps.attributes === nextProps.attributes
    );
  },
);

MediaLibraryWidgetContainer.displayName = 'MediaLibraryWidgetContainer';

export default MediaLibraryWidgetContainer;
