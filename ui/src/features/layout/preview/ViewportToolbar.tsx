import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import ScaleToFitIcon from '@assets/icons/justify-stretch.svg?react';
import { Link2Icon } from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Tooltip } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import BreakpointIcon from '@/components/BreakpointIcon';
import ZoomControl from '@/components/zoom/ZoomControl';
import {
  scaleValues,
  selectViewportWidth,
  setEditorFrameViewPort,
  setViewportMinHeight,
  setViewportWidth,
} from '@/features/ui/uiSlice';
import { getHalfwayScrollPosition } from '@/utils/function-utils';
import { getViewportSizes } from '@/utils/viewports';

import type React from 'react';
import type { RefObject } from 'react';
import type { ScaleValue } from '@/features/ui/uiSlice';
import type { viewportSize } from '@/types/Preview';

import styles from './ViewportToolbar.module.css';

interface ViewportToolbarProps {
  editorPaneRef: RefObject<HTMLElement>;
  scalingContainerRef: RefObject<HTMLElement>;
}

const findClosestScaleValue = (desiredScale: number): ScaleValue => {
  // Filter the list to find all scales less than or equal to desiredScale. Remove an extra 0.01 from the scale to bias
  // towards dropping down a scale in case of an almost exact fit in the viewport (it looks nicer to have a bit of gap).
  const filteredScales = scaleValues.filter(
    (value) => value.scale <= desiredScale - 0.01,
  );

  // If there's any valid scale in the filtered list, return largest one.
  if (filteredScales.length > 0) {
    return filteredScales.reduce((prev, curr) =>
      curr.scale > prev.scale ? curr : prev,
    );
  }

  // If no scales are less than or equal to desiredScale, return the smallest available scale.
  return scaleValues[0];
};

