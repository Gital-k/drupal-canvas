# Drupal Canvas Vite Plugin

Vite plugin for Drupal Canvas code components development.

## Usage

```sh
npm install -D @drupal-canvas/vite-plugin
```

```js
// vite.config.js
import { defineConfig } from 'vite'
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react-swc";
import drupalCanvas from "@drupal-canvas/vite-plugin"

export default defineConfig({
  plugins: [react(), tailwindcss(), drupalCanvas()],
});
```

## Options

| Option          | Environment variable    | Description                                                   |
| --------------- | ----------------------- | ------------------------------------------------------------- |
| `componentDir`  | `CANVAS_COMPONENT_DIR`  | Directory where code components are stored in the filesystem. |
| `siteUrl`       | `CANVAS_SITE_URL`       | Base URL of your Drupal site.                                 |
| `jsonapiPrefix` | `CANVAS_JSONAPI_PREFIX` | Custom prefix for JSON:API requests.                          |
