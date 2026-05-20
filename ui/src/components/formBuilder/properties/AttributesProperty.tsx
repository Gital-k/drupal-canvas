import { Cross2Icon, PlusIcon } from '@radix-ui/react-icons';
import { Button, Flex, IconButton, Text, TextField } from '@radix-ui/themes';

interface AttributesPropertyProps {
  label: string;
  value: Record<string, string | string[]>;
  onChange: (value: Record<string, string | string[]>) => void;
}

export function AttributesProperty({
  label,
  value,
  onChange,
}: AttributesPropertyProps) {
  const attrs = value ?? {};
  const entries = Object.entries(attrs);

  const add = () => {
    onChange({ ...attrs, '': '' });
  };

  const updateAttrKey = (oldKey: string, newKey: string) => {
    const updated: Record<string, string | string[]> = {};
    for (const [k, v] of Object.entries(attrs)) {
      updated[k === oldKey ? newKey : k] = v;
    }
    onChange(updated);
  };

  const updateAttrValue = (key: string, newVal: string) => {
    onChange({ ...attrs, [key]: newVal });
  };

  const remove = (key: string) => {
    const updated = { ...attrs };
    delete updated[key];
    onChange(updated);
  };

  return (
    <div>
      <Text as="div" size="1" weight="medium" mb="1">
        {label}
      </Text>
      <Flex direction="column" gap="1">
        {entries.map(([key, val], i) => (
          <Flex key={i} gap="1" align="center">
            <TextField.Root
              size="1"
              placeholder="attribute"
              value={key}
              onChange={(e) => updateAttrKey(key, e.target.value)}
              style={{ flex: '0 0 40%' }}
            />
            <TextField.Root
              size="1"
              placeholder="value"
              value={Array.isArray(val) ? val.join(' ') : String(val ?? '')}
              onChange={(e) => updateAttrValue(key, e.target.value)}
              style={{ flex: 1 }}
            />
            <IconButton
              size="1"
              variant="ghost"
              color="red"
              onClick={() => remove(key)}
            >
              <Cross2Icon />
            </IconButton>
          </Flex>
        ))}
        <Button size="1" variant="ghost" onClick={add}>
          <PlusIcon /> Add attribute
        </Button>
      </Flex>
    </div>
  );
}
