# PHPUnit

Follow [setup instructions](./setup.md) to ensure composer dev dependencies are
installed.

Check `.env.defaults` and if you need to make any alterations, copy it to `.env`
and edit as appropriate.

From the module directory, run:
```shell
composer run phpunit -- --help
```
or with DDEV:
```shell
ddev exec -d /var/www/html composer run phpunit -- --help
```

## Running tests from PhpStorm

The DDEV environment uses the standard layout: the project root maps 1:1 to
`/var/www/html` and is Mutagen-synced, so Drupal core and `vendor/` are visible
on the host. PhpStorm's stock DDEV integration configures the interpreter and
path mappings automatically; the one thing it does not configure is the PHPUnit
test framework.

Scaffold the PhpStorm `.idea` config with:
```shell
ddev phpstorm-config
```

This writes `.idea/php.xml` (a Docker Compose interpreter plus PHPUnit settings)
and patches `.idea/workspace.xml` with the path mapping. Both files are
git-ignored local config. The command is idempotent.

PhpStorm must then probe the container once to cache its PHP and PHPUnit
versions:

1. Open **Settings → PHP → CLI Interpreter**, select the `DDEV-canvas`
   interpreter, and apply/refresh it.
2. Open **Settings → PHP → Test Frameworks** and confirm PHPUnit is detected.

Afterwards, click the gutter arrow next to any test class or method to run it
inside the `ddev-canvas-web` container.
