import { Flex, Switch, Text } from '@radix-ui/themes';

interface BooleanPropertyProps {
  label: string;
  value: boolean;
  onChange: (value: boolean) => void;
}

export function BooleanProperty({
  label,
  value,
  onChange,
}: BooleanPropertyProps) {
  return (
    <Flex align="center" gap="2" asChild>
      <label>
        <Switch size="1" checked={!!value} onCheckedChange={onChange} />
        <Text size="1" weight="medium">
          {label}
        </Text>
      </label>
    </Flex>
  );
}
