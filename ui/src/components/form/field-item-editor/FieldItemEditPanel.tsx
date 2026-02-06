import { Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import { Box, Button, Card, Flex, IconButton, Text } from '@radix-ui/themes';

import styles from './FieldItemEditor.module.css';

interface FieldItemEditPanelProps {
  isOpen: boolean;
  selectedIndex: number | null;
  onClose: () => void;
  onRemove: () => void;
  children?: React.ReactNode;
  isRequired: boolean;
  cardinality: number;
  fieldLabel?: string;
}

export default function FieldItemEditPanel({
  isOpen,
  selectedIndex,
  onClose,
  onRemove,
  children,
  isRequired,
  cardinality,
  fieldLabel,
}: FieldItemEditPanelProps) {
  if (!isOpen || selectedIndex === null) {
    return null;
  }

  const showRemoveButton = cardinality !== 1 && !isRequired;

  return (
    <Card className={styles.editPanel}>
      <Flex direction="column" gap="3">
        <Flex
          justify="between"
          align="center"
          className={styles.editPanelHeader}
        >
          <Text size="2" weight="medium">
            {fieldLabel || 'Edit'}
          </Text>
          <IconButton
            size="1"
            variant="ghost"
            onClick={onClose}
            aria-label="Close"
          >
            <Cross2Icon />
          </IconButton>
        </Flex>

        <Box className={styles.editPanelContent}>{children}</Box>

        {showRemoveButton && (
          <Flex justify="end" className={styles.editPanelFooter}>
            <Button size="1" variant="soft" color="red" onClick={onRemove}>
              <TrashIcon /> Remove
            </Button>
          </Flex>
        )}
      </Flex>
    </Card>
  );
}
