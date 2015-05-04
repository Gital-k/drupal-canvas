<?php

/**
 * @file
 * Contains FileUtil.
 */

namespace Drupal\canvas_mini_site;

/**
 * Utility functions for working with files.
 */
class FileUtil {

  /**
   * Recursively remove directory.
   *
   * @param string $directory
   *   Path of directory to remove.
   *
   * @return bool
   *   TRUE if directory was removed;
   */
  public static function rrmdir($directory) {
    if (!is_dir($directory)) {
      return FALSE;
    }
    $file_iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($file_iterator as $path) {
      $path->isDir() && !$path->isLink() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    return rmdir($directory);
  }

  /**
   * Check list of filenames are using allowed extensions.
   *
   * @param array $files
   *   Array of filenames to check.
   * @param array $allowed_extensions
   *   Array of extensions that are allowed.
   *
   * @return array
   *   Array of filenames that are not using an allowed extension.
   */
  public static function checkExtensions($files, $allowed_extensions) {
    $invalid_files = array();
    foreach ($files as $file_name) {
      $path_info = pathinfo($file_name);
      if (isset($path_info['extension']) && !in_array($path_info['extension'], $allowed_extensions)) {
        $invalid_files[] = $file_name;
      }
    }
    return $invalid_files;
  }

}
