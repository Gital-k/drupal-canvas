import { vi } from 'vitest';

export default () => {
  vi.useFakeTimers();
  vi.stubGlobal(
    'URL',
    vi.fn().mockImplementation((i) => i),
  );
  vi.stubGlobal(
    'fetch',
    vi.fn().mockImplementation((url: string | URL | Request) => {
      if (typeof url === 'string' && url.match(/session\/token/)) {
        return {
          text: () => new Promise((resolve) => resolve('abcdefg')),
          ok: true,
        };
      }
      const response = {
        html: '<h1>Hi there</h1>',
        layout: [],
        model: {},
        autoSaves: {},
      };
      return {
        clone: () => ({
          text: () => new Promise((resolve) => resolve('hey!')),
          headers: new Headers({
            'Content-Type': 'application.json',
          }),
          ok: true,
          json: () =>
            new Promise((resolve) =>
              resolve({
                html: '<h1>Hi there</h1>',
                layout: [],
                model: {},
                autoSaves: {},
              }),
            ),
        }),
        headers: new Headers({
          'Content-Type': 'application.json',
        }),
        ok: true,
        text: () => new Promise((resolve) => resolve(JSON.stringify(response))),
        json: () => {
          return new Promise((resolve) => resolve(response));
        },
      };
    }),
  );
};
