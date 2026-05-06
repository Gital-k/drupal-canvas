import { useState } from 'react';
import clsx from 'clsx';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';

import type { ReactNode } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './AccordionAndDetails.module.css';

const Details = ({
  title = '',
  children = null,
  attributes = {},
  summaryAttributes = {},
  open: controlledOpen,
  onOpenChange,
}: {
  title: string;
  children: ReactNode;
  attributes: Attributes;
  summaryAttributes: object;
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
}) => {
  const { open: openAttr, ...restAttributes } = attributes;
  const [uncontrolledOpen, setUncontrolledOpen] = useState(Boolean(openAttr));
  const isControlled = controlledOpen !== undefined;
  const open = isControlled ? controlledOpen : uncontrolledOpen;
  const handleOpenChange = (next: boolean) => {
    if (!isControlled) {
      setUncontrolledOpen(next);
    }
    onOpenChange?.(next);
  };
  return (
    <Collapsible.Root
      open={open}
      onOpenChange={handleOpenChange}
      {...restAttributes}
    >
      <Flex asChild justify="between" align="center" width="100%">
        <Collapsible.Trigger asChild className={styles.trigger}>
          <button>
            <Text size="2" weight="medium" {...summaryAttributes}>
              {title}
            </Text>
            <ChevronRightIcon className={styles.chevron} aria-hidden />
          </button>
        </Collapsible.Trigger>
      </Flex>
      <Collapsible.Content
        forceMount={true}
        className={clsx(styles.content, styles.detailsContent)}
      >
        <Box p="1">{children}</Box>
      </Collapsible.Content>
    </Collapsible.Root>
  );
};

export default Details;
