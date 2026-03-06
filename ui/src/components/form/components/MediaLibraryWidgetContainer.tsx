import { memo } from 'react';
import clsx from 'clsx';

import { useAppSelector } from '@/app/hooks';
import { selectIsReordering } from '@/features/mediaLibrary/mediaLibrarySlice';
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
  isReordering: boolean;
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
    // Always block re-renders during active reordering.
    if (nextProps.isReordering) {
      return true;
    }
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
  const isReordering = useAppSelector(selectIsReordering);

  return (
    <MediaLibraryWidgetContainerInner
      attributes={attributes}
      renderChildren={children}
      isReordering={isReordering}
    />
  );
};

MediaLibraryWidgetContainer.displayName = 'MediaLibraryWidgetContainer';

export default MediaLibraryWidgetContainer;
