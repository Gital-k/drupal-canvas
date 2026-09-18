import { describe, expect, it } from 'vitest';

import { getDefaultPreviewImage } from '@/components/form/components/DefaultImagePreview';

import type { FieldDataItem } from '@/types/Component';

const imageSchema: FieldDataItem['jsonSchema'] = {
  type: 'object',
  properties: {
    src: { type: 'string', contentMediaType: 'image/*' },
  },
};

const videoSchema: FieldDataItem['jsonSchema'] = {
  type: 'object',
  properties: {
    src: { type: 'string', contentMediaType: 'video/*' },
    poster: { type: 'string', contentMediaType: 'image/*' },
  },
};

describe('getDefaultPreviewImage', () => {
  it('uses src and alt for image props', () => {
    expect(
      getDefaultPreviewImage(imageSchema, {
        src: '/image.png',
        alt: 'A mountain',
        width: 100,
        height: 100,
      }),
    ).toEqual({ url: '/image.png', alt: 'A mountain' });
  });

  it('falls back to a default alt for image props without alt', () => {
    expect(
      getDefaultPreviewImage(imageSchema, {
        src: '/image.png',
        alt: '',
        width: 100,
        height: 100,
      }),
    ).toEqual({ url: '/image.png', alt: 'Default' });
  });

  it('uses the poster, not the video src, for video props', () => {
    const result = getDefaultPreviewImage(videoSchema, {
      src: '/video.mp4',
      poster: 'https://placehold.co/1920x1080.png',
    });
    expect(result.url).toBe('https://placehold.co/1920x1080.png');
    expect(result.url).not.toContain('.mp4');
    expect(result.alt).toBe('Default');
  });

  it('returns no URL for video props without a poster', () => {
    expect(
      getDefaultPreviewImage(videoSchema, {
        src: '/video.mp4',
        poster: undefined as unknown as string,
      }),
    ).toEqual({ url: undefined, alt: 'Default' });
  });

  it('falls back to image behavior without a schema', () => {
    expect(
      getDefaultPreviewImage(undefined, {
        src: '/image.png',
        alt: 'A mountain',
        width: 100,
        height: 100,
      }),
    ).toEqual({ url: '/image.png', alt: 'A mountain' });
  });
});
