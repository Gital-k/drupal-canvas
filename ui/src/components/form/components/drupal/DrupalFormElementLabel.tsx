import clsx from 'clsx';

import PropLinker from '@/components/form/components/drupal/PropLinker';
import FormElementLabel from '@/components/form/components/FormElementLabel';
import { a2p } from '@/local_packages/utils.js';

import type { ReactNode } from 'react';
import type { PropLinkerProps } from '@/components/form/components/drupal/PropLinker';
import type { Attributes } from '@/types/DrupalAttribute';

// Account for linker data being snake_case when it arrives via attributes.
interface PropLinkData extends Omit<PropLinkerProps, 'propName'> {
  prop_name: string;
}

// Whitespace between a custom element's tags reaches hyperscriptify as a text
// node, so an element written as `<tag title="…"></tag>` still has children.
const hasContent = (node: ReactNode): boolean => {
  if (Array.isArray(node)) {
    return node.some(hasContent);
  }
  if (typeof node === 'string') {
    return node.trim() !== '';
  }
  return node !== null && node !== undefined && typeof node !== 'boolean';
};

const DrupalFormElementLabel = ({
  title = '',
  titleDisplay = '',
  required = '',
  attributes = {},
  directLinkerData = undefined,
  children = undefined,
}: {
  title: string;
  titleDisplay?: string;
  required?: string;
  attributes?: Attributes;
  directLinkerData?: PropLinkData;
  children?: ReactNode;
}) => {
  const classes = clsx(
    titleDisplay === 'after' ? 'option' : '',
    titleDisplay === 'invisible' ? 'visually-hidden' : '',
    required ? 'js-form-required' : '',
    required ? 'form-required' : '',
  );
  // Prefer the title passed as a child. An attribute can only carry a string,
  // so a label containing markup arrives there flattened and would render as
  // text. Templates that pass the title only as an attribute keep working:
  // the whitespace between an empty element's tags still arrives as a child,
  // so only children with actual content may override the attribute.
  const label = hasContent(children) ? children : title;
  const show = hasContent(label) || !!required;

  // The basic form label
  const theLabel = (
    <FormElementLabel
      attributes={a2p(
        attributes,
        {},
        { skipAttributes: ['class', 'prop_link_data'] },
      )}
      className={classes}
    >
      {label}
    </FormElementLabel>
  );
  const getTheLabel = () => {
    // If there is prop link data, render the PropLinker next to the label.
    // We wrap the label in a div so they can appear next to each other without
    // the linker appearing inside the <label> tag.
    if (
      titleDisplay !== 'invisible' &&
      (attributes?.prop_link_data || directLinkerData)
    ) {
      const propLinkData: PropLinkData = directLinkerData
        ? directLinkerData
        : (attributes.prop_link_data as PropLinkData);
      return (
        <div className="canvas-linked-prop-label-wrapper">
          {theLabel}
          <PropLinker
            propName={propLinkData.prop_name}
            linked={propLinkData.linked}
            suggestions={propLinkData.suggestions}
          />
        </div>
      );
    }

    // If there are no prop links, just return the label.
    return theLabel;
  };

  return show && getTheLabel();
};

export default DrupalFormElementLabel;
