# Contributing

For joining the development process of Drupal Canvas (Canvas for short) or trying the development process, we strongly recommend the use of [DDEV](https://ddev.com/get-started/) (version 1.24.0 or later).

## Useful links
1. [Issue queue](https://www.drupal.org/project/issues/canvas?categories=All)
2. [Source code](https://git.drupalcode.org/project/canvas)

## DDEV for your local environment

```shell
git clone git@git.drupalcode.org:project/canvas.git
cd canvas
ddev start
```

After `ddev start`, you will have a functional Drupal installation at https://canvas.ddev.site with Canvas already installed.

### First experience
After this process, you will get the `drush uli` to login into the admin area. You will see an article created. If you edit the article, you will see a new link "Drupal Canvas: Test" to edit this entity with the new Canvas UI.

### Usage
The most common commands in the development process are:
1. `ddev setup`: Set up the Canvas development environment (installs dependencies, builds UI, installs Drupal). Use `ddev setup --force` to completely reset an existing environment. (Note, this is executed automatically the first time you start ddev)
2. `ddev cleanup`: Remove environment-specific folders and files ignored by `.gitignore` (web, vendor, node_modules, build artifacts, etc.). Use `ddev cleanup --force` to skip confirmation.
3. `ddev drupal-install`: This will reinstall the site. Very useful when you update Canvas and want a fresh installation.
4. `ddev ui-build`: This will build a production ready canvas UI.
5. `ddev ui-dev`: This will build a development environment for developing the canvas app itself.
6. Tests and linting: The commands `ddev eslint`, `ddev phpcs`, `ddev phpstan` and `ddev phpunit` must be used before any commit to ensure code looks good and pass tests.
7. Cypress tests: `ddev cypress run`
8. Playwright tests: `ddev playwright test`
   - Watch tests run in the browser via VNC: https://canvas.ddev.site:8444
   - View test reports dashboard: https://canvas.ddev.site:9324

Tip: Use `ddev help <command>` for additional information about the command and arguments available.
## Setting up your local environment manually
1. Clone Drupal 11 (preferably a clone for Git archeology: `git clone git@git.drupal.org:project/drupal.git` — Drupal >=11.3 is required, so also do: `git checkout 11.3.x`).
2. `cd drupal && git clone git@git.drupal.org:project/canvas.git modules/contrib/canvas`
3. `composer require drush/drush`
4. Install Canvas's dev dependencies into the host project (required for static analysis and tests): `cd modules/contrib/canvas && composer run install-dev-deps && cd -`
5. Add `$settings['extension_discovery_scan_tests'] = TRUE;` to the end of the `sites/default/settings.php` file (this allows hidden modules to be installed).
6.a Recommended: using Recipes to set up a standardized environment for development *and* testing:
```
php core/scripts/drupal install minimal
php core/scripts/drupal recipe modules/contrib/canvas/tests/fixtures/recipes/base
php core/scripts/drupal recipe modules/contrib/canvas/tests/fixtures/recipes/test_site
```
6.b If you prefer Drush and "Standard", and then subsequently manually installing `canvas_test_*` modules:
7. Build the front end: `cd modules/contrib/canvas/ui` and then either
    * With Node.js available: `npm install && npm run build`
    * With Docker available: `docker build --output dist .`
8. You can access Drupal Canvas at `/canvas` and start by creating your first Canvas Page!
9. If you're curious: look at the code, step through it with a debugger, and join us!
10. If you want to run *all* tests locally: `composer require drupal/simple_oauth:^6 jangregor/phpstan-prophecy league/openapi-psr7-validator devizzent/cebe-php-openapi --dev && composer update`

### During development

From the Canvas project root
```shell
# Runs all Canvas back-end (PHP) linting.
composer run lint

# Runs all other Canvas linting (TS, JS, CSS, YAML, OpenAPI, spelling).
npm run lint
```

(To see everything that's available: `composer run` and `npm run` will show you.)

# Architectural Decision Records
When architectural decisions are made, they should be recorded in _ADRs_. To create an ADR:

1. Install <https://github.com/npryce/adr-tools> — see [installation instructions](https://github.com/npryce/adr-tools/blob/master/INSTALL.md).
2. From the root of this project: ```adr new This Is A New Decision```.


# Developer Tips & Tricks

## 1. Inspecting the rendered preview markup
There is a secret keyboard shortcut that is very helpful for debugging the HTML inside the iFrame.

If you press and hold the `V` key, the React app will 'disappear' until you release the `V` key.

If you press & hold the `V` key and then click on the iFrame (focusing into it),  then the V key can be released and the UI will remain hidden. Once hidden, it becomes much easier to use the browser's developer tools to inspect elements inside the iFrame.

You can then click (focus) outside the iFrame and tap the `V` key once more to return the UI.

## 2. Always start from a fresh start
1. Reinstall the site frequently (`ddev setup --force`).
2. If you're working with sqlite, delete the database frequently (`rm web/sites/default/.ht.sqlite`). (This automatically occurs when using ddev)

# Releases

See `docs/release-process.md`.

# Frequent contributors: expert tips

1. When early in an MR and/or developing low-level functionality that is unlikely to affect end-to-end (E2E) tests,
   disable them by changing `_CANVAS_E2E_TESTS: true` to `false`.
2. _Please contribute more! 🙏_
