import { describe, expect, it } from 'vitest';

import { resolvePreviewComponent } from './preview-component-resolver';

describe('resolvePreviewComponent', () => {
  it('uses default function export when available', () => {
    const Component = () => null;
    const result = resolvePreviewComponent({ default: Component });
    expect(result.component).toBe(Component);
    expect(result.reason).toBeNull();
  });

  it('falls back to default.Component when default is object metadata', () => {
    const Component = () => null;
    const result = resolvePreviewComponent({
      default: { id: 'meta', Component },
    });
    expect(result.component).toBe(Component);
  });

  it('falls back to named Component export', () => {
    const Component = () => null;
    const result = resolvePreviewComponent({ Component });
    expect(result.component).toBe(Component);
  });

  it('finds PascalCase named exports', () => {
    const HeroCard = () => null;
    const result = resolvePreviewComponent({ heroCard: 1, HeroCard });
    expect(result.component).toBe(HeroCard);
  });

  it('returns failure reason when no export is renderable', () => {
    const result = resolvePreviewComponent({ default: { id: 'meta' } });
    expect(result.component).toBeNull();
    expect(result.reason).toContain('No renderable React export found');
  });
});
