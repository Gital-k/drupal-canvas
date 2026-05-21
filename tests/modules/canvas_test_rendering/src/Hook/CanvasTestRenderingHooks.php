<?php

namespace Drupal\canvas_test_rendering\Hook;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
/**
 * Hook implementations for canvas_test_rendering.
 */
class CanvasTestRenderingHooks
{
    /**
     * Implements hook_entity_display_build_alter().
     */
    #[Hook('entity_display_build_alter')]
    public function entityDisplayBuildAlter(array &$build, array $context): void
    {
        $entity = $context['entity'];
        assert($entity instanceof FieldableEntityInterface);
        $view_mode = $context['view_mode'];
        assert(is_string($view_mode));
        $message = sprintf('hook_entity_display_build_alter for %s %d in %s view mode', $entity->getEntityTypeId(), $entity->id(), $view_mode);
        \Drupal::logger('canvas_test')->debug($message);
    }
}
