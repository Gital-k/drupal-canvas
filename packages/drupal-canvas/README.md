# Drupal Canvas Code Components Utils

Utilities and base components for building Drupal Canvas code components.

## Utilities

### cn

Utility for combining Tailwind CSS classes.

```jsx
import { cn } from 'drupal-canvas';

export default function Example() {
  return <ControlDots className="top-4 left-4 stroke-white absolute" />;
}

const ControlDots = ({ className }) => (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 31 9"
    fill="none"
    strokeWidth="2"
    className={cn('w-12', className)}
  >
    <ellipse cx="4.13" cy="4.97" rx="3.13" ry="2.97" />
    <ellipse cx="15.16" cy="4.97" rx="3.13" ry="2.97" />
    <ellipse cx="26.19" cy="4.97" rx="3.13" ry="2.97" />
  </svg>
);
```

### getPageData

Access information about the current page.

```js
import { getPageData } from 'drupal-canvas';
const { pageTitle, breadcrumbs } = getPageData();
```

### getSiteData

Access information about the site.

```js
import { getSiteData } from 'drupal-canvas';
const { siteName } = getSiteData().branding;
```

### sortLinksetMenu

Sort a menu linkset returned by the [core linkset endpoint](https://www.drupal.org/docs/develop/decoupled-drupal/decoupled-menus/decoupled-menus-overview):

```jsx
import { sortLinksetMenu } from 'drupal-canvas';
const { data } = useSWR('/system/menu/main/linkset', async (url) => {
  const response = await fetch(url);
  return response.json();
});
const menu = sortLinksetMenu(data);
```

### getNodePath

Given a node returned from `JSON:API`, return either the path alias or fall back
to /node/x path.

```jsx
import { getNodePath } from 'drupal-canvas';
const articles = data.map((article) => ({
  ...article,
  _path: getNodePath(article),
}));
```

### sortMenu

Sort menu items from
[JSON:API Menu Items](https://www.drupal.org/project/jsonapi_menu_items) module
into a tree with additional `_children` and `_hasSubmenu` properties.

```jsx
import { sortMenu, JsonApiClient } from 'drupal-canvas';
const client = new JsonApiClient();
const { data } = useSWR(['menu_items', 'main'], ([type, resourceId]) =>
  client.getResource(type, resourceId),
);
const menu = sortMenu(data);
```

### JsonApiClient

[JSON:API client](https://www.npmjs.com/package/@drupal-api-client/json-api-client)
automatically configured with a baseUrl as well as
[deserialization](https://project.pages.drupalcode.org/api_client/jsonapi-tutorial/deserializing-data/).

```jsx
import useSWR from 'swr';
import { JsonApiClient } from 'drupal-canvas';
import { DrupalJsonApiParams } from 'drupal-jsonapi-params';
const client = new JsonApiClient();

export default function List() {
  const { data, error, isLoading } = useSWR(
    [
      'node--article',
      {
        queryString: new DrupalJsonApiParams()
          .addInclude(['field_tags'])
          .getQueryString(),
      },
    ],
    ([type, options]) => client.getCollection(type, options),
  );

  if (error) return 'An error has occurred.';
  if (isLoading) return 'Loading...';
  return (
    <ul>
      {data.map((article) => (
        <li key={article.id}>{article.title}</li>
      ))}
    </ul>
  );
}
```

You can override the `baseUrl` and any default options:

```js
const client = new JsonApiClient('https://drupal-api-demo.party', {
  serializer: undefined,
  cache: undefined,
});
```

## Base Components

### FormattedText

A built-in component to render text with trusted HTML using [`dangerouslySetInnerHTML`](https://react.dev/reference/react-dom/components/common#dangerously-setting-the-inner-html).

The content is safe when processed through Drupal's filter system that is
[correctly configured](https://www.drupal.org/docs/administering-a-drupal-site/security-in-drupal/configuring-text-formats-aka-input-formats-for-security).

```jsx
import { FormattedText } from 'drupal-canvas';

export default function Example() {
  return (
    <FormattedText>
      <em>Hello, world!</em>
    </FormattedText>
  );
}
```

### Image

The [next-image-standalone](https://www.npmjs.com/package/next-image-standalone) `Image` component preconfigured with image loader compatible with Drupal Canvas dynamic image style.

```jsx
import { Image } from 'drupal-canvas';

export default function MyComponent({ photo }) {
  return (
    <Image
      src={photo.src}
      alt={photo.alt}
      width={photo.width}
      height={photo.height}
    />
  );
};
```

### Scripts

| Command      | Description                                                              |
| ------------ | ------------------------------------------------------------------------ |
| `build`      | Compile to the `dist` folder for production use.                         |
| `dev`        | Compile to the `dist` folder for development while watching for changes. |
| `type-check` | Run TypeScript type checking without emitting files.                     |
| `test`       | Run tests.                                                               |
