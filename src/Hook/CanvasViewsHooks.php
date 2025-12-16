<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for canvas Views integration.
 */
class CanvasViewsHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_views_data_alter().
   *
   * Adds a computed publication_state field for Canvas pages that determines
   * Draft/Published/Archived status without storing data in the database.
   *
   * @deprecated in canvas:1.0.0 and is removed from canvas:2.0.0. Use
   *   moderation_state field from Content Moderation module instead.
   *
   * @see https://www.drupal.org/node/3563404
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    if (!isset($data['canvas_page_field_data'])) {
      return;
    }

    $data['canvas_page_field_data']['publication_state_computed'] = [
      'title' => $this->t('Publication State (Computed)'),
      'help' => $this->t('Shows whether a page is Draft (never published), Published (currently published), or Archived (previously published but now unpublished). This is a computed field that does not store data. It will be replaced by Content Moderation states in a future version.'),

      // Field handler (for display).
      'field' => [
        'id' => 'canvas_publication_state',
      ],

      // Filter handler (for filtering).
      'filter' => [
        'id' => 'canvas_publication_state_filter',
      ],

      // Sort handler (for sorting).
      'sort' => [
        'id' => 'canvas_publication_state_sort',
      ],
    ];
  }

}
