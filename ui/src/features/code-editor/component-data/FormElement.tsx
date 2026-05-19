import { Flex, Text } from '@radix-ui/themes';

import { REQUIRED_EXAMPLE_ERROR_MESSAGE } from '@/features/code-editor/component-data/Props';

import styles from './FormElement.module.css';

export function FormElement({ children }: { children: React.ReactNode }) {
  return (
    <Flex direction="column" gap="1">
      {children}
    </Flex>
  );
}

export function Label({
  children,
  htmlFor,
}: {
  children: React.ReactNode;
  htmlFor?: string;
}) {
  return (
    <Text htmlFor={htmlFor} as="label" size="1" weight="medium">
      {children}
    </Text>
  );
}

export function Divider() {
  return <hr className={styles.divider} />;
}

export function RequiredFieldError({ show }: { show: boolean }) {
  if (!show) return null;
  return (
    <Text color="red" size="1">
      {REQUIRED_EXAMPLE_ERROR_MESSAGE}
    </Text>
  );
}
