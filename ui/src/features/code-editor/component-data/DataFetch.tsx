import { Accordion } from 'radix-ui';
import { JSONTree } from 'react-json-tree';
import { ChevronDownIcon, InfoCircledIcon } from '@radix-ui/react-icons';
import { Box, Callout } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import { selectCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';
import { jsonTreeThemeProp } from '@/features/code-editor/component-data/jsonTreeTheme';

import styles from './DataFetch.module.css';

const DataFetch = () => {
  const fetchedData = useAppSelector(
    selectCodeComponentProperty('dataFetches'),
  );

  return (
    <>
      {Object.keys(fetchedData).length === 0 && (
        <Box flexGrow="1" pt="4" maxWidth="500px" mx="auto">
          <Callout.Root size="1" variant="surface" color="gray">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              Results from <code>useSWR()</code> and data fetching with
              <code>'@/lib/drupal-utils'</code> functions will be shown here.
            </Callout.Text>
          </Callout.Root>
        </Box>
      )}

      <Accordion.Root
        type="multiple"
        defaultValue={Object.keys(fetchedData).map(
          (id, index) => `item-${index}`,
        )}
      >
        {Object.keys(fetchedData).map((id, index) => (
          <Accordion.Item key={id} value={`item-${index}`}>
            <Accordion.Trigger className={styles.accordionTrigger}>
              <Box mt="4">
                <Callout.Root color="gray" size="1">
                  <Callout.Text size="1" weight="medium">
                    <ChevronDownIcon
                      className={styles.accordionChevron}
                      aria-hidden
                    />
                    &nbsp;
                    {id}
                  </Callout.Text>
                </Callout.Root>
              </Box>
            </Accordion.Trigger>
            <Accordion.Content>
              <Box mt="4" mb="8">
                {fetchedData[id].error ? (
                  <Callout.Root color="red">
                    <Callout.Icon>
                      <InfoCircledIcon />
                    </Callout.Icon>
                    <Callout.Text>
                      {fetchedData[id].data?.message
                        ? fetchedData[id].data.message
                        : 'error'}
                    </Callout.Text>
                    <Box p="2" className={styles.errorWrapper}>
                      <JSONTree
                        data={fetchedData[id].data}
                        theme={jsonTreeThemeProp}
                        invertTheme={false}
                        hideRoot={true}
                      />
                    </Box>
                  </Callout.Root>
                ) : (
                  <JSONTree
                    data={fetchedData[id].data}
                    theme={jsonTreeThemeProp}
                    invertTheme={false}
                    hideRoot={true}
                  />
                )}
              </Box>
            </Accordion.Content>
          </Accordion.Item>
        ))}
      </Accordion.Root>
    </>
  );
};

export default DataFetch;
