import { useMemo } from 'react';

import { useAppSelector } from '@/app/hooks';
import InputDescription from '@/components/form/components/drupal/InputDescription';
import FormElementLabel from '@/components/form/components/FormElementLabel';
import { FORM_TYPES } from '@/features/form/constants';
import { selectFormValues } from '@/features/form/formStateSlice';
import { getDrupalSettings } from '@/utils/drupal-globals';

import styles from './SerpPreview.module.css';

const FIELD_KEYS = {
  TITLE: 'title[0][value]',
  META_TITLE: 'metatags[0][basic][title]',
  ALIAS: 'path[0][alias]',
  DESCRIPTION: 'description[0][value]',
} as const;

/**
 * SERP Preview component that displays a preview of search engine results.
 */
const SerpPreview = () => {
  const formValues = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.ENTITY_FORM),
  );

  const drupalSettings = getDrupalSettings();
  const siteName = (drupalSettings?.canvas as any)?.siteName || '';
  const baseUrl = (drupalSettings?.canvas as any)?.baseUrl || '';

  const title = formValues[FIELD_KEYS.TITLE] || '';
  const metaTitle = formValues[FIELD_KEYS.META_TITLE] || '';
  const alias = formValues[FIELD_KEYS.ALIAS] || '';
  const description = formValues[FIELD_KEYS.DESCRIPTION] || '';

  const previewTitle = useMemo(() => {
    if (metaTitle) {
      return metaTitle
        .replace(/\[current-page:title]/gi, title)
        .replace(/\[canvas_page:title]/gi, title)
        .replace(/\[site:name]/gi, siteName);
    }
    return title ? `${title} | ${siteName}` : siteName;
  }, [metaTitle, title, siteName]);

  const previewUrl = useMemo(() => {
    return alias
      ? `${baseUrl}${alias.startsWith('/') ? alias : `/${alias}`}`
      : baseUrl;
  }, [alias, baseUrl]);

  return (
    <div className={styles.wrapper}>
      <FormElementLabel className={styles.label}>
        Search Result Preview
      </FormElementLabel>
      <InputDescription
        description="This preview uses the typical character limits for Google search result pages on desktop. Search engines may decide to show different content."
        descriptionDisplay="before"
      >
        <div className={styles.preview}>
          <div className={styles.title}>{previewTitle}</div>
          <div className={styles.url}>{previewUrl}</div>
          <div className={styles.description}>{description}</div>
        </div>
      </InputDescription>
    </div>
  );
};

export default SerpPreview;
