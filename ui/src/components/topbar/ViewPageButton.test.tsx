import { Provider } from 'react-redux';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';
import { CANVAS_PAGE_ENTITY_TYPE } from '@/constants/canvasEntityTypes';
import {
  useGetPageLayoutQuery,
  useGetTemplateLayoutQuery,
} from '@/services/componentAndLayout';

import ViewPageButton, { buildViewPageHref } from './ViewPageButton';

import type * as ComponentAndLayoutModule from '@/services/componentAndLayout';

vi.mock('@/utils/drupal-globals', async () => {
  const actual = await vi.importActual('@/utils/drupal-globals');
  return {
    ...actual,
    getBaseUrl: vi.fn().mockReturnValue('/'),
  };
});

vi.mock('@/services/componentAndLayout', async (importOriginal) => {
  const actual = await importOriginal<typeof ComponentAndLayoutModule>();
  return {
    ...actual,
    useGetPageLayoutQuery: vi.fn(),
    useGetTemplateLayoutQuery: vi.fn(),
  };
});

const mockUseGetPageLayoutQuery = vi.mocked(useGetPageLayoutQuery);
const mockUseGetTemplateLayoutQuery = vi.mocked(useGetTemplateLayoutQuery);

const defaultSkippedQuery = {
  data: undefined,
  isLoading: false,
  isError: false,
};

const renderAtPath = (path: string) => {
  const store = makeStore();
  return render(
    <Provider store={store}>
      <MemoryRouter
        initialEntries={[path]}
        future={{ v7_relativeSplatPath: true, v7_startTransition: true }}
      >
        <Theme
          accentColor="blue"
          hasBackground={false}
          panelBackground="solid"
          appearance="light"
        >
          <Routes>
            <Route
              path="/editor/:entityType/:entityId"
              element={<ViewPageButton />}
            />
            <Route
              path="/template/:entityType/:bundle/:viewMode/:previewEntityId"
              element={<ViewPageButton />}
            />
            <Route
              path="/preview/:entityType/:entityId"
              element={<ViewPageButton />}
            />
          </Routes>
        </Theme>
      </MemoryRouter>
    </Provider>,
  );
};

describe('buildViewPageHref', () => {
  it('uses the path alias when present and strips its leading slash', () => {
    expect(
      buildViewPageHref('node', '5', {
        'path[0][alias]': '/about-us',
      }),
    ).toBe('http://localhost:3000/about-us');
  });

  it('falls back to canonical entity path when alias is missing', () => {
    expect(buildViewPageHref('node', '5', {})).toBe(
      'http://localhost:3000/node/5',
    );
  });

  it('falls back to canonical entity path when entity_form_fields is undefined', () => {
    expect(buildViewPageHref('node', '5', undefined)).toBe(
      'http://localhost:3000/node/5',
    );
  });
});

describe('ViewPageButton', () => {
  beforeEach(() => {
    mockUseGetPageLayoutQuery.mockReturnValue(defaultSkippedQuery as any);
    mockUseGetTemplateLayoutQuery.mockReturnValue(defaultSkippedQuery as any);
  });

  it('renders nothing on routes without a target entity', () => {
    render(
      <Provider store={makeStore()}>
        <MemoryRouter
          initialEntries={['/editor']}
          future={{ v7_relativeSplatPath: true, v7_startTransition: true }}
        >
          <Theme
            accentColor="blue"
            hasBackground={false}
            panelBackground="solid"
            appearance="light"
          >
            <Routes>
              <Route path="/editor" element={<ViewPageButton />} />
            </Routes>
          </Theme>
        </MemoryRouter>
      </Provider>,
    );
    expect(
      screen.queryByRole('button', { name: /view page/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('link', { name: /view page/i }),
    ).not.toBeInTheDocument();
  });

  it('renders nothing on preview routes', () => {
    mockUseGetPageLayoutQuery.mockReturnValue({
      data: {
        isPublished: true,
        entity_form_fields: {},
      },
      isLoading: false,
      isError: false,
    } as any);

    renderAtPath('/preview/node/5');
    expect(
      screen.queryByRole('button', { name: /view page/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('link', { name: /view page/i }),
    ).not.toBeInTheDocument();
  });

  it('renders nothing for non-full template view modes', () => {
    renderAtPath('/template/node/page/teaser/9');
    expect(
      screen.queryByRole('button', { name: /view page/i }),
    ).not.toBeInTheDocument();
  });

  it('entity editor: shows disabled button while loading', () => {
    mockUseGetPageLayoutQuery.mockReturnValue({
      data: undefined,
      isLoading: true,
      isError: false,
    } as any);

    renderAtPath('/editor/node/5');

    expect(screen.getByRole('button', { name: /view page/i })).toBeDisabled();
  });

  it('entity editor: links when draft (unpublished) node', () => {
    mockUseGetPageLayoutQuery.mockReturnValue({
      data: {
        isPublished: false,
        entity_form_fields: {},
      },
      isLoading: false,
      isError: false,
    } as any);

    renderAtPath('/editor/node/5');

    const link = screen.getByRole('link', { name: /view page/i });
    expect(link.getAttribute('href')).toMatch(/\/node\/5$/);
  });

  it('entity editor: disabled when unpublished canvas_page', () => {
    mockUseGetPageLayoutQuery.mockReturnValue({
      data: {
        isPublished: false,
        entity_form_fields: {},
      },
      isLoading: false,
      isError: false,
    } as any);

    renderAtPath(`/editor/${CANVAS_PAGE_ENTITY_TYPE}/3`);

    expect(screen.getByRole('button', { name: /view page/i })).toBeDisabled();
  });

  it('entity editor: links when published with alias', () => {
    mockUseGetPageLayoutQuery.mockReturnValue({
      data: {
        isPublished: true,
        entity_form_fields: {
          'path[0][alias]': '/my-page',
        },
      },
      isLoading: false,
      isError: false,
    } as any);

    renderAtPath('/editor/node/5');

    const link = screen.getByRole('link', { name: /view page/i });
    expect(link).toHaveAttribute('target', '_blank');
    expect(link.getAttribute('href')).toMatch(/my-page$/);
  });

  it('entity editor: links when published without alias using canonical path', () => {
    mockUseGetPageLayoutQuery.mockReturnValue({
      data: {
        isPublished: true,
        entity_form_fields: {},
      },
      isLoading: false,
      isError: false,
    } as any);

    renderAtPath('/editor/node/5');

    const link = screen.getByRole('link', { name: /view page/i });
    expect(link.getAttribute('href')).toMatch(/\/node\/5$/);
  });

  it('full template: links when preview node is unpublished (draft)', () => {
    mockUseGetTemplateLayoutQuery.mockReturnValue({
      data: {
        isPublished: false,
        entity_form_fields: {},
      },
      isLoading: false,
      isError: false,
    } as any);

    renderAtPath('/template/node/page/full/12');

    const link = screen.getByRole('link', { name: /view page/i });
    expect(link.getAttribute('href')).toMatch(/\/node\/12$/);
  });

  it('full template: links when published with canonical path fallback', () => {
    mockUseGetTemplateLayoutQuery.mockReturnValue({
      data: {
        isPublished: true,
        entity_form_fields: {},
      },
      isLoading: false,
      isError: false,
    } as any);

    renderAtPath('/template/node/page/full/12');

    const link = screen.getByRole('link', { name: /view page/i });
    expect(link.getAttribute('href')).toMatch(/\/node\/12$/);
  });
});
