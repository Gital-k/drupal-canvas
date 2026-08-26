<?php

/**
 * @file
 * Resolves the minimum Drupal core version a module declares it supports.
 *
 * Used by `composer run (lint:)chase-upstream` to set the RECTOR_MIN_CORE
 * environment variable consumed by rector.php, so that drupal-rector only emits
 * BC wrappers (and only applies "breaking" sets) appropriate to the oldest core
 * the module still supports.
 *
 * Derived from project_analysis_utils' InfoUpdater\MinimumCoreResolver, but
 * resolves from the project's *root* declared requirement only — the top-level
 * <machine_name>.info.yml core_version_requirement, falling back to
 * composer.json's drupal/core. Shipped submodules are intentionally NOT scanned:
 * Canvas requires ^11.2 at the root even though some submodules still declare
 * older core, and the chase-upstream baseline follows the project's own minimum.
 *
 * Kept as a dependency-light standalone CLI so it can later move into
 * gitlab_templates for any contrib module.
 *
 * Usage:
 *   php scripts/resolve-min-core.php [PROJECT_DIR] [AUTOLOAD_PATH]
 *
 *   PROJECT_DIR     Directory to read (default: current working directory).
 *   AUTOLOAD_PATH   composer autoloader providing composer/semver + symfony/yaml
 *                   (default: discovered by walking up from PROJECT_DIR / here).
 *
 * Prints a "<major>.<minor>.0" version to stdout (e.g. "11.2.0"), never below
 * the 10.1.0 floor.
 */

declare(strict_types=1);

use Composer\Semver\VersionParser;
use Symfony\Component\Yaml\Yaml;

const FLOOR = '10.1.0';
const KEY = 'core_version_requirement';

$projectDir = $argv[1] ?? getcwd();
$autoload = $argv[2] ?? discover_autoload($projectDir);
if ($autoload === NULL || !is_file($autoload)) {
  fwrite(STDERR, "resolve-min-core: could not locate composer autoloader; falling back to " . FLOOR . "\n");
  echo FLOOR . "\n";
  exit(0);
}
require $autoload;

echo resolve($projectDir) . "\n";

/**
 * Walk up from $start (and from this script) to find a vendor/autoload.php.
 */
function discover_autoload(string $start): ?string {
  foreach ([$start, __DIR__] as $dir) {
    $dir = realpath($dir) ?: $dir;
    while ($dir && $dir !== DIRECTORY_SEPARATOR) {
      if (is_file($dir . '/vendor/autoload.php')) {
        return $dir . '/vendor/autoload.php';
      }
      $dir = dirname($dir);
    }
  }
  return NULL;
}

/**
 * Resolve the minimum supported core version string (e.g. "11.2.0").
 *
 * Order: the project's root .info.yml (preferring the one matching the composer
 * package machine name), then composer.json's drupal/core, then the floor.
 */
function resolve(string $projectDir): string {
  $machineName = composer_machine_name($projectDir . '/composer.json');

  foreach (root_info_files($projectDir, $machineName) as $file) {
    $constraint = read_info_constraint($file);
    if ($constraint !== NULL) {
      $floor = floor_of($constraint);
      if ($floor !== NULL) {
        return clamp($floor);
      }
    }
  }

  $constraint = read_composer_constraint($projectDir . '/composer.json');
  if ($constraint !== NULL) {
    $floor = floor_of($constraint);
    if ($floor !== NULL) {
      return clamp($floor);
    }
  }

  return FLOOR;
}

/**
 * Lowest version a constraint accepts, as "major.minor.0"; NULL if unusable.
 */
function floor_of(string $rawConstraint): ?string {
  $raw = trim($rawConstraint);
  if ($raw === '') {
    return NULL;
  }
  try {
    // getLowerBound() already returns the lowest bound across a "||"
    // disjunction, so "^11.2 || ^12" yields 11.2.0.0.
    $bound = (new VersionParser())->parseConstraints($raw)->getLowerBound();
  }
  catch (\Throwable) {
    // Legacy "core: 8.x" values, garbage, etc.
    return NULL;
  }
  if ($bound->isZero()) {
    return NULL;
  }
  $version = (string) preg_replace('/[-+].*$/', '', $bound->getVersion());
  if (preg_match('/^(\d+)\.(\d+)/', $version, $m) !== 1) {
    return NULL;
  }
  return $m[1] . '.' . $m[2] . '.0';
}

/**
 * Clamp a "major.minor.0" version up to the floor.
 */
function clamp(string $version): string {
  return version_compare($version, FLOOR, '<') ? FLOOR : $version;
}

/**
 * Top-level *.info.yml files in $projectDir (non-recursive).
 *
 * When the composer package machine name is known, its matching info file is
 * returned first so a multi-info project resolves from its primary module.
 *
 * @return string[]
 */
function root_info_files(string $projectDir, ?string $machineName): array {
  $files = glob($projectDir . '/*.info.yml') ?: [];
  if ($machineName !== NULL) {
    usort($files, static fn (string $a, string $b): int =>
      (basename($b) === "$machineName.info.yml" ? 1 : 0) <=> (basename($a) === "$machineName.info.yml" ? 1 : 0)
    );
  }
  return $files;
}

/**
 * The module machine name from composer.json's "name" (e.g. drupal/canvas -> canvas).
 */
function composer_machine_name(string $file): ?string {
  $contents = @file_get_contents($file);
  if ($contents === FALSE) {
    return NULL;
  }
  $data = json_decode($contents, TRUE);
  if (!is_array($data) || empty($data['name']) || !str_contains((string) $data['name'], '/')) {
    return NULL;
  }
  return explode('/', (string) $data['name'], 2)[1];
}

function read_info_constraint(string $file): ?string {
  $contents = @file_get_contents($file);
  if ($contents === FALSE) {
    return NULL;
  }
  try {
    $info = Yaml::parse($contents);
  }
  catch (\Throwable) {
    return NULL;
  }
  return is_array($info) && isset($info[KEY]) ? (string) $info[KEY] : NULL;
}

function read_composer_constraint(string $file): ?string {
  $contents = @file_get_contents($file);
  if ($contents === FALSE) {
    return NULL;
  }
  $data = json_decode($contents, TRUE);
  return is_array($data) && !empty($data['require']['drupal/core'])
    ? (string) $data['require']['drupal/core']
    : NULL;
}
