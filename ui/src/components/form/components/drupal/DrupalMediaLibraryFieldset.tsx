import { useEffect, useRef, useState } from 'react';

import { getDrupal } from '@/utils/drupal-globals';

const DrupalMediaLibraryFieldset = ({
  children,
}: {
  children: React.ReactNode;
}) => {
  const Drupal = getDrupal();
  const wrapRef = useRef<HTMLDivElement>(null);
  const [ajaxAdditions, setAjaxAdditions] = useState<any>(null);
  useEffect(() => {
    if (!wrapRef.current) {
      return;
    }
    const fieldsetWrapper = wrapRef.current;
    // @ts-ignore
    const handleAddition = (e) => {
      // Convert HTML string to DOM element
      const parser = new DOMParser();
      const doc = parser.parseFromString(
        `<div data-wrap>${e.detail.data}</div>`,
        'text/html',
      );
      const element = doc.body.firstElementChild;
      if (element) {
        Drupal.HyperscriptifyUpdateStore([
          ...element.querySelectorAll('*[attributes]'),
        ]);
        setAjaxAdditions(Drupal.Hyperscriptify(element));
      }
    };
    fieldsetWrapper.addEventListener(
      'canvas:updateMediaWidget',
      handleAddition,
    );

    return () => {
      if (fieldsetWrapper) {
        fieldsetWrapper.removeEventListener(
          'canvas:updateMediaWidget',
          handleAddition,
        );
      }
    };
  }, [Drupal]);

  return (
    <div
      data-canvas-ml-ajax-target
      ref={wrapRef}
      style={{ display: 'contents' }}
    >
      {ajaxAdditions || children}
    </div>
  );
};

export default DrupalMediaLibraryFieldset;
