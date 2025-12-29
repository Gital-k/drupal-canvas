<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;

/**
 * Filter handler for Canvas page publication state.
 *
 * Allows filtering by Draft/Published/Archived without storing data.
 *
 * @deprecated in canvas:1.0.0 and is removed from canvas:2.0.0. There is no
 *   replacement.
 *
 * @see https://www.drupal.org/node/3563404
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter("canvas_publication_state_filter")]
class PublicationStateFilter extends InOperator {

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): ?array {
    if ($this->valueOptions !== NULL) {
      return $this->valueOptions;
    }

    $this->valueOptions = [
      'draft' => $this->t('Draft'),
      'published' => $this->t('Published'),
      'archived' => $this->t('Archived'),
    ];

    return $this->valueOptions;
  }

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

    // Get the selected values.
    $values = $this->value;
    if (!is_array($values)) {
      $values = [$values];
    }

    if (empty($values)) {
      return;
    }

    // Build the condition.
    $operator = $this->operator === 'not in' ? 'NOT IN' : 'IN';
    $placeholders = [];
    foreach ($values as $value) {
      // Escape the value for SQL injection protection.
      $escaped_value = str_replace("'", "''", (string) $value);
      $placeholders[] = "'" . $escaped_value . "'";
    }
    $condition = "({$formula}) {$operator} (" . implode(', ', $placeholders) . ")";

    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $query->addWhereExpression($this->options['group'], $condition);
  }

}
