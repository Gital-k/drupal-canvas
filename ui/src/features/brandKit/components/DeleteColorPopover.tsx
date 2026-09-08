import { Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Box, Button, Flex, IconButton, Spinner, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import ErrorCard from '@/components/error/ErrorCard';
import {
  useDeleteColorMutation,
  useGetColorUsageDetailsQuery,
} from '@/services/brandKit';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { translate, translatePlural } from '@/utils/translate';

import type { Measurable } from '@radix-ui/rect';
import type { BrandKitColor } from '@/types/CodeComponent';

import styles from './DeleteColorPopover.module.css';

interface DeleteColorPopoverProps {
  color: BrandKitColor;
  anchorRef: React.RefObject<Measurable>;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const DeleteColorPopover = ({
  color,
  anchorRef,
  open,
  onOpenChange,
}: DeleteColorPopoverProps) => {
  const dispatch = useAppDispatch();
  const [deleteColor, { isLoading: isDeleting, isError, error, reset }] =
    useDeleteColorMutation();

  const {
    data: usageDetails,
    isLoading: isUsageLoading,
    isError: isUsageError,
  } = useGetColorUsageDetailsQuery(color.id, { skip: !open });

  const currentUsages = usageDetails?.current ?? [];
  const configUsages = usageDetails?.config ?? [];
  const priorUsages = usageDetails?.prior ?? [];

  // Whether the color can be deleted is decided by the server: it also weighs
  // auto-saves and default revisions, which the usage lists do not report.
  const isBlocked =
    !isUsageLoading &&
    usageDetails !== undefined &&
    usageDetails.deletable === false;
  const isDeleteDisabled = isUsageLoading || isUsageError || isBlocked;

  const hasPriorOnlyWarning =
    !isUsageLoading &&
    usageDetails !== undefined &&
    !isBlocked &&
    priorUsages.length > 0;

  const handleDelete = async () => {
    try {
      await deleteColor(color.id).unwrap();
      dispatch(
        componentAndLayoutApi.util.invalidateTags([
          { type: 'Folders', id: 'LIST' },
        ]),
      );
      onOpenChange(false);
      reset();
    } catch (err) {
      console.error('Failed to delete color:', err);
    }
  };

  const errorMessage =
    isError && error
      ? error && 'data' in error
        ? (error.data as { message?: string })?.message
        : String(error)
      : null;

  const blockingCount = currentUsages.length + configUsages.length;

  return (
    <Popover.Root open={open} onOpenChange={onOpenChange}>
      <Popover.Anchor virtualRef={anchorRef} />
      <Popover.Portal
        container={
          document.querySelector<HTMLElement>('.radix-themes') ?? document.body
        }
      >
        <Popover.Content
          side="bottom"
          align="start"
          sideOffset={4}
          className={styles.popoverContent}
          data-testid="canvas-delete-color-popover"
          onInteractOutside={(e) => {
            const target = e.target as Element | null;
            if (target?.hasAttribute('data-radix-menu-content')) {
              e.preventDefault();
            }
          }}
        >
          {/* Header with title and close button */}
          <Flex
            justify="between"
            align="center"
            className={styles.header}
            px="3"
            py="3"
          >
            <Flex gap="2" align="center">
              <TrashIcon className={styles.titleIcon} />
              <Text size="2" weight="bold">
                {translate('Delete color')}
              </Text>
            </Flex>
            <Popover.Close asChild>
              <IconButton
                variant="ghost"
                size="1"
                aria-label={translate('Close')}
              >
                <Cross2Icon />
              </IconButton>
            </Popover.Close>
          </Flex>

          <Box px="3" py="4" data-testid="canvas-delete-color-popover-body">
            {isUsageLoading && (
              <Flex justify="center">
                <Spinner />
              </Flex>
            )}

            {!isUsageLoading && isUsageError && (
              <Text size="2" role="alert">
                {translate(
                  'Unable to get usage data for <b>@name</b>. Try again.',
                  { '@name': color.name },
                )}
              </Text>
            )}

            {!isUsageLoading && isBlocked && (
              <Text size="2">
                {blockingCount > 0 ? (
                  <>
                    {translatePlural(
                      blockingCount,
                      '<b>@name</b> is in use on 1 page or template. Remove all uses before deleting.',
                      '<b>@name</b> is in use on @count pages or templates. Remove all uses before deleting.',
                      { '@name': color.name, '@count': blockingCount },
                    )}
                  </>
                ) : (
                  <>
                    {/* Blocked by an unpublished change or a published revision
                        that is no longer the latest, so there is nothing to
                        list here. */}
                    {translate(
                      '<b>@name</b> is still in use. Remove all uses before deleting.',
                      { '@name': color.name },
                    )}
                  </>
                )}
              </Text>
            )}

            {!isUsageLoading && !isBlocked && !isUsageError && (
              <Text size="2">
                {translate(
                  'You are about to permanently delete the <b>@name</b> color.',
                  { '@name': color.name },
                )}
                {hasPriorOnlyWarning && (
                  <>
                    <br />
                    <br />
                    {translatePlural(
                      priorUsages.length,
                      'This will break <b>@count past version</b>. Reverting to past versions that rely on this color will appear broken.',
                      'This will break <b>@count past versions</b>. Reverting to past versions that rely on this color will appear broken.',
                      { '@count': priorUsages.length },
                    )}
                  </>
                )}
              </Text>
            )}
          </Box>

          {errorMessage && (
            <Box px="3" pb="3">
              <ErrorCard
                title={translate('Failed to delete color')}
                error={errorMessage}
              />
            </Box>
          )}

          {/* Footer with action buttons */}
          <Flex
            gap="2"
            justify="end"
            px="3"
            pb="3"
            pt="2"
            className={styles.footer}
          >
            <Popover.Close asChild>
              <Button variant="outline" size="1">
                {translate('Cancel')}
              </Button>
            </Popover.Close>
            <Button
              onClick={handleDelete}
              loading={isDeleting}
              disabled={isDeleteDisabled}
              size="1"
              color="red"
              data-testid="canvas-delete-color-confirm-button"
            >
              {translate('Delete')}
            </Button>
          </Flex>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
};

export default DeleteColorPopover;
