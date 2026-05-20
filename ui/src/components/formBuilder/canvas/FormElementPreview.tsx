import { Box, Text } from '@radix-ui/themes';

import type {
  FormElement,
  FormElementSchema,
} from '@/features/formBuilder/types';

interface FormElementPreviewProps {
  element: FormElement;
  schemas: Record<string, FormElementSchema>;
}

const inputBase: React.CSSProperties = {
  width: '100%',
  padding: '6px 8px',
  border: '1px solid var(--gray-6)',
  borderRadius: 4,
  fontSize: 13,
  color: 'var(--gray-11)',
  background: 'var(--color-background)',
  pointerEvents: 'none',
  boxSizing: 'border-box',
};

function FieldWrapper({
  children,
  required,
  description,
}: {
  children: React.ReactNode;
  required?: boolean;
  description?: string;
}) {
  return (
    <Box style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      {children}
      {description && (
        <Text size="1" color="gray">
          {description}
        </Text>
      )}
    </Box>
  );
}

function FieldLabel({
  label,
  required,
}: {
  label: string;
  required?: boolean;
}) {
  return (
    <Text as="div" size="2" weight="medium">
      {label}
      {required && (
        <Text color="red" style={{ marginLeft: 2 }}>
          *
        </Text>
      )}
    </Text>
  );
}

export function FormElementPreview({
  element,
  schemas,
}: FormElementPreviewProps) {
  const p = element.properties;
  const title =
    (p.title as string) || schemas[element.type]?.label || element.type;
  const placeholder = (p.placeholder as string) || '';
  const defaultValue = (p.default_value as string) || '';
  const required = p.required as boolean | undefined;
  const description = p.description as string | undefined;
  const options = (p.options as Record<string, string>) || {};

  switch (element.type) {
    case 'textfield':
    case 'email':
    case 'tel':
    case 'url':
      return (
        <FieldWrapper required={required} description={description}>
          <FieldLabel label={title} required={required} />
          <input
            style={inputBase}
            type="text"
            placeholder={placeholder}
            defaultValue={defaultValue}
            tabIndex={-1}
          />
        </FieldWrapper>
      );

    case 'number':
      return (
        <FieldWrapper required={required} description={description}>
          <FieldLabel label={title} required={required} />
          <input
            style={inputBase}
            type="number"
            placeholder={placeholder}
            defaultValue={defaultValue}
            tabIndex={-1}
          />
        </FieldWrapper>
      );

    case 'date':
      return (
        <FieldWrapper required={required} description={description}>
          <FieldLabel label={title} required={required} />
          <input
            style={inputBase}
            type="date"
            defaultValue={defaultValue}
            tabIndex={-1}
          />
        </FieldWrapper>
      );

    case 'textarea':
      return (
        <FieldWrapper required={required} description={description}>
          <FieldLabel label={title} required={required} />
          <textarea
            style={{ ...inputBase, resize: 'none', height: 72 }}
            placeholder={placeholder}
            defaultValue={defaultValue}
            rows={3}
            tabIndex={-1}
          />
        </FieldWrapper>
      );

    case 'select':
      return (
        <FieldWrapper required={required} description={description}>
          <FieldLabel label={title} required={required} />
          <select style={{ ...inputBase }} tabIndex={-1}>
            {!!p.empty_option && (
              <option value="">{String(p.empty_option)}</option>
            )}
            {Object.entries(options).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </FieldWrapper>
      );

    case 'radios':
      return (
        <FieldWrapper required={required} description={description}>
          <FieldLabel label={title} required={required} />
          <Box style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            {Object.entries(options).length > 0 ? (
              Object.entries(options).map(([value, label]) => (
                <label
                  key={value}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                    fontSize: 13,
                    pointerEvents: 'none',
                  }}
                >
                  <input type="radio" readOnly tabIndex={-1} />
                  {label}
                </label>
              ))
            ) : (
              <Text size="1" color="gray">
                No options defined
              </Text>
            )}
          </Box>
        </FieldWrapper>
      );

    case 'checkboxes':
      return (
        <FieldWrapper required={required} description={description}>
          <FieldLabel label={title} required={required} />
          <Box style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            {Object.entries(options).length > 0 ? (
              Object.entries(options).map(([value, label]) => (
                <label
                  key={value}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                    fontSize: 13,
                    pointerEvents: 'none',
                  }}
                >
                  <input type="checkbox" readOnly tabIndex={-1} />
                  {label}
                </label>
              ))
            ) : (
              <Text size="1" color="gray">
                No options defined
              </Text>
            )}
          </Box>
        </FieldWrapper>
      );

    case 'checkbox':
      return (
        <FieldWrapper description={description}>
          <label
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 6,
              fontSize: 13,
              pointerEvents: 'none',
            }}
          >
            <input type="checkbox" readOnly tabIndex={-1} />
            {title}
            {required && <Text color="red">*</Text>}
          </label>
        </FieldWrapper>
      );

    case 'hidden':
      return (
        <Box
          style={{
            padding: '4px 8px',
            border: '1px dashed var(--gray-5)',
            borderRadius: 4,
            background: 'var(--gray-2)',
          }}
        >
          <Text size="1" color="gray">
            Hidden field: <code>{element.machineName}</code>
          </Text>
        </Box>
      );

    case 'submit':
    case 'button': {
      const btnValue =
        (p.value as string) ||
        (element.type === 'submit' ? 'Submit' : 'Button');
      const isPrimary =
        (p.button_type as string) === 'primary' ||
        (!p.button_type && element.type === 'submit');
      return (
        <button
          style={{
            padding: '7px 16px',
            border: isPrimary ? 'none' : '1px solid var(--gray-6)',
            borderRadius: 4,
            fontSize: 13,
            fontWeight: 500,
            cursor: 'default',
            background: isPrimary
              ? 'var(--accent-9)'
              : 'var(--color-background)',
            color: isPrimary ? 'white' : 'var(--gray-12)',
            pointerEvents: 'none',
          }}
          tabIndex={-1}
        >
          {btnValue}
        </button>
      );
    }

    case 'container':
      return (
        <Box
          style={{
            border: '1px dashed var(--gray-6)',
            borderRadius: 4,
            padding: '8px 12px',
            background: 'var(--gray-1)',
            minHeight: 40,
          }}
        >
          <Text size="1" color="gray">
            Container{element.machineName ? `: ${element.machineName}` : ''}
          </Text>
        </Box>
      );

    case 'fieldset':
      return (
        <fieldset
          style={{
            border: '1px solid var(--gray-6)',
            borderRadius: 4,
            padding: '8px 12px',
            margin: 0,
            pointerEvents: 'none',
          }}
        >
          <legend style={{ fontSize: 13, fontWeight: 500, padding: '0 4px' }}>
            {title}
          </legend>
          {description && (
            <Text size="1" color="gray">
              {description}
            </Text>
          )}
        </fieldset>
      );

    case 'details':
      return (
        <details
          style={{
            border: '1px solid var(--gray-6)',
            borderRadius: 4,
            padding: '8px 12px',
            pointerEvents: 'none',
          }}
        >
          <summary style={{ fontSize: 13, fontWeight: 500, cursor: 'default' }}>
            {title}
          </summary>
          {description && (
            <Text size="1" color="gray">
              {description}
            </Text>
          )}
        </details>
      );

    default:
      return (
        <Box
          style={{
            padding: '6px 8px',
            border: '1px solid var(--gray-5)',
            borderRadius: 4,
          }}
        >
          <Text size="1" color="gray">
            {element.type}: {title}
          </Text>
        </Box>
      );
  }
}
