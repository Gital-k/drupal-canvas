import { isCanvasPreview } from 'drupal-canvas';

const IsCanvasPreviewIndicator = () => {
  const isPreview = isCanvasPreview();

  return (
    <div data-testid="isCanvasPreview-indicator" data-is-preview={isPreview}>
      <p>isCanvasPreview: {isPreview ? 'true' : 'false'}</p>
    </div>
  );
};

export default IsCanvasPreviewIndicator;
