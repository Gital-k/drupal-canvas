import { createIsomorphicFn } from '@tanstack/react-start';

/**
 * Resolves the JSON:API transport `CanvasComponentTree` hands the generic
 * renderer: on the server the request-aware in-process transport (see
 * ./server-jsonapi-fetch), in the browser nothing, so the default `fetch`
 * reaches the application's same-origin proxy over HTTP.
 *
 * `createIsomorphicFn` lets TanStack Start's compiler replace this with the
 * implementation of the environment being built, so the server branch — and
 * with it the dynamic import of the server module — never reaches a browser
 * bundle. The compiler processes this package in every environment: TanStack
 * Start treats packages declaring `@tanstack/react-start` as a peer
 * dependency as framework packages, excluded from dependency pre-bundling
 * and compiled into the SSR build (the `canvas()` Vite plugin lists the SDK
 * packages as `ssr.noExternal` as well).
 */
export const getServerJsonApiFetch = createIsomorphicFn()
  .server((): typeof fetch => async (input, init) => {
    const { serverJsonApiFetch } = await import('./server-jsonapi-fetch');
    return serverJsonApiFetch(input, init);
  })
  .client((): undefined => undefined);
