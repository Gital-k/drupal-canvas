import clsx from 'clsx';

import { a2p } from '@/local_packages/utils';

import { propagateValueChange } from './propagateValueChange';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './Select.module.css';

interface SelectProps {
  attributes?: Attributes;
  options?: Array<{
    value: string;
    label: string;
    selected: boolean;
    type: string;
  }>;
}
const Select: React.FC<SelectProps> = ({ attributes = {}, options = [] }) => {
  return (
    <select
      {...a2p(attributes)}
      className={clsx(attributes.class || '', styles.select)}
      ref={(element) => {
        if (element) {
          propagateValueChange(element, {
            property: 'value',
          });
        }
      }}
    >
      {options.map((option, index) => (
        <option key={index} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );
};

export default Select;
