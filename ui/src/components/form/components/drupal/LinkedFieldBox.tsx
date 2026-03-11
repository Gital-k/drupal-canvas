import clsx from 'clsx';
import { Cross2Icon, TextIcon } from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';

import InputDescription from '@/components/form/components/drupal/InputDescription';
import { _unlinkPropFromEntityValue } from '@/features/layout/layoutModelSlice';
import useInputUIData from '@/hooks/useInputUIData';

import styles from './LinkedFieldBox.module.css';

const ARROW_SEPARATOR = ' → ';
const SLASH_SEPARATOR = ' / ';

const LinkedFieldBox = ({
  title,
  propName,
  description,
  descriptionDisplay,
}: {
  title: string;
  propName: string;
  description: string;
  descriptionDisplay?: 'before' | 'after' | 'invisible';
}) => {
  // Convert arrows to slashes for the full label display
  const fullLabel = title.replaceAll(ARROW_SEPARATOR, SLASH_SEPARATOR);
  // Extract just the last segment for the short title
  const parts = fullLabel.split(SLASH_SEPARATOR);
  const shortTitle = parts[parts.length - 1];
  const inputUIData = useInputUIData();
  const { selectedComponent } = inputUIData;

  const unlinkField = () => {
    _unlinkPropFromEntityValue({
      propName,
      componentToUpdateId: selectedComponent,
      inputUIData,
    });
  };

  return (
    <Box mb="4" data-testid={`linked-field-box-${propName}`}>
      <InputDescription
        description={description}
        descriptionDisplay={descriptionDisplay}
      >
        <Flex className={styles.wrapper} mb="2" title={fullLabel}>
          <Text className={clsx(styles.linkIcon, styles.iconBox)}>
            <TextIcon />
          </Text>
          <Text
            data-testid={`linked-field-label-${propName}`}
            className={styles.text}
          >
            {shortTitle}
          </Text>
          <button
            className={clsx(styles.iconBox, styles.closeIcon)}
            onClick={unlinkField}
          >
            <Cross2Icon />
          </button>
        </Flex>
      </InputDescription>
    </Box>
  );
};

export default LinkedFieldBox;
