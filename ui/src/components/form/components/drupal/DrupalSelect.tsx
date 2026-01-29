import Select from '@/components/form/components/Select';
import { wrapRHF } from '@/components/form/wrapRHF';

import type { Attributes } from '@/types/DrupalAttribute';

export interface DrupalSelectProps {
  attributes?: Attributes;
  options?: Array<{
    value: string;
    label: string;
    selected: boolean;
    type: string;
  }>;
}

const DrupalSelect: React.FC<DrupalSelectProps> = ({
  attributes = {},
  options = [],
}) => {
  return <Select options={options} attributes={attributes} />;
};

export default wrapRHF(DrupalSelect);
