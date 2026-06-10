<?php

/**
 * @file
 * Rewrites the Drupal scaffold's composer.json scripts into delegating shims.
 *
 * Run inside the web container by .ddev/commands/host/setup after `ddev poser`.
 *
 * With the inverted bind mount the Composer root is the scaffold volume at
 * /var/www/html, while the Canvas module is bind-mounted one level down at
 * $DRUPAL_PROJECT_FOLDER. `ddev setup` seeds the scaffold's composer.json from
 * the module's, so the module's scripts (phpstan, phpcs, phpunit, …) end up in
 * the scaffold. Those scripts resolve module files relative to `$(pwd)`
 * (phpstan.neon, phpcs.xml, tests/), which only works when run from the module
 * directory — not from the scaffold root where `ddev composer run` executes.
 *
 * This rewrites each such script in the SCAFFOLD copy to `cd` into the module
 * directory and re-run the module's own (unmodified) script there, forwarding
 * any passthrough arguments. The module's composer.json is never touched, so
 * CI, host, and manual setups (which already run from the module root) are
 * unaffected.
 */

$scaffoldRoot = getenv('DDEV_COMPOSER_ROOT') ?: '/var/www/html';
$moduleDir = getenv('DRUPAL_PROJECT_FOLDER');

if (!$moduleDir) {
  fwrite(STDERR, "DRUPAL_PROJECT_FOLDER is not set; cannot shim scaffold scripts.\n");
  exit(1);
}

$scaffoldFile = $scaffoldRoot . '/composer.json';
$moduleFile = $moduleDir . '/composer.json';

foreach ([$scaffoldFile, $moduleFile] as $file) {
  if (!is_file($file)) {
    fwrite(STDERR, "Expected composer.json not found at $file\n");
    exit(1);
  }
}

$scaffold = json_decode(file_get_contents($scaffoldFile), TRUE, 512, JSON_THROW_ON_ERROR);
$module = json_decode(file_get_contents($moduleFile), TRUE, 512, JSON_THROW_ON_ERROR);

$moduleScripts = $module['scripts'] ?? [];
if (!$moduleScripts) {
  echo "No module scripts to shim.\n";
  exit(0);
}

$scaffold['scripts'] = $scaffold['scripts'] ?? [];
foreach (array_keys($moduleScripts) as $name) {
  // Delegate to the module's own script from the module directory, forwarding
  // passthrough arguments. Single-quote the path defensively even though the
  // container path has no spaces.
  $scaffold['scripts'][$name] = "cd '$moduleDir' && composer run " . escapeshellarg($name) . ' -- "$@"';
}

file_put_contents(
  $scaffoldFile,
  json_encode($scaffold, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);

printf("Shimmed %d scaffold composer script(s) to run in %s.\n", count($moduleScripts), $moduleDir);
