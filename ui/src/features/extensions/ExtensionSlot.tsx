import ExtensionButton from '@/components/extensions/ExtensionButton';
import { useGetExtensionsQuery } from '@/services/extensions';

import type React from 'react';

type ExtensionSlotProps = {
  slotLocation: string;
};

const ExtensionSlot: React.FC<ExtensionSlotProps> = ({ slotLocation }) => {
  const { data: extensionsData, isLoading, error } = useGetExtensionsQuery();

  // Handle loading state
  if (isLoading) {
    return null;
  }

  // Handle error state
  if (error || !extensionsData) {
    return null;
  }

  // Filter extensions to find those with a placement matching the slotLocation
  const extensionsWithPlacement = extensionsData.filter((extension) =>
    extension.placements?.some(
      (placement) => placement.location === slotLocation,
    ),
  );

  // If no extensions have this placement, don't render anything
  if (extensionsWithPlacement.length === 0) {
    return null;
  }

  return (
    <>
      {extensionsWithPlacement.map((extension) => (
        <ExtensionButton key={extension.id} extension={extension} />
      ))}
    </>
  );
};

export default ExtensionSlot;