const ViewportToolbar: React.FC<ViewportToolbarProps> = (props) => {
  const { editorPaneRef, scalingContainerRef } = props;
  const dispatch = useAppDispatch();
  const currentWidth = useAppSelector(selectViewportWidth);
  // Get viewport sizes (supports theme-level customization).
  const viewportSizes = useMemo(() => getViewportSizes(), []);

  // Auto scale-to-fit preference from localStorage (defaults to true)
  const [autoScaleToFit, setAutoScaleToFit] = useState(() => {
    const stored = localStorage.getItem('Canvas.editorFrame.autoScaleToFit');
    // Default to true if not set
    return stored === null ? true : stored === 'true';
  });

  // Track if initial auto-scale has been performed
  const hasAutoScaledOnMount = useRef(false);

  const getViewportByWidth = useCallback(
    (width: number): viewportSize => {
      const viewport = viewportSizes.find((vw) => vw.width === width);
      if (!viewport) {
        throw new Error(`No viewport found with width: ${width}`);
      }
      return viewport;
    },
    [viewportSizes],
  );

  const getViewportById = useCallback(
    (id: string): viewportSize => {
      const viewport = viewportSizes.find((vw) => vw.id === id);
      if (!viewport) {
        throw new Error(`No viewport found with id: ${id}`);
      }
      return viewport;
    },
    [viewportSizes],
  );

  // Calculate the optimal scale for a given viewport width
  const calculateOptimalScale = useCallback(
    (targetWidth: number): number => {
      if (!editorPaneRef.current) return 1;
      const editorFrameContainerWidth =
        editorPaneRef.current.getBoundingClientRect().width;
      const scaleToFit = editorFrameContainerWidth / targetWidth;
      const closestScale = findClosestScaleValue(scaleToFit);
      return closestScale.scale < 1 ? closestScale.scale : 1;
    },
    [editorPaneRef],
  );

  // Scale-to-fit function that updates both scale and position.
  // When scrollToTop is true, positions at the top of the preview (for initial page load).
  // When false, centers the preview vertically (for manual scale-to-fit clicks).
  const performScaleToFit = useCallback(
    (targetWidth: number, { scrollToTop = false } = {}) => {
      if (editorPaneRef.current && scalingContainerRef.current) {
        const newScale = calculateOptimalScale(targetWidth);
        const x = getHalfwayScrollPosition(editorPaneRef.current);

        let y: number;
        if (scrollToTop) {
          y = 0;
        } else {
          // Calculate position based on the NEW scale (without waiting for DOM update)
          const unscaledPreviewHeight =
            scalingContainerRef.current.scrollHeight;
          const newScaledPreviewHeight = unscaledPreviewHeight * newScale;

          // Calculate the center offset inside the editor frame
          const editorFrameHeight = editorPaneRef.current.scrollHeight;
          const centerOffset = (editorFrameHeight - newScaledPreviewHeight) / 2;
          y = centerOffset - 50;
        }

        dispatch(
          setEditorFrameViewPort({
            scale: newScale,
            x,
            y,
          }),
        );
      }
    },
    [calculateOptimalScale, dispatch, editorPaneRef, scalingContainerRef],
  );

  // Scale and center horizontally - for smooth resize animations
  // Preview stays anchored at top due to transform-origin: top center
  const performScaleOnly = useCallback(
    (targetWidth: number) => {
      if (editorPaneRef.current) {
        const newScale = calculateOptimalScale(targetWidth);
        const x = getHalfwayScrollPosition(editorPaneRef.current);
        dispatch(setEditorFrameViewPort({ scale: newScale, x }));
      }
    },
    [calculateOptimalScale, dispatch, editorPaneRef],
  );

  const handleScaleToFit = () => {
    performScaleToFit(currentWidth);
  };

  const handleWidthClick = (viewportSize: viewportSize) => {
    dispatch(setViewportWidth(viewportSize.width));
    dispatch(setViewportMinHeight(viewportSize.height));
    // Remember user's last chosen viewport size so it can persist across page reloads/navigation etc.
    localStorage.setItem('Canvas.editorFrame.viewportSize', viewportSize.id);

    // Auto-scale to fit when enabled
    if (autoScaleToFit) {
      // Use requestAnimationFrame to ensure the viewport width change has been applied
      requestAnimationFrame(() => {
        performScaleToFit(viewportSize.width);
      });
    }
  };

  // Toggle auto scale-to-fit preference
  const handleAutoScaleToggle = () => {
    const newValue = !autoScaleToFit;
    setAutoScaleToFit(newValue);
    localStorage.setItem('Canvas.editorFrame.autoScaleToFit', String(newValue));
    // Immediately scale to fit when enabling
    if (newValue) {
      handleScaleToFit();
    }
  };

  useLayoutEffect(() => {
    // Attempt to restore user's last viewport choice from localStorage
    const storedViewportId = localStorage.getItem(
      'Canvas.editorFrame.viewportSize',
    );
    let vs: viewportSize;
    if (currentWidth) {
      vs = getViewportByWidth(currentWidth);
    } else {
      vs = getViewportById(storedViewportId || 'tablet');
    }
    dispatch(setViewportWidth(vs.width));
    dispatch(setViewportMinHeight(vs.height));
  }, [currentWidth, dispatch, getViewportByWidth, getViewportById]);

  // Auto-scale to fit on initial load when enabled, scrolling to top of the page
  useEffect(() => {
    if (autoScaleToFit && currentWidth > 0 && !hasAutoScaledOnMount.current) {
      hasAutoScaledOnMount.current = true;
      // Double requestAnimationFrame to ensure DOM has fully rendered
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          performScaleToFit(currentWidth, { scrollToTop: true });
        });
      });
    }
  }, [autoScaleToFit, currentWidth, performScaleToFit]);

  // Watch for editor pane size changes (e.g., when layer column opens/closes)
  // and auto-scale if enabled. Debounced to wait for resize animations to complete.
  useEffect(() => {
    if (!autoScaleToFit || !editorPaneRef.current) {
      return;
    }

    let resizeTimeout: ReturnType<typeof setTimeout> | null = null;
    const currentEditorPane = editorPaneRef.current;

    const resizeObserver = new ResizeObserver(() => {
      // Only rescale if we've already done the initial scale
      if (hasAutoScaledOnMount.current && currentWidth > 0) {
        // Debounce to wait for resize animation to complete
        if (resizeTimeout) {
          clearTimeout(resizeTimeout);
        }
        resizeTimeout = setTimeout(() => {
          // Only update scale, not position - so preview scales in place smoothly
          performScaleOnly(currentWidth);
        }, 150);
      }
    });

    resizeObserver.observe(currentEditorPane);

    return () => {
      if (resizeTimeout) {
        clearTimeout(resizeTimeout);
      }
      resizeObserver.disconnect();
    };
  }, [autoScaleToFit, currentWidth, editorPaneRef, performScaleOnly]);

  return (
    <Flex
      className={styles.toolbar}
      gap="2"
      data-testid="canvas-editor-frame-controls"
    >
      <DropdownMenu.Root>
        <DropdownMenu.Trigger>
          <Button
            variant="surface"
            size="1"
            color="gray"
            className={`${styles.toolbarButton} ${styles.viewportSelect}`}
          >
            <BreakpointIcon width={currentWidth} />
            {currentWidth
              ? getViewportByWidth(currentWidth)?.name
              : 'Select viewport'}
            <DropdownMenu.TriggerIcon />
          </Button>
        </DropdownMenu.Trigger>
        <DropdownMenu.Content size="1">
          {viewportSizes.map((vs) => (
            <DropdownMenu.Item
              key={vs.name}
              onClick={() => handleWidthClick(vs)}
              color={vs.width === currentWidth ? 'blue' : undefined}
            >
              <BreakpointIcon width={vs.width} />
              {vs.name} ({vs.width}px)
            </DropdownMenu.Item>
          ))}
        </DropdownMenu.Content>
      </DropdownMenu.Root>
      <DropdownMenu.Root>
        <Tooltip
          side="bottom"
          content={autoScaleToFit ? 'Scale to fit (auto)' : 'Scale to fit'}
        >
          <Flex className={styles.scaleToFitButton}>
            <Button
              size="1"
              onClick={handleScaleToFit}
              color={autoScaleToFit ? 'blue' : 'gray'}
              variant="surface"
              highContrast={!autoScaleToFit}
              className={styles.toolbarButton}
              data-testid="scale-to-fit"
            >
              {autoScaleToFit ? <Link2Icon /> : <ScaleToFitIcon />}
            </Button>
            <DropdownMenu.Trigger>
              <Button
                size="1"
                color={autoScaleToFit ? 'blue' : 'gray'}
                variant="surface"
                className={styles.dropdownTrigger}
              >
                <DropdownMenu.TriggerIcon />
              </Button>
            </DropdownMenu.Trigger>
          </Flex>
        </Tooltip>
        <DropdownMenu.Content size="1">
          <DropdownMenu.Item onClick={handleAutoScaleToggle}>
            {autoScaleToFit ? 'Disable auto-scale' : 'Enable auto-scale'}
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Root>
      <ZoomControl buttonClass={styles.toolbarButton} />
    </Flex>
  );
};

export default ViewportToolbar;
