import { useEffect, useState } from 'react';
import { CheckIcon, CopyIcon } from '@radix-ui/react-icons';
import { Flex, IconButton, Tooltip } from '@radix-ui/themes';

import { translate } from '@/utils/translate';

import styles from './HeadlessFrontends.module.css';

interface CommandSnippetProps {
  command: string;
  'data-testid'?: string;
}

const CommandSnippet = ({
  command,
  'data-testid': dataTestId,
}: CommandSnippetProps) => {
  const [isCopied, setIsCopied] = useState(false);

  useEffect(() => {
    if (!isCopied) {
      return;
    }
    const timer = setTimeout(() => setIsCopied(false), 1500);
    return () => clearTimeout(timer);
  }, [isCopied]);

  const handleCopy = async () => {
    await navigator.clipboard.writeText(command);
    setIsCopied(true);
  };

  return (
    <Flex gap="2" align="center">
      <div className={styles.snippetBox}>
        <pre className={styles.snippetPre} data-testid={dataTestId}>
          {command}
        </pre>
      </div>
      <Tooltip
        content={isCopied ? translate('Copied') : translate('Copy command')}
      >
        <IconButton
          size="1"
          variant="ghost"
          color={isCopied ? 'green' : 'gray'}
          aria-label={
            isCopied ? translate('Copied') : translate('Copy command')
          }
          onClick={() => void handleCopy()}
        >
          {isCopied ? <CheckIcon /> : <CopyIcon />}
        </IconButton>
      </Tooltip>
    </Flex>
  );
};

export default CommandSnippet;
