import clsx from 'clsx';

import { useFieldItemEditorContext } from './FieldItemEditorContext';

import type { ReactNode } from 'react';

import styles from './FieldItemEditor.module.css';

interface FieldItemInputWrapperProps {
  delta: number;
  children: ReactNode;
}

export default function FieldItemInputWrapper({
  delta,
  children,
}: FieldItemInputWrapperProps) {
  const context = useFieldItemEditorContext();

  if (!context) {
    return <>{children}</>;
  }

  const { selectedIndex } = context;
  const isSelected = selectedIndex === delta;

  return (
    <div
      className={clsx(styles.inputWrapper, {
        [styles.inputWrapperVisible]: isSelected,
        [styles.inputWrapperHidden]: !isSelected,
      })}
    >
      {children}
    </div>
  );
}
