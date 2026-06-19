<?php

declare(strict_types=1);

use DrupalRector\Services\DrupalRectorSettings;
use DrupalRector\Set\Drupal11SetList;
use Rector\Config\RectorConfig;

/**
 * Rector configuration for `composer run (lint:)chase-upstream`.
 *
 * Ported from project_analysis@master-next, adapted for a single contrib
 * module that retains backward-compatibility (BC) layers down to its declared
 * minimum supported core version.
 *
 * The minimum supported core version is injected via the RECTOR_MIN_CORE
 * environment variable, which the composer scripts derive from the module's own
 * core_version_requirement (see scripts/resolve-min-core.php). It drives two
 * things:
 *
 *  1. Deprecations: DrupalRectorSettings::setMinimumCoreVersionSupported() makes
 *     drupal-rector wrap changes in DeprecationHelper::backwardsCompatibleCall()
 *     only for deprecations introduced *after* that version — so a module that
 *     supports ^11.2 gets the new API directly for anything up to 11.2, and BC
 *     wrappers only for 11.3+.
 *
 *  2. Breaking changes: a "breaking" rule rewrites to an API that only exists
 *     from version 11.N onward (no BC wrapper is possible). It is therefore safe
 *     to apply *only* when every supported core already has it — i.e. when the
 *     minimum supported minor is >= N. We load DRUPAL_11N_BREAKING sets up to and
 *     including the supported minor (11.2 -> 11.1 + 11.2 breaking), never beyond.
 *
 * Plain getenv() (not getenv(..., TRUE)) so the value is visible to rector's
 * parallel workers. Falls back to 10.1.0 — rector's own default and the floor
 * below which BC wrapping is disabled entirely.
 *
 * NOTE: HookConvertRector is intentionally not registered. Hook conversion is a
 * separate, opinionated transform and is deferred to a follow-up; when added it
 * must run as its own pass (it writes src/Hook/*Hooks.php outside rector's file
 * pipeline).
 */
return static function (RectorConfig $rectorConfig): void {

  $minCore = trim((string) (getenv('RECTOR_MIN_CORE') ?: '')) ?: '10.1.0';

  // All Drupal 11 deprecations; BC-wrapped per the minimum supported version
  // below. Drupal 8/9/10 sets are not loaded — Canvas requires ^11.2.
  $sets = [
    Drupal11SetList::DRUPAL_11,
  ];

  // Breaking sets, keyed by the minor they target. Include only those at or
  // below the supported minor (see point 2 above).
  $breakingByMinor = [
    1 => Drupal11SetList::DRUPAL_111_BREAKING,
    2 => Drupal11SetList::DRUPAL_112_BREAKING,
    3 => Drupal11SetList::DRUPAL_113_BREAKING,
    4 => Drupal11SetList::DRUPAL_114_BREAKING,
  ];
  if (preg_match('/^(\d+)\.(\d+)/', $minCore, $m) === 1 && (int) $m[1] === 11) {
    $supportedMinor = (int) $m[2];
    foreach ($breakingByMinor as $minor => $set) {
      if ($minor <= $supportedMinor) {
        $sets[] = $set;
      }
    }
  }
  $rectorConfig->sets($sets);

  $rectorConfig->singleton(DrupalRectorSettings::class, fn (): DrupalRectorSettings =>
    (new DrupalRectorSettings())
      ->setMinimumCoreVersionSupported($minCore)
  );

  if (class_exists(\DrupalFinder\DrupalFinderComposerRuntime::class)) {
    $drupalFinder = new \DrupalFinder\DrupalFinderComposerRuntime();
  }
  else {
    $drupalFinder = new \DrupalFinder\DrupalFinder();
    $drupalFinder->locateRoot(__DIR__);
  }
  $drupalRoot = $drupalFinder->getDrupalRoot();

  $phpstanDrupalExtension = __DIR__ . '/vendor/mglaman/phpstan-drupal/extension.neon';
  if (file_exists($phpstanDrupalExtension)) {
    $rectorConfig->phpstanConfigs([$phpstanDrupalExtension]);
  }

  $rectorConfig->autoloadPaths([
    $drupalRoot . '/core',
    $drupalRoot . '/modules',
    $drupalRoot . '/profiles',
    $drupalRoot . '/themes',
  ]);

  $rectorConfig->skip([
    '*/upgrade_status/tests/modules/*',
    '*/ProxyClass/*',
    '*/tests/fixtures/*',
  ]);
  $rectorConfig->fileExtensions(['php', 'module', 'theme', 'install', 'profile', 'inc', 'engine']);
  $rectorConfig->importNames(TRUE, FALSE);
  $rectorConfig->importShortClasses(FALSE);
  $rectorConfig->parallel();
};
