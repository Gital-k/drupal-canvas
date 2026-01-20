<?php

declare(strict_types=1);

namespace Drupal\canvas\Config;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Discovery\YamlDiscovery;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Discovers and loads Canvas theme configuration from YAML files.
 *
 * Themes can define Canvas settings in {theme}.canvas.yml files. This service
 * discovers these files, handles theme inheritance, and merges configurations
 * (child themes override parent themes).
 */
final class ThemeSettingsDiscovery {

  /**
   * Static cache of discovered settings.
   *
   * @var array<string, array>
   */
  private static array $cache = [];

  /**
   * Constructs a ThemeSettingsDiscovery object.
   */
  public function __construct(
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeInitializationInterface $themeInitialization,
    private readonly string $appRoot,
    private readonly CacheBackendInterface $cacheBackend,
  ) {
  }

  /**
   * Gets the merged Canvas settings for a specific theme.
   *
   * This method follows the theme inheritance chain (base themes → active
   * theme) and merges configurations, with child themes overriding parent
   * themes.
   *
   * @param string $theme_name
   *   The theme name to get settings for.
   *
   * @return array
   *   The merged Canvas settings array.
   */
  public function getSettings(string $theme_name): array {
    // Use static cache to avoid re-discovering on the same request.
    if (isset(self::$cache[$theme_name])) {
      return self::$cache[$theme_name];
    }

    // Try to load from persistent cache.
    $cache_key = "canvas:theme_settings:{$theme_name}";
    $cached = $this->cacheBackend->get($cache_key);
    if ($cached && $cached->data) {
      self::$cache[$theme_name] = $cached->data;
      return $cached->data;
    }

    $themes = $this->themeHandler->listInfo();
    if (!isset($themes[$theme_name])) {
      $empty_result = [];
      self::$cache[$theme_name] = $empty_result;
      // Cache empty result to avoid re-checking on every request.
      $this->cacheBackend->set(
        $cache_key,
        $empty_result,
        CacheBackendInterface::CACHE_PERMANENT,
        ['config', 'canvas:theme_settings']
      );
      return $empty_result;
    }

    // Build the theme inheritance chain (base themes first, then active theme).
    $theme_chain = [];
    $current_theme = $theme_name;
    while ($current_theme && isset($themes[$current_theme])) {
      array_unshift($theme_chain, $current_theme);
      $current_theme = $themes[$current_theme]->base_theme ?? NULL;
    }

    // Get theme directories for discovery.
    // Prepend Drupal root to make paths absolute for file_exists().
    $theme_directories = [];
    foreach ($theme_chain as $theme) {
      if (isset($themes[$theme])) {
        $theme_path = $themes[$theme]->getPath();
        // Normalize path to avoid double slashes.
        $theme_directories[$theme] = rtrim($this->appRoot, '/') . '/' . ltrim($theme_path, '/');
      }
    }

    if (empty($theme_directories)) {
      self::$cache[$theme_name] = [];
      return [];
    }

    // Discover YAML files using YamlDiscovery.
    $yaml_discovery = new YamlDiscovery('canvas', $theme_directories);
    $definitions = $yaml_discovery->findAll();

    // Merge configurations in inheritance order (base themes first, then child).
    $merged_settings = [];
    foreach ($theme_chain as $theme) {
      if (isset($definitions[$theme])) {
        $theme_config = $definitions[$theme];
        // Validate and filter viewport settings if present.
        if (isset($theme_config['viewports']) && is_array($theme_config['viewports'])) {
          $theme_config['viewports'] = $this->validateViewports($theme_config['viewports']);
        }
        $merged_settings = NestedArray::mergeDeepArray(
          [$merged_settings, $theme_config],
          TRUE
        );
      }
    }

    // Ensure viewports key exists if it was set (even if empty after validation).
    // This allows the Controller to always access $theme_settings['viewports'].
    if (isset($merged_settings['viewports']) && empty($merged_settings['viewports'])) {
      // Remove empty viewports array to fall back to defaults on client side.
      unset($merged_settings['viewports']);
    }

    // Store in static cache for this request.
    self::$cache[$theme_name] = $merged_settings;

    // Store in persistent cache with tags for invalidation.
    // Use 'config' tag so it's cleared when configuration cache is cleared.
    $this->cacheBackend->set(
      $cache_key,
      $merged_settings,
      CacheBackendInterface::CACHE_PERMANENT,
      ['config', 'canvas:theme_settings']
    );

    return $merged_settings;
  }

  /**
   * Validates viewport configuration values.
   *
   * Ensures all viewport widths are positive integers. Invalid values are
   * filtered out.
   *
   * @param array $viewports
   *   Array of viewport configurations keyed by viewport ID.
   *
   * @return array
   *   Validated viewport configuration with invalid entries removed.
   */
  private function validateViewports(array $viewports): array {
    $validated = [];
    foreach ($viewports as $id => $width) {
      // Only accept positive integers.
      if (is_int($width) && $width > 0) {
        $validated[$id] = $width;
      }
      // Also accept numeric strings that represent positive integers.
      elseif (is_string($width) && ctype_digit($width) && (int) $width > 0) {
        $validated[$id] = (int) $width;
      }
    }
    return $validated;
  }

  /**
   * Gets all discovered Canvas settings, keyed by theme name.
   *
   * @return array<string, array>
   *   An array of settings keyed by theme name.
   */
  public function getAllSettings(): array {
    $all_settings = [];
    $themes = $this->themeHandler->listInfo();

    foreach (array_keys($themes) as $theme_name) {
      $all_settings[$theme_name] = $this->getSettings($theme_name);
    }

    return $all_settings;
  }

}
