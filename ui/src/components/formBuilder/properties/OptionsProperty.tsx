import { Cross2Icon, PlusIcon } from '@radix-ui/react-icons';
import { Button, Flex, IconButton, Text, TextField } from '@radix-ui/themes';

interface OptionsPropertyProps {
  label: string;
  value: Record<string, string>;
  onChange: (value: Record<string, string>) => void;
}

export function OptionsProperty({
  label,
  value,
  onChange,
}: OptionsPropertyProps) {
  const entries = Object.entries(value ?? {});

  const addOption = () => {
    const key = `option_${entries.length + 1}`;
    onChange({ ...value, [key]: `Option ${entries.length + 1}` });
  };

  const updateKey = (oldKey: string, newKey: string) => {
    const updated: Record<string, string> = {};
    for (const [k, v] of Object.entries(value)) {
      updated[k === oldKey ? newKey : k] = v;
    }
    onChange(updated);
  };

  const updateLabel = (key: string, newLabel: string) => {
    onChange({ ...value, [key]: newLabel });
  };

  const removeOption = (key: string) => {
    const updated = { ...value };
    delete updated[key];
    onChange(updated);
  };

  return (
    <div>
      <Text as="div" size="1" weight="medium" mb="1">
        {label}
      </Text>
      <Flex direction="column" gap="1">
        {entries.map(([key, label]) => (
          <Flex key={key} gap="1" align="center">
            <TextField.Root
              size="1"
              placeholder="key"
              value={key}
              onChange={(e) => updateKey(key, e.target.value)}
              style={{ flex: '0 0 40%' }}
            />
            <TextField.Root
              size="1"
              placeholder="Label"
              value={label}
              onChange={(e) => updateLabel(key, e.target.value)}
              style={{ flex: 1 }}
            />
            <IconButton
              size="1"
              variant="ghost"
              color="red"
              onClick={() => removeOption(key)}
            >
              <Cross2Icon />
            </IconButton>
          </Flex>
        ))}
        <Button size="1" variant="ghost" onClick={addOption}>
          <PlusIcon /> Add option
        </Button>
      </Flex>
    </div>
  );
}
