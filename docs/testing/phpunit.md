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
ddev composer run phpunit -- --help
```

## Running tests from PhpStorm

The DDEV environment uses the standard layout: the project root maps 1:1 to
`/var/www/html` and is Mutagen-synced, so Drupal core and `vendor/` are visible
on the host. PhpStorm's stock DDEV integration creates the Docker Compose
interpreter and the 1:1 path mapping for you.

First, let PhpStorm probe the container once so it caches the PHP and PHPUnit
versions and writes its interpreter to `.idea/php.xml`:

1. Open **Settings → PHP → CLI Interpreter**, select the DDEV interpreter, and
   apply/refresh it.
2. Open **Settings → PHP → Test Frameworks** and add a *PHPUnit by Remote
   Interpreter* configuration for that interpreter; confirm PHPUnit is detected.

The one thing PhpStorm gets wrong for Drupal is the PHPUnit bootstrap: it runs
PHPUnit with `--no-configuration` and only the Composer autoloader, which does
not register the `Drupal\Tests\<module>\` PSR-4 test namespace. Tests whose
parent class lives there (for example a `*TestBase`) then fail to load with
`Class ... not found`. Fix it with:

```shell
ddev phpstorm-config
```

This patches `.idea/php.xml` so the PHPUnit interpreter settings point at
`web/core/tests/bootstrap.php` (`bootstrap_file_path` + `use_bootstrap_file`),
which registers the test namespaces. It only edits the `PhpUnit` component,
leaving PhpStorm's interpreter, include paths, and caches untouched, and is
idempotent. `.idea/` is git-ignored local config, so run this once per checkout.

If PhpStorm was already open, close and reopen the project (or invalidate
caches) so it re-reads `.idea/php.xml`. Afterwards, click the gutter arrow next
to any test class or method to run it inside the `ddev-canvas-web` container.
