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
ddev exec -d /var/www/html/modules/contrib/canvas composer run phpunit -- --help
```

## Test groups

Every concrete test class must carry at least one `#[Group(…)]` attribute:

```php
#[Group('canvas')]
final class MyTest extends CanvasKernelTestBase {}
```

This is what makes a test selectable:
```shell
composer run phpunit -- --group canvas_data_model
```

PHPUnit does not inherit `#[Group(…)]` from a parent class, so each concrete
test class must repeat the attribute even when its base class already has one.
Abstract base classes therefore do not need one. This is enforced by the
`Canvas.Tests.RequiredGroupAttribute` sniff.

The `@group` annotation is not accepted; use the attribute.
