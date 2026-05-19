import { Text, TextField } from '@radix-ui/themes';

interface NumberPropertyProps {
  label: string;
  value: number | string;
  onChange: (value: number | undefined) => void;
  min?: number;
  max?: number;
}

export function NumberProperty({
  label,
  value,
  onChange,
  min,
  max,
}: NumberPropertyProps) {
  return (
    <label>
      <Text as="div" size="1" weight="medium" mb="1">
        {label}
      </Text>
      <TextField.Root
        type="number"
        value={value ?? ''}
        min={min}
        max={max}
        size="1"
        onChange={(e) => {
          const v = e.target.value;
          onChange(v === '' ? undefined : Number(v));
        }}
      />
    </label>
  );
}
