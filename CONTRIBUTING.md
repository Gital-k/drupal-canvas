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

### How the environment is mounted

DDEV runs with `no_project_mount: true`. The Drupal scaffold (core, `vendor/`,
`index.php`, other contrib) lives in a named Docker volume at `/var/www/html`,
and this repository is bind-mounted directly at
`/var/www/html/web/modules/contrib/canvas` (see `.ddev/docker-compose.inverted.yaml`).

This replaces the older `ddev-drupal-contrib` `symlink-project` flow, which
symlinked each project file into the docroot. Because the module now has a single
container path, npm workspaces (`node_modules` at the module root) and xdebug path
mapping both behave exactly as on the host. The trade-off: Drupal core and
`vendor/` live in the volume, not on the host, so they are not indexable or
steppable in your IDE — debugging targets the Canvas module itself.

`web/` and `vendor/` no longer appear in the repository working directory, so
`git status` stays clean.

DDEV's settings management is disabled (`disable_settings_management: true`)
because it writes `settings.php` to the host docroot path, which is empty under
`no_project_mount`. The `ddev setup` command instead copies
`.ddev/drupal-settings/settings.php` and `settings.ddev.php` into the volume's
docroot; `settings.ddev.php` holds the SQLite database connection and enables
test-module discovery.

### Composer scripts (DDEV)

`ddev composer ...` runs against the scaffold's `composer.json` at
`/var/www/html`. During `ddev setup`, every Canvas composer script is also
copied into that scaffold `composer.json` as a delegating shim that `cd`s into
the module directory first, so you can run them directly:

```shell
ddev composer run ui-build
ddev composer run phpstan
ddev composer run phpunit -- tests/src/Unit/CoalescerTest.php
```

Passthrough arguments after `--` are forwarded to the module's own script. The
module's `composer.json` is never modified — only the scaffold copy is shimmed
(see `.ddev/scripts/shim-scaffold-composer-scripts.php`), so CI, host, and manual
setups, which already run from the module root, are unaffected.

The `ddev phpunit`, `ddev phpcs`, `ddev phpstan`, `ddev cypress`, `ddev playwright`
and `ddev stylelint` commands also target the module directory.

### Debugging with xdebug (PhpStorm / IDE)

1. Enable xdebug: `ddev xdebug on` (disable with `ddev xdebug off`).
2. In your IDE, map the project to the container module path:
   - Host project root → `/var/www/html/web/modules/contrib/canvas`
3. Set the server name to `canvas.ddev.site` (matches `PHP_IDE_CONFIG`).

`.idea/` is not tracked, so this is a per-developer setup.

### First experience
After this process, you will get the `drush uli` to login into the admin area. You will see an article created. If you edit the article, you will see a new link "Drupal Canvas: Test" to edit this entity with the new Canvas UI.

### Usage
The most common commands in the development process are:
1. `ddev setup`: Set up the Canvas development environment (installs dependencies, builds UI, installs Drupal). Use `ddev setup --force` to completely reset an existing environment. (Note, this is executed automatically the first time you start ddev)
2. `ddev cleanup`: Empty the Drupal scaffold volume and remove environment-specific folders and files ignored by `.gitignore` (node_modules, build artifacts, etc.). Use `ddev cleanup --force` to skip confirmation.
3. `ddev drupal-install`: This will reinstall the site. Very useful when you update Canvas and want a fresh installation.
4. `ddev ui-build`: This will build a production ready canvas UI.
5. `ddev ui-dev`: This will build a development environment for developing the canvas app itself.
6. Tests and linting: The commands `ddev eslint`, `ddev phpcs`, `ddev phpstan` and `ddev phpunit` must be used before any commit to ensure code looks good and pass tests.
7. Cypress tests: `ddev cypress run`
8. Playwright tests: `ddev playwright test`
   - Watch tests run in the browser via VNC: https://canvas.ddev.site:8444 (username: your local username, password: `secret`)
   - View test reports: Run `ddev playwright show-report` after tests complete, then open https://canvas.ddev.site:9324
   - Each test installs its own throwaway Drupal site. Its SQLite database lives
     on a tmpfs (RAM) mount at `/tmp/sqlite` so concurrent installs do not
     contend on the scaffold volume's filesystem journal, so parallel workers
     (the default) work without a `--workers` override. Test databases reset on
     every `ddev restart`; the persistent dev-site database at
     `web/sites/default/files/.ht.sqlite` is unaffected.

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
