import { Text, TextField } from '@radix-ui/themes';

interface StringPropertyProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
  placeholder?: string;
}

export function StringProperty({
  label,
  value,
  onChange,
  required,
  placeholder,
}: StringPropertyProps) {
  return (
    <label>
      <Text as="div" size="1" weight="medium" mb="1">
        {label}
        {required && <Text color="red"> *</Text>}
      </Text>
      <TextField.Root
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        size="1"
      />
    </label>
  );
}
