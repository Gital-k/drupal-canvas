import { useCallback, useEffect, useMemo, useState } from 'react';
import { CheckIcon, ChevronLeftIcon, Cross2Icon } from '@radix-ui/react-icons';
import { Badge, Box, Button, Flex, Spinner, Text } from '@radix-ui/themes';

import { Divider } from '@/features/code-editor/component-data/FormElement';

import styles from './ReviewConfigurationPanel.module.css';

export interface ReviewConfigurationPanelProps {
  onBack: () => void;
  onSend: (reviewerIds: string[]) => void;
  isSending: boolean;
  hasSent: boolean;
}

const ReviewConfigurationPanel: React.FC<ReviewConfigurationPanelProps> = ({
  onBack,
  onSend,
  isSending,
  hasSent,
}) => {
  const [reviewerInput, setReviewerInput] = useState<string>('');
  const [reviewerIds, setReviewerIds] = useState<string[]>([]);

  // Auto-return to change list after success
  useEffect(() => {
    if (hasSent) {
      const timer = setTimeout(() => {
        onBack();
      }, 1500);
      return () => clearTimeout(timer);
    }
  }, [hasSent, onBack]);

  const handleAddReviewer = useCallback(() => {
    const trimmed = reviewerInput.trim();
    if (trimmed && !reviewerIds.includes(trimmed)) {
      setReviewerIds((prev) => [...prev, trimmed]);
      setReviewerInput('');
    }
  }, [reviewerInput, reviewerIds]);

  const handleRemoveReviewer = useCallback((id: string) => {
    setReviewerIds((prev) => prev.filter((r) => r !== id));
  }, []);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleAddReviewer();
      }
      if (e.key === ',' || e.key === ' ') {
        e.preventDefault();
        handleAddReviewer();
      }
    },
    [handleAddReviewer],
  );

  const isSendDisabled = useMemo(
    () => reviewerIds.length === 0 || isSending || hasSent,
    [reviewerIds.length, isSending, hasSent],
  );

  return (
    <Flex direction="column" width="100%">
      <Box px="4" py="3">
        <Button variant="ghost" size="1" onClick={onBack}>
          <ChevronLeftIcon />
          Back
        </Button>
      </Box>
      <Divider />
      <Box px="4" py="4">
        <Box mb="2">
          <Text as="label" size="2" weight="medium">
            Reviewers
          </Text>
        </Box>
        <Box mb="3">
          <Text size="1" color="gray">
            Enter user IDs separated by commas
          </Text>
        </Box>
        <Flex direction="column" gap="2">
          <input
            type="text"
            value={reviewerInput}
            onChange={(e) => setReviewerInput(e.target.value)}
            onKeyDown={handleKeyDown}
            onBlur={handleAddReviewer}
            placeholder="Enter reviewer ID and press Enter"
            className={styles.reviewerInput}
            disabled={isSending || hasSent}
            data-testid="reviewer-input"
          />
          {reviewerIds.length > 0 && (
            <Flex wrap="wrap" gap="2" mt="2">
              {reviewerIds.map((id) => (
                <Badge key={id} variant="soft" color="blue" size="2">
                  <Flex align="center" gap="1">
                    <span>{id}</span>
                    {!isSending && !hasSent && (
                      <button
                        type="button"
                        onClick={() => handleRemoveReviewer(id)}
                        className={styles.removeButton}
                        aria-label={`Remove ${id}`}
                        data-testid={`remove-reviewer-${id}`}
                      >
                        <Cross2Icon />
                      </button>
                    )}
                  </Flex>
                </Badge>
              ))}
            </Flex>
          )}
        </Flex>
      </Box>
      <Divider />
      <Box px="4" py="4">
        <Button
          size="2"
          variant="solid"
          disabled={isSendDisabled}
          onClick={() => onSend(reviewerIds)}
          data-testid="send-for-review-button"
          className={styles.sendButton}
        >
          {isSending ? (
            <>
              Sending
              <Spinner loading />
            </>
          ) : hasSent ? (
            <>
              Sent
              <CheckIcon />
            </>
          ) : (
            'Send for review'
          )}
        </Button>
      </Box>
    </Flex>
  );
};

export default ReviewConfigurationPanel;
