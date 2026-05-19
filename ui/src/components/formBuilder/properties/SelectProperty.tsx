import { Select, Text } from '@radix-ui/themes';

interface SelectPropertyProps {
  label: string;
  value: string;
  options: Record<string, string>;
  onChange: (value: string) => void;
}

export function SelectProperty({
  label,
  value,
  options,
  onChange,
}: SelectPropertyProps) {
  return (
    <div>
      <Text as="div" size="1" weight="medium" mb="1">
        {label}
      </Text>
      <Select.Root size="1" value={value ?? ''} onValueChange={onChange}>
        <Select.Trigger style={{ width: '100%' }} />
        <Select.Content>
          {Object.entries(options).map(([val, display]) => (
            <Select.Item key={val} value={val}>
              {display}
            </Select.Item>
          ))}
        </Select.Content>
      </Select.Root>
    </div>
  );
}
