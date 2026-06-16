<?php

/**
 * @file
 * Adds the Drupal test bootstrap to PhpStorm's PHPUnit interpreter settings.
 *
 * Run inside the web container by .ddev/commands/host/phpstorm-config.
 *
 * Canvas uses the standard ddev-drupal-contrib layout: the project root is the
 * Composer root and maps 1:1 to /var/www/html in the container (Mutagen-synced).
 * Drupal core and vendor/ install into the project root and are visible on the
 * host, so PhpStorm's stock DDEV integration already creates a working Docker
 * Compose interpreter and a 1:1 path mapping. Those are left untouched here.
 *
 * The one thing that integration gets wrong for Drupal is the PHPUnit bootstrap.
 * PhpStorm runs PHPUnit with `--no-configuration` and only `custom_loader_path`
 * (vendor/autoload.php). The composer autoloader does NOT register the
 * `Drupal\Tests\<module>\` PSR-4 test namespace — only Drupal core's
 * web/core/tests/bootstrap.php does. Without it, a test whose parent class lives
 * in that namespace (e.g. a *TestBase) fails to load with "Class ... not found".
 *
 * This script patches .idea/php.xml so every <phpunit_by_interpreter> carries
 * bootstrap_file_path + use_bootstrap_file pointing at core's bootstrap. It is a
 * PATCH, not a rewrite: PhpStorm owns the interpreter, include paths, and the
 * phpinfo cache; this only touches the PhpUnit component. It is idempotent.
 *
 * Note the asymmetry that makes this necessary instead of the simpler
 * `use_configuration_file` route: phpunit.xml.dist declares
 * bootstrap="../../../core/tests/bootstrap.php", which only resolves from the
 * 3rd-party module depth (web/modules/contrib/canvas), not from the composer
 * root at /var/www/html. So we set the absolute bootstrap directly rather than
 * lean on the config file. The CLI test runner has the same fix in
 * tests/scripts/phpunit.sh.
 */

// Standard-layout paths (container-side). The project root maps 1:1 to this.
const PROJECT_ROOT = '/var/www/html';

// Drupal core's PHPUnit bootstrap. Registers the test PSR-4 namespaces. Absolute
// so it resolves regardless of the config file's location.
const BOOTSTRAP_PATH = PROJECT_ROOT . '/web/core/tests/bootstrap.php';

// The composer autoloader PhpStorm already points at; preserved when present.
const CUSTOM_LOADER_PATH = PROJECT_ROOT . '/vendor/autoload.php';

// The .ddev dir is bind-mounted at /mnt/ddev_config, so this script's __DIR__ is
// not under the project. The project root maps 1:1 to /var/www/html, where .idea
// lives. Prefer DDEV_COMPOSER_ROOT if exported.
$projectRoot = getenv('DDEV_COMPOSER_ROOT') ?: PROJECT_ROOT;
$phpXmlPath = $projectRoot . '/.idea/php.xml';

if (!is_file($phpXmlPath)) {
  fwrite(STDERR, <<<TXT
No .idea/php.xml found at $phpXmlPath.

PhpStorm writes this file after its DDEV interpreter is validated once. Open the
project in PhpStorm, then:
  1. Settings -> PHP -> CLI Interpreter, select the DDEV interpreter, apply.
  2. Settings -> PHP -> Test Frameworks, confirm PHPUnit is detected.
Then re-run `ddev phpstorm-config`.

TXT);
  exit(1);
}

$doc = new DOMDocument('1.0', 'UTF-8');
$doc->preserveWhiteSpace = FALSE;
$doc->formatOutput = TRUE;
if (!@$doc->load($phpXmlPath)) {
  fwrite(STDERR, "Could not parse $phpXmlPath; refusing to overwrite.\n");
  exit(1);
}

$patched = patch_phpunit_bootstrap($doc);

