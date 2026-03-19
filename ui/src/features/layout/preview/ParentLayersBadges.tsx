import { Component1Icon } from '@radix-ui/react-icons';

import type { ComponentLayerItem } from '@/features/layout/layoutUtils';

import styles from './ParentLayersBadges.module.css';

export interface ParentLayersBadgesProps {
  layers: ComponentLayerItem[];
  onSelectLayer: (uuid: string) => void;
  onHighlightAncestor?: (uuid: string) => void;
  onUnhighlightAncestor?: () => void;
}

/**
 * Vertical stack of badges showing the component hierarchy (root at top, current at bottom).
 * Each badge is clickable to select that component.
 */
export function ParentLayersBadges(props: ParentLayersBadgesProps) {
  const { layers, onSelectLayer, onHighlightAncestor, onUnhighlightAncestor } =
    props;

  if (layers.length === 0) {
    return null;
  }

  const layerNames = layers.map((l) => l.name).join(', ');
  const ariaLabel = `Component hierarchy: ${layerNames}`;

  return (
    <div
      className={styles.stack}
      role="list"
      aria-label={ariaLabel}
      data-testid="canvas-parent-layers-badges"
    >
      {layers.map((layer) => (
        <button
          key={layer.uuid}
          type="button"
          className={styles.badge}
          onClick={(e) => {
            e.stopPropagation();
            onSelectLayer(layer.uuid);
          }}
          onMouseEnter={() => onHighlightAncestor?.(layer.uuid)}
          onMouseLeave={() => onUnhighlightAncestor?.()}
          aria-label={`Select ${layer.name}`}
          title={layer.name}
        >
          <Component1Icon width={10} height={10} aria-hidden />
          <span>{layer.name}</span>
        </button>
      ))}
    </div>
  );
}
