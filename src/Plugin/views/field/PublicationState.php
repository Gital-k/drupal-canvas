<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * A field handler that computes publication state without storing data.
 *
 * Determines whether a Canvas page is:
 * - Draft: Never published (status=0, no published revisions)
 * - Published: Currently published (status=1)
 * - Archived: Previously published (status=0, has published revisions)
 *
 * @deprecated in canvas:1.0.0 and is removed from canvas:2.0.0. Use
 *   moderation_state field from Content Moderation module instead.
 * @see \Drupal\content_moderation\Plugin\Field\ModerationStateFieldItemList
 *
 * @ingroup views_field_handlers
 */
#[ViewsField("canvas_publication_state")]
class PublicationState extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();

    // Build a CASE expression to compute the publication state.
    // We need to check:
    // 1. If status = 1, then 'published'
    // 2. If status = 0 AND exists published revision, then 'archived'
    // 3. If status = 0 AND no published revision, then 'draft'.
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

    // Add the formula as a field.
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    // @phpstan-ignore-next-line (NULL is valid for formulas in Views)
    $this->field_alias = $query->addField(NULL, $formula, 'publication_state_computed');
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);

    // Map the value to a human-readable label.
    $labels = [
      'draft' => $this->t('Draft'),
      'published' => $this->t('Published'),
      'archived' => $this->t('Archived'),
    ];

    return $labels[$value] ?? $value;
  }

}
