<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Hook implementations for Canvas's auto-save functionality.
 *
 * @see \Drupal\canvas\AutoSave\AutoSaveManager
 */
class AutoSaveHooks {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly RouteMatchInterface $currentRouteMatch,
  ) {
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    // Deleting an entity invalidates its entire pending-changes set, not just
    // the auto-save matching $entity's own langcode: a content entity's
    // per-language snapshots and a config entity's per-language
    // StagedLanguageConfigOverride drafts are all separate auto-save entries.
    // Discard the whole group so no orphaned sibling draft is left behind.
    $group = $this->autoSaveManager->getTranslationGroupAutoSaves($entity);
    // An entity without any auto-save yields an empty group; still delete it
    // directly so the non-auto-save cleanup (form violations, brand kit file
    // usage) in AutoSaveManager::delete() runs.
    foreach ($group !== [] ? $group : [$entity] as $member) {
      $this->autoSaveManager->delete($member);
    }
  }

  /**
   * Implements hook_entity_translation_delete().
   */
  #[Hook('entity_translation_delete')]
  public function entityTranslationDelete(EntityInterface $translation): void {
    // Deleting a single translation removes only that translation from the
    // entity, so unlike hook_entity_delete() the sibling translations' drafts
    // must survive. Discard just the deleted translation's own snapshot,
    // keyed by its langcode; otherwise the stale snapshot is reapplied on the
    // next publish and silently resurrects the deleted translation.
    // @see \Drupal\canvas\Controller\ApiAutoSaveController::applyAutoSaveTranslationSnapshots()
    $this->autoSaveManager->delete($translation);
  }

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (str_ends_with($form_id, '_revision_revert') || str_ends_with($form_id, '_revision_revert_confirm')) {
      $entity = self::resolveRevisionableEntity($form_state, $this->currentRouteMatch);
      if ($entity === NULL) {
        return;
      }
      if (!$this->autoSaveManager->getAutoSaveEntity($entity)->isEmpty()) {
        if (!empty($form['actions']['submit']['#submit'])) {
          $form['actions']['submit']['#submit'][] = [self::class, 'revisionRevertSubmit'];
        }
        else {
          $form['#submit'][] = [self::class, 'revisionRevertSubmit'];
        }

        $form['canvas_auto_save_warning'] = [
          '#theme' => 'status_messages',
          '#message_list' => [
            'warning' => [
              new TranslatableMarkup('This page has unpublished changed in Drupal Canvas. Reverting to this revision will delete the auto-saved changes.'),
            ],
          ],
          '#status_headings' => [
            'warning' => new TranslatableMarkup('Warning'),
          ],
          '#weight' => -10,
        ];
      }
    }
  }

  /**
   * Submit handler for the revision revert form.
   *
   * Deletes the auto-saved version of the entity when reverting a revision.
   */
  public static function revisionRevertSubmit(array &$form, FormStateInterface $form_state): void {
    $entity = self::resolveRevisionableEntity($form_state, \Drupal::routeMatch());
    if ($entity instanceof EntityInterface) {
      // Delete the auto-saved version of the entity.
      \Drupal::service(AutoSaveManager::class)->delete($entity);
    }
  }

  /**
   * Resolves the revisionable entity being reverted, if available.
   */
  private static function resolveRevisionableEntity(FormStateInterface $form_state, RouteMatchInterface $route_match): ?RevisionableInterface {
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityFormInterface) {
      $entity = $form_object->getEntity();
      if ($entity instanceof RevisionableInterface) {
        return $entity;
      }
    }

    $parameters = $route_match->getParameters()->all();
    foreach (['node_revision', 'revision'] as $preferred_parameter_name) {
      if (($parameters[$preferred_parameter_name] ?? NULL) instanceof RevisionableInterface) {
        return $parameters[$preferred_parameter_name];
      }
    }

    foreach ($parameters as $parameter_name => $parameter) {
      if (str_ends_with((string) $parameter_name, '_revision') && $parameter instanceof RevisionableInterface) {
        return $parameter;
      }
    }

    foreach ($parameters as $parameter) {
      if ($parameter instanceof RevisionableInterface) {
        return $parameter;
      }
    }

    return NULL;
  }

}
