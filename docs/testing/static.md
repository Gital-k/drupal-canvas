# Static Testing

## PHP

>>>
❗ Setup
First follow the global [setup instructions](./setup.md) to ensure you have all
the required PHP dependencies.
>>>

The following commands are all run from the module directory.

You can run all the following linting scripts in one go:
```shell
composer run lint
```

### PHP_CodeSniffer
Also known as [`phpcs`](https://github.com/squizlabs/PHP_CodeSniffer), this
script will detect violations of a defined coding standard.
```shell
composer run lint:phpcs
```

Violations, where possible, can be fixed with `phpcbf` (PHP Code Beautifier and
Fixer).
```shell
composer run fix:phpcbf
```

Configuration is located in [`phpcs.xml`](../../phpcs.xml).

### PHPStan
PHPStan performs static analysis of code to detects potential errors.
```shell
composer run lint:phpstan
```

Configuration is located in [`phpstan.neon`](../../phpstan.neon)

### Chase upstream (Rector)
Canvas uses [Drupal Rector](https://github.com/palantirnet/drupal-rector) to
semi-automatically adopt upstream Drupal core changes (deprecations and safe
breaking changes), while keeping backward-compatibility layers down to Canvas'
minimum supported core version.

Report what could be chased, without modifying files:
```shell
composer run lint:chase-upstream
```

Apply the changes (Rector + `phpcbf`), then review and commit the result:
```shell
composer run chase-upstream
```

The minimum supported core version is derived from Canvas'
`core_version_requirement` by [`scripts/resolve-min-core.php`](../../scripts/resolve-min-core.php)
and passed to Rector as `RECTOR_MIN_CORE`; configuration is located in
[`rector.php`](../../rector.php). A nightly CI job (`chase-upstream`, allowed to
fail) reports new upstream changes and attaches a `chase-upstream.patch`
artifact you can apply locally.

## Node.js

### Setup
From the module directory, install dependencies:

```shell
npm install
```

You can run all the following linting scripts in one go:
```shell
npm run lint
```

### eslint
This script will detect violations of a defined coding standard and perform
static analysis of code.

```shell
npm run lint:eslint
```

Configuration is located in [`eslint.config.mjs`](../../eslint.config.mjs).

### prettier
A code formatter to enforce coding style across the project.
```shell
npm run lint:prettier
```

### stylelint
A code formatter to enforce coding style across CSS.
```shell
npm run lint:stylelint
```

Configuration is located in [`.prettierrc.json`](../../.prettierrc.json) and
[`.prettierignore`](../../.prettierignore).

### cspell
A spell checker for code.

```shell
npm run lint:cspell
```

Configuration is located in [`.cspell.json`](../../.cspell.json).
