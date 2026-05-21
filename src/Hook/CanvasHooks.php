<?php

namespace Drupal\canvas\Hook;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropSource\FieldStorageDefinition;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Hook\Attribute\Hook;
/**
 * Hook implementations for canvas.
 */
class CanvasHooks
{
    /**
     * Implements hook_preprocess_HOOK() for regions.
     *
     * @see \Drupal\canvas\Render\MainContent\CanvasPreviewRenderer::prepare()
     */
    #[Hook('preprocess_region')]
    public function preprocessRegion(array &$variables): void
    {
        if (!array_key_exists('#canvas_region_preview', $variables['elements'])) {
            // Nothing to do: this is a region being rendered outside of a Canvas
            // preview context.
            return;
        }
        $region = $variables['region'];
        assert(is_string($region) && strlen($region) >= 1);
        $content = (string) $variables['content'];
        // Additional `content-start` and `content-end` comment annotations to
        // surround just the {{ content }} output to allow Canvas's UI to more
        // accurately render the region overlays.
        $variables['content'] = Markup::create(<<<HTML
        <!-- canvas-region-content-start-{$region} -->
        {$content}
        <!-- canvas-region-content-end-{$region} -->
        HTML);
    }
    /**
     * Implements hook_preprocess_form_element().
     */
    #[Hook('preprocess_form_element')]
    public function preprocessFormElement(array &$variables): void
    {
        if (!isset($variables['label'])) {
            return;
        }
        if (isset($variables['element']['#form_id'])) {
            $variables['label']['#form_id'] = $variables['element']['#form_id'];
        }
    }
    /**
     * Implements hook_preprocess_HOOK() for html.
     */
    #[Hook('preprocess_html')]
    public function preprocessHtml(array &$variables): void
    {
        $current_route = \Drupal::routeMatch()->getRouteName();
        if ($current_route === 'entity.canvas_page.canonical') {
            $variables['attributes']['class'][] = 'canvas-page';
        }
    }
}
