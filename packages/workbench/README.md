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
