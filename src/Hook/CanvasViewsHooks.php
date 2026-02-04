<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for Canvas Views integration.
 */
class CanvasViewsHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_views_data_alter().
   *
   * Adds a computed publication_state field for  Canvas pages that determines
   * Draft/Published/Archived status.
   *
   * @internal
   *   This views integration is internal and will be replaced by Workspaces in
   *   a future version.
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    if (!isset($data['canvas_page_field_data'])) {
      return;
    }

    $data['canvas_page_field_data']['publication_state_computed'] = [
      'title' => $this->t('(Deprecated) Publication State'),
      'group' => $this->t('Canvas (Internal)'),
      'help' => $this->t('Shows whether a page is Draft (never published), Published (currently published), or Archived (previously published but now unpublished). This is an internal views integration that will be replaced by Workspaces in a future version.'),
      'field' => [
        'id' => 'canvas_publication_state',
      ],
      'filter' => [
        'id' => 'canvas_publication_state_filter',
      ],
      'sort' => [
        'id' => 'canvas_publication_state_sort',
      ],
    ];
  }

}
