<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Condition\ConditionManager;

/**
 * HTTP API for the segmentation rules a personalization Segment can use.
 *
 * Segmentation rules are condition plugins, but not every condition plugin
 * makes sense as one: personalization narrows them through
 * hook_plugin_filter_condition__canvas_personalization_alter(). That narrowed
 * set is what the Drupal UI offers, what Segment::addSegmentRule() accepts and
 * what validation of a stored Segment accepts, so it is also the only set this
 * HTTP API exposes.
 *
 * ⚠️ This is highly experimental and *will* be refactored or even removed.
 *
 * @todo Revisit in https://www.drupal.org/i/3527086
 *
 * @see \Drupal\canvas_personalization\Hook\SegmentationRulesHooks::conditionPersonalizationAlter()
 * @see \Drupal\canvas_personalization\Entity\Segment::addSegmentRule()
 * @see \Drupal\canvas_personalization\Form\SegmentRuleForm
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiSegmentRuleControllers {

  public function __construct(
    private readonly ConditionManager $conditionManager,
  ) {}

  /**
   * Lists the segmentation rules that are available on this site.
   *
   * Only the rule's identity is exposed. Its settings are not: those are
   * exposed as a Drupal form by the `canvas.api.form.segment_rule` route,
   * mirroring how a component instance's props are exposed as a Drupal form.
   */
  public function list(): CacheableJsonResponse {
    $definitions = \array_filter(
      $this->conditionManager->getFilteredDefinitions('canvas_personalization'),
      \is_array(...),
    );

    $segment_rules = [];
    foreach ($definitions as $id => $definition) {
      $segment_rules[$id] = [
        'id' => (string) $id,
        'label' => (string) $definition['label'],
      ];
    }

    $response = new CacheableJsonResponse($segment_rules);
    $response->addCacheableDependency($this->conditionManager);
    $response->addCacheableDependency(
      (new CacheableMetadata())
        // Installing or uninstalling a module changes both which condition
        // plugins exist and which modules narrow them down for
        // personalization, so the response must be invalidated when the module
        // list changes.
        ->addCacheTags(['config:core.extension'])
        // Themes can narrow the plugins down too, and only the active theme's
        // implementations run.
        // @see \Drupal\Core\Plugin\FilteredPluginManagerTrait::getFilteredDefinitions()
        ->addCacheContexts(['theme'])
    );

    return $response;
  }

}
