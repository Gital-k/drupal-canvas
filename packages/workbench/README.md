# Canvas Workbench (prototype)

Canvas Workbench is an early prototype for a local developer tool, similar to Storybook, but focused on Drupal Canvas Code Components and Canvas pages.

## Goal

Provide a complete local Drupal Canvas preview experience for Code Components and Canvas Pages built with Code Components from source code alone.

## What we are testing

- Run a local Vite + React app that scans your local codebase.
- Discover Drupal Canvas Code Components automatically.
- Show discovered components in a UI.
- Read component inputs from each `component.yml` and let you preview them through different example values.
- Discover Canvas pages alongside components.
- Represent pages as [json-render](https://json-render.dev) objects.
- Use json-render to preview Canvas pages locally.
- Support hot module reload (HMR) for both component and page previews.
- Explore an additional story metadata format, possibly compatible with Storybook.
- Explore integrating Canvas page json-render objects with popular React framework routing.

## Setup and run

Workbench ships a `canvas-workbench` binary that starts a bundled Vite server.

```bash
# In packages/workbench
npm run build
npm link

# Generate a new codebase
npx @drupal-canvas/create@latest
# Run in the root of the new project
npm link @drupal-canvas/workbench
npx canvas-workbench dev
```

## How the Workbench runtime works

### `bin/canvas-workbench.js`

- Resolves Vite from this package installation and runs the Vite CLI through Node.
- Ensure that Vite uses the `@drupal-canvas/workbench` package root and `vite.config.ts`, while forwarding extra CLI flags.
- Keeps `cwd` as the directory where you run the command, so discovery scans your project, not the workbench package.

### `vite.config.ts`

- Sets `root` to the workbench package, but allows Vite file access to both the workbench code and the host project directory.
- Registers a custom discovery plugin that runs `discoverCodeComponents({ scanRoot: process.cwd() })` and caches results.
- Exposes discovery data at `/__canvas/discovery` as JSON for the UI.
- Watches the host project for relevant file changes (`component.yml`, `*.component.yml`, and JS(X)/TS(X)/CSS files), refreshes discovery, and triggers a full reload.
