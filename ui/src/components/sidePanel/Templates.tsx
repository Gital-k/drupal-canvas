import { useCallback, useEffect, useMemo, useState } from 'react';
import parse from 'html-react-parser';
import { PlusIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Select, Text } from '@radix-ui/themes';

import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import {
  AccordionDetails,
  AccordionRoot,
} from '@/components/form/components/Accordion';
import TemplateList from '@/components/list/TemplateList';
import PermissionCheck from '@/components/PermissionCheck';
import PageVariants from '@/components/sidePanel/PageVariants';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import {
  useCreateContentTemplateMutation,
  useGetContentTemplatesQuery,
  useGetViewModesQuery,
} from '@/services/componentAndLayout';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { ModeData } from '@/services/componentAndLayout';

const canvasSettings = getCanvasSettings();

const Templates = () => {
  const supportedEntityTypes =
    canvasSettings?.contentTemplateEntityTypes ??
    ({} as NonNullable<typeof canvasSettings>['contentTemplateEntityTypes']);
  const entityTypeIds = Object.keys(supportedEntityTypes);
  const [openEntityTypes, setOpenEntityTypes] = useState<string[]>([
    ...entityTypeIds,
    'page-templates',
  ]);
  const { data } = useGetContentTemplatesQuery();

  const visibleEntityTypeIds = entityTypeIds.filter((entityTypeId) => {
    if (!data) return true;
    const bundles = data[entityTypeId]?.bundles ?? {};
    return Object.values(bundles).some(
      (bundle) => Object.keys(bundle.viewModes).length > 0,
    );
  });

  const onClickHandler = (categoryName: string) => {
    setOpenEntityTypes((state) => {
      if (!state.includes(categoryName)) {
        return [...state, categoryName];
      }
      return state.filter((stateName) => stateName !== categoryName);
    });
  };

  return (
    <>
      <PermissionCheck hasPermission="contentTemplates">
        <Flex direction="column" mb="4">
          <AddTemplateButton />
        </Flex>
      </PermissionCheck>
      <AccordionRoot value={openEntityTypes}>
        <PermissionCheck hasPermission="contentTemplates">
          {visibleEntityTypeIds.map((entityTypeId) => (
            <AccordionDetails
              key={entityTypeId}
              value={entityTypeId}
              title={supportedEntityTypes[entityTypeId].bundleCollectionLabel}
              onTriggerClick={() => onClickHandler(entityTypeId)}
            >
              <ErrorBoundary title="An unexpected error has occurred while fetching templates.">
                <TemplateList entityTypeId={entityTypeId} />
              </ErrorBoundary>
            </AccordionDetails>
          ))}
        </PermissionCheck>
        <PermissionCheck hasPermission="pageVariants">
          <AccordionDetails
            value="page-templates"
            title="Page templates"
            onTriggerClick={() => onClickHandler('page-templates')}
          >
            <ErrorBoundary title="An unexpected error has occurred while fetching page templates.">
              <PageVariants />
            </ErrorBoundary>
          </AccordionDetails>
        </PermissionCheck>
      </AccordionRoot>
    </>
  );
};

const AddTemplateButton = () => {
  const [isOpen, setIsOpen] = useState(false);

  return (
    <>
      <Button
        data-testid="big-add-template-button"
        variant="soft"
        size="1"
        onClick={() => setIsOpen(true)}
      >
        <PlusIcon />
        New content template
      </Button>
      {isOpen && <AddTemplateDialog isOpen={isOpen} setIsOpen={setIsOpen} />}
    </>
  );
};

interface TemplateDialogProps {
  isOpen: boolean;
  setIsOpen: (isOpen: boolean) => void;
}

