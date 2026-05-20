import { Text, TextArea } from '@radix-ui/themes';

interface TextPropertyProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
}

export function TextProperty({ label, value, onChange }: TextPropertyProps) {
  return (
    <label>
      <Text as="div" size="1" weight="medium" mb="1">
        {label}
      </Text>
      <TextArea
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value)}
        size="1"
        rows={3}
      />
    </label>
  );
}