if ($patched === 0) {
  fwrite(STDERR, <<<TXT
.idea/php.xml has no PHPUnit interpreter to patch yet.

Open Settings -> PHP -> Test Frameworks in PhpStorm, add a PHPUnit configuration
for the DDEV interpreter (PHPUnit by Remote Interpreter), apply, then re-run
`ddev phpstorm-config`.

TXT);
  exit(1);
}

// Reparse to normalize formatting for a stable diff, then save.
$doc->loadXML($doc->saveXML());
$doc->preserveWhiteSpace = FALSE;
$doc->formatOutput = TRUE;
if (file_put_contents($phpXmlPath, $doc->saveXML()) === FALSE) {
  fwrite(STDERR, "Could not write $phpXmlPath\n");
  exit(1);
}

echo "Patched $patched PHPUnit interpreter setting(s) in $phpXmlPath\n";
echo "Bootstrap: " . BOOTSTRAP_PATH . "\n";

/**
 * Ensures every <phpunit_by_interpreter> carries the Drupal core bootstrap.
 *
 * Returns the number of interpreter elements patched. Creates the PhpUnit
 * component and a phpunit_by_interpreter element if none exists but a PhpStorm
 * interpreter is defined, so a freshly-detected interpreter is covered too.
 */
function patch_phpunit_bootstrap(DOMDocument $doc): int {
  $root = $doc->documentElement;

  // Find or create <component name="PhpUnit"> / <phpunit_settings>.
  $phpUnit = NULL;
  foreach ($root->getElementsByTagName('component') as $component) {
    if ($component->parentNode === $root && $component->getAttribute('name') === 'PhpUnit') {
      $phpUnit = $component;
      break;
    }
  }
  if ($phpUnit === NULL) {
    $phpUnit = $doc->createElement('component');
    $phpUnit->setAttribute('name', 'PhpUnit');
    $root->appendChild($phpUnit);
  }
  $settings = first_child_named($phpUnit, 'phpunit_settings');
  if ($settings === NULL) {
    $settings = $doc->createElement('phpunit_settings');
    $phpUnit->appendChild($settings);
  }

  $interpreterElements = [];
  foreach ($settings->getElementsByTagName('phpunit_by_interpreter') as $elem) {
    $interpreterElements[] = $elem;
  }

  // None present: create one keyed to the first PhpStorm interpreter, if any.
  if (!$interpreterElements) {
    $interpreterId = first_interpreter_id($root);
    if ($interpreterId === NULL) {
      return 0;
    }
    $elem = $doc->createElement('phpunit_by_interpreter');
    $elem->setAttribute('interpreter_id', $interpreterId);
    $elem->setAttribute('custom_loader_path', CUSTOM_LOADER_PATH);
    $elem->setAttribute('phpunit_phar_path', '');
    $settings->appendChild($elem);
    $interpreterElements[] = $elem;
  }

  foreach ($interpreterElements as $elem) {
    $elem->setAttribute('bootstrap_file_path', BOOTSTRAP_PATH);
    $elem->setAttribute('use_bootstrap_file', 'true');
    // Preserve the composer autoloader if PhpStorm did not set one.
    if (!$elem->hasAttribute('custom_loader_path')) {
      $elem->setAttribute('custom_loader_path', CUSTOM_LOADER_PATH);
    }
  }

  return count($interpreterElements);
}

/**
 * Returns the first direct child element with the given tag name, or NULL.
 */
function first_child_named(DOMElement $parent, string $tag): ?DOMElement {
  foreach ($parent->childNodes as $child) {
    if ($child instanceof DOMElement && $child->localName === $tag) {
      return $child;
    }
  }
  return NULL;
}

/**
 * Returns the id of the first PhpStorm interpreter defined in php.xml, or NULL.
 */
function first_interpreter_id(DOMElement $root): ?string {
  foreach ($root->getElementsByTagName('interpreter') as $interpreter) {
    if ($interpreter->hasAttribute('id')) {
      return $interpreter->getAttribute('id');
    }
  }
  return NULL;
}
