<?php

/**
 * @file
 * Contains Archive.
 */

namespace Drupal\canvas_mini_site;

/**
 * Wrapper around \ArchiverInterface.
 */
class Archive {

  /**
   * @var \ArchiverInterface
   */
  protected $archiver;

  /**
   * @param \stdClass $file
   *   Drupal file object.
   *
   * @return Archive|FALSE
   *   The archive. Or FALSE if unable to open the archive.
   */
  public static function open(\stdClass $file) {
    $archive = new static();
    $file_path = drupal_realpath($file->uri);
    try {
      if ($file->filemime === 'application/zip') {
        $archive->archiver = new \ArchiverZip($file_path);
      }
      else {
        $archive->archiver = new \ArchiverTar($file_path);
      }
      // Check archive is valid by listing contents.
      $archive->archiver->listContents();
    }
    catch (\Exception $e) {
      return FALSE;
    }
    return $archive;
  }

  /**
   * Lists all files in the archive.
   *
   * @return array
   *   An array of file names relative to the root of the archive.
   */
  public function listContents() {
    return $this->archiver->listContents();
  }

  /**
   * Tree of files in the archive.
   *
   * @return array
   *   Nested arrays that represent the tree structure of the files.
   */
  public function fileTree() {
    $listing = $this->listContents();
    $tree = array();
    foreach ($listing as $file_path) {
      $parts = explode('/', $file_path);
      // Files in archive end in / if a directory.
      if (substr($file_path, -1) === '/') {
        $parts = array_slice($parts, 0, -1);
        drupal_array_set_nested_value($tree, $parts, array('.' => $file_path));
      }
      else {
        drupal_array_set_nested_value($tree, $parts, $file_path);
      }
    }
    return $tree;
  }

  /**
   * Extracts multiple files in the archive to the specified path.
   *
   * @param string $path
   *   A full system path of the directory to which to extract files.
   * @param array $files
   *   (Optional) A list of files to be extracted. Files are relative to the
   *   root of the archive. If not specified, all files in the archive will be
   *   extracted.
   *
   * @return $this
   */
  public function extract($path, array $files = array()) {
    $this->archiver->extract($path, $files);
    return $this;
  }

}
