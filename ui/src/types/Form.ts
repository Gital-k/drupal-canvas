import type {
  ComponentModels,
  LayoutNode,
  RegionNode,
} from '@/features/layout/layoutModelSlice';
import type { ComponentsList } from '@/types/Component';

export interface InputMessage {
  type: 'error' | 'warning' | 'info';
  message: string;
}

export interface InputUIData {
  selectedComponent: string;
  components: ComponentsList | undefined;
  selectedComponentType: string;
  layout: RegionNode[];
  model?: ComponentModels;
  node?: LayoutNode | null;
  version: string;
}
