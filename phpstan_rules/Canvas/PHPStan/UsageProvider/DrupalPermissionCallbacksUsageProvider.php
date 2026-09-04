<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use Symfony\Component\Yaml\Yaml;

/**
 * Marks permission callbacks declared in *.permissions.yml as used.
 *
 * Drupal's permission handler calls the methods listed under
 * `permission_callbacks` to generate dynamic permissions. The reference lives
 * in YAML, so ShipMonk cannot trace it and reports the method as dead.
 *
 * @see \Drupal\user\PermissionHandler::getPermissions()
 */
final class DrupalPermissionCallbacksUsageProvider extends ReflectionBasedMemberUsageProvider {

  /**
   * Callbacks parsed from all canvas *.permissions.yml files.
   *
   * Populated lazily by getPermissionCallbacks(). NULL before first call.
   *
   * @var array<string, list<string>>|null
   */
  private static ?array $permissionCallbacks = NULL;

  /**
   * Returns the method names per class declared as permission callbacks.
   *
   * Parses the main module, submodules (modules/*) and test modules
   * (tests/modules/*) permission files. Result is cached statically.
   *
   * @return array<string, list<string>>
   *   Method names, keyed by the declaring class name.
   */
  private static function getPermissionCallbacks(): array {
    if (self::$permissionCallbacks !== NULL) {
      return self::$permissionCallbacks;
    }

    $moduleRoot = dirname(__DIR__, 4);
    $callbacks = [];

    $permissionFiles = array_merge(
      glob($moduleRoot . '/*.permissions.yml') ?: [],
      glob($moduleRoot . '/modules/*/*.permissions.yml') ?: [],
      glob($moduleRoot . '/tests/modules/*/*.permissions.yml') ?: [],
    );

    foreach ($permissionFiles as $permissionFile) {
      $permissions = Yaml::parseFile($permissionFile);
      if (!\is_array($permissions) || !\is_array($permissions['permission_callbacks'] ?? NULL)) {
        continue;
      }
      foreach ($permissions['permission_callbacks'] as $callback) {
        if (!\is_string($callback) || !str_contains($callback, '::')) {
          continue;
        }
        [$className, $methodName] = explode('::', $callback, 2);
        $callbacks[ltrim($className, '\\')][] = $methodName;
      }
    }

    return self::$permissionCallbacks = $callbacks;
  }

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    $declaringClass = $method->getDeclaringClass()->getName();
    $callbacks = self::getPermissionCallbacks();
    if (!\in_array($method->getName(), $callbacks[$declaringClass] ?? [], TRUE)) {
      return NULL;
    }
    return VirtualUsageData::withNote(
      \sprintf('Declared as a permission_callbacks entry in a canvas permissions file: %s::%s().', $method->getDeclaringClass()->getShortName(), $method->getName()),
    );
  }

}
