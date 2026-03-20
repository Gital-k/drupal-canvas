import { memo } from 'react';
import clsx from 'clsx';

import { a2p } from '@/local_packages/utils';

import styles from './MediaLibraryWidgetContainer.module.css';

interface MediaLibraryWidgetContainerProps {
  attributes: {
    class?: string;
    [key: string]: any;
  };
  children: React.ReactNode;
}

interface MediaLibraryWidgetContainerInnerProps {
  attributes: {
    class?: string;
    [key: string]: any;
  };
  renderChildren: React.ReactNode;
}

const MediaLibraryWidgetContainerInner = memo(
  ({ attributes, renderChildren }: MediaLibraryWidgetContainerInnerProps) => {
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
  (
    prevProps: MediaLibraryWidgetContainerInnerProps,
    nextProps: MediaLibraryWidgetContainerInnerProps,
  ) => {
    return (
      prevProps.renderChildren === nextProps.renderChildren &&
      prevProps.attributes === nextProps.attributes
    );
  },
);

MediaLibraryWidgetContainerInner.displayName =
  'MediaLibraryWidgetContainerInner';

const MediaLibraryWidgetContainer = ({
  attributes,
  children,
}: MediaLibraryWidgetContainerProps) => {
  return (
    <MediaLibraryWidgetContainerInner
      attributes={attributes}
      renderChildren={children}
    />
  );
};

MediaLibraryWidgetContainer.displayName = 'MediaLibraryWidgetContainer';

export default MediaLibraryWidgetContainer;