const AddTemplateDialog = ({ isOpen, setIsOpen }: TemplateDialogProps) => {
  const { navigateToTemplateEditor } = useEditorNavigation();
  const supportedEntityTypes =
    canvasSettings?.contentTemplateEntityTypes ??
    ({} as NonNullable<typeof canvasSettings>['contentTemplateEntityTypes']);
  const entityTypeIds = Object.keys(supportedEntityTypes);
  const defaultEntityType =
    entityTypeIds.length === 1 ? entityTypeIds[0] : null;
  const [selectedContentType, setSelectedContentType] = useState<string | null>(
    null,
  );
  const [selectedEntityType, setSelectedEntityType] = useState<string | null>(
    defaultEntityType,
  );
  const selectedEntityTypeMeta = selectedEntityType
    ? supportedEntityTypes[selectedEntityType]
    : null;
  // Entity types without a separate bundle entity type have a single implicit
  // bundle equal to the entity type ID — no bundle selection needed.
  const hasBundleSelection = selectedEntityTypeMeta?.bundleLabel != null;
  const [selectedViewMode, setSelectedViewMode] = useState<string | null>();

  const [createTemplate, { reset, isSuccess, isError, error }] =
    useCreateContentTemplateMutation();
  const {
    data,
    error: getViewModeError,
    isError: isGetViewModeError,
  } = useGetViewModesQuery();

  const redirectToSelectedAfterCreation = useCallback(() => {
    if (!selectedContentType || !selectedEntityType || !selectedViewMode) {
      return;
    }
    navigateToTemplateEditor({
      entityType: selectedEntityType,
      bundle: selectedContentType,
      viewMode: selectedViewMode,
    });
  }, [
    navigateToTemplateEditor,
    selectedContentType,
    selectedEntityType,
    selectedViewMode,
  ]);

  useEffect(() => {
    if (isSuccess) {
      setIsOpen(false);
      redirectToSelectedAfterCreation();
      setSelectedContentType('');
      setSelectedViewMode(null);
      setSelectedEntityType(defaultEntityType);
      reset();
    }
  }, [
    isSuccess,
    reset,
    setIsOpen,
    redirectToSelectedAfterCreation,
    defaultEntityType,
  ]);

  useEffect(() => {
    setSelectedViewMode('');
  }, [selectedContentType]);

  useEffect(() => {
    // For entity types with no separate bundle, auto-select the implicit bundle.
    setSelectedContentType(hasBundleSelection ? null : selectedEntityType);
    setSelectedViewMode(null);
  }, [selectedEntityType, hasBundleSelection]);

  const handleCreateTemplate = () => {
    createTemplate({
      entityType: selectedEntityType,
      bundle: selectedContentType,
      viewMode: selectedViewMode,
    });
  };

  const availableTemplates = useMemo(
    () =>
      selectedEntityType && selectedContentType
        ? Object.entries(
            data?.[selectedEntityType]?.[selectedContentType] || {},
          ).filter(([mode, modeData]) => {
            const typedModeData = modeData as unknown as ModeData;
            return !typedModeData.hasTemplate;
          }).length
        : null,
    [data, selectedContentType, selectedEntityType],
  );

  const entityBundleLabels =
    typeof selectedEntityType === 'string' &&
    canvasSettings?.entityTypeLabels?.[selectedEntityType];
  const bundleLabelType = typeof entityBundleLabels;
  const showEntityTypeDropdown = entityTypeIds.length > 1;
  return (
    <Dialog
      open={isOpen}
      title="Add new template"
      headerClose={false}
      error={
        isError || isGetViewModeError
          ? {
              title: isError
                ? 'Failed to add template'
                : 'Failed to load view modes',
              message: parse(
                extractErrorMessageFromApiResponse(error || getViewModeError),
              ),
              resetButtonText: 'Try again',
              onReset: isError ? handleCreateTemplate : undefined,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Add new template',
        isConfirmDisabled: !(selectedContentType && selectedViewMode),
        onConfirm: handleCreateTemplate,
      }}
      onOpenChange={(open) => setIsOpen(open)}
    >
      <Flex
        direction="column"
        data-testid="canvas-manage-library-add-template-content"
        p="0"
        gap="2"
        mb="2"
      >
        <Box>
          <Text as="p" size="1" color="gray">
            Creates a new template using existing view modes as the template
            name.
          </Text>
        </Box>
        {showEntityTypeDropdown && (
          <Flex direction="column" gap="2">
            <Box>
              <DialogFieldLabel htmlFor="entity-type">
                Entity type
              </DialogFieldLabel>
            </Box>
            <Select.Root
              name="entity-type"
              required
              value={selectedEntityType || undefined}
              onValueChange={(value) => setSelectedEntityType(value)}
              size="1"
            >
              <Select.Trigger
                id="entity-type"
                placeholder="Select entity type…"
                style={{ width: '100%' }}
              />
              <Select.Content>
                {Object.entries(supportedEntityTypes).map(([id, meta]) => {
                  const { label } = meta as {
                    label: string;
                    bundleLabel: string | null;
                  };
                  return (
                    <Select.Item key={id} value={id}>
                      {label}
                    </Select.Item>
                  );
                })}
              </Select.Content>
            </Select.Root>
          </Flex>
        )}
        {hasBundleSelection && (
          <Flex direction="column" gap="2">
            <Box>
              <DialogFieldLabel htmlFor="content-type">
                {selectedEntityTypeMeta?.bundleLabel ?? 'Bundle'}
              </DialogFieldLabel>
            </Box>
            <Select.Root
              key={selectedEntityType}
              name="content-type"
              required
              disabled={!selectedEntityType}
              value={selectedContentType || ''}
              onValueChange={(value) => setSelectedContentType(value as string)}
              size="1"
            >
              <Select.Trigger
                id="content-type"
                placeholder={`Select ${selectedEntityTypeMeta?.bundleLabel?.toLowerCase() ?? 'bundle'}…`}
                style={{ width: '100%' }}
              />
              <Select.Content>
                {bundleLabelType === 'object' &&
                  Object.entries(entityBundleLabels).map(([type, label]) => (
                    <Select.Item key={type} value={type}>
                      {String(label)}
                    </Select.Item>
                  ))}
              </Select.Content>
            </Select.Root>
          </Flex>
        )}
        {selectedContentType && (
          <Flex direction="column" gap="2">
            <Box>
              <DialogFieldLabel htmlFor="template-name">
                Template
              </DialogFieldLabel>
            </Box>
            <Select.Root
              name="template name"
              required
              disabled={!selectedContentType}
              value={selectedViewMode || ''}
              onValueChange={(value) => setSelectedViewMode(value)}
              size="1"
            >
              <Select.Trigger
                id="template-name"
                placeholder={
                  availableTemplates === 0
                    ? 'No more available templates'
                    : 'Available templates…'
                }
                style={{ width: '100%' }}
                disabled={!selectedContentType}
              />
              <Select.Content>
                <Select.Group>
                  {!!selectedEntityType &&
                    selectedContentType &&
                    Object.entries(
                      data?.[selectedEntityType]?.[selectedContentType] || {},
                    ).map(([mode, modeData]) => {
                      const typedModeData = modeData as unknown as ModeData;
                      return (
                        <Select.Item
                          key={mode}
                          value={mode}
                          disabled={typedModeData.hasTemplate}
                        >
                          {typedModeData.label}{' '}
                          {typedModeData.hasTemplate &&
                            '(template already exists)'}
                        </Select.Item>
                      );
                    })}
                </Select.Group>
              </Select.Content>
            </Select.Root>
          </Flex>
        )}
      </Flex>
    </Dialog>
  );
};

export default Templates;
