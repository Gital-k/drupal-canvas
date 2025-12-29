<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\views\sort;

use Drupal\views\Attribute\ViewsSort;
use Drupal\views\Plugin\views\sort\SortPluginBase;

/**
 * Sort handler for Canvas page publication state.
 *
 * Allows sorting by Draft/Published/Archived without storing data.
 *
 * @deprecated in canvas:1.0.0 and is removed from canvas:2.0.0. There is no
 * replacement.
 *
 * @see https://www.drupal.org/node/3563404
 *
 * @ingroup views_sort_handlers
 */
#[ViewsSort("canvas_publication_state_sort")]
class PublicationStateSort extends SortPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();

    // Build the same CASE expression used in the field handler.
    $status_field = $this->tableAlias . '.status';
    $page_id_field = $this->tableAlias . '.id';

    $formula = "CASE
      WHEN {$status_field} = 1 THEN 'published'
      WHEN {$status_field} = 0 AND EXISTS(
        SELECT 1 FROM {canvas_page_field_revision} r
        WHERE r.id = {$page_id_field}
        AND r.status = 1
        LIMIT 1
      ) THEN 'archived'
      ELSE 'draft'
    END";

    // Add the ORDER BY using the formula.
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    // @phpstan-ignore-next-line (NULL is valid for formulas in Views)
    $query->addOrderBy(NULL, $formula, $this->options['order'], 'publication_state_computed');
  }

}
