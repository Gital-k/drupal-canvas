<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class EntityFormController extends ControllerBase {

  use EntityFormTrait;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly RequestStack $requestStack,
    protected ThemeHandlerInterface $themeHandler,
  ) {
  }

  public function form(string $entity_type, FieldableEntityInterface $entity, string $entity_form_mode): array {
    // @phpstan-ignore-next-line property.notFound
    if (!$this->themeHandler->themeExists('canvas_stark') || !$this->themeHandler->listInfo()['canvas_stark']->status) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('The canvas_stark theme must be enabled for this form to work.'),
      ];
    }

    // The 'default' value sent to
    // `\Drupal\Core\Entity\EntityTypeManagerInterface::getFormObject`
    // is for 'operation' not form mode.
    $form = $this->entityTypeManager()->getFormObject($entity_type, 'default');
    $form_entity = $entity;
    $request = $this->requestStack->getCurrentRequest();
    $is_get = $request?->getMethod() === 'GET';

    // On a cold-cache POST triggered by a remove button, FormBuilder rebuilds
    // the form from scratch using the route entity (DB values only). For fields
    // with unsaved auto-save additions this produces wrong items_count — the
    // remove button for the extra delta doesn't exist in the rebuilt form.
    // We detect this specific case by checking both the triggering element name
    // and whether the form cache is cold, then load the auto-save entity so
    // items_count is initialised correctly.
    // We intentionally do NOT do this for add-more or other triggers since those
    // are also cold-cache on first use and must not get a different entity.
    $is_cold_remove_post = FALSE;
    if (!$is_get && $request?->getMethod() === 'POST') {
      $triggering = $request->request->get('_triggering_element_name', '');
      if (str_contains($triggering, '_remove_button')) {
        $form_build_id = $request->request->get('form_build_id');
        if ($form_build_id) {
          /** @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $kv */
          $kv = \Drupal::service('keyvalue.expirable');
          $is_cold_remove_post = ($kv->get('form')->get($form_build_id) === NULL);
        }
      }
    }

    if ($is_get || $is_cold_remove_post) {
      $autoSave = $this->autoSaveManager->getAutoSaveEntity($entity);
      if (!$autoSave->isEmpty()) {
        \assert($autoSave->entity instanceof FieldableEntityInterface);
        $form_entity = $autoSave->entity;
        // AutoSaveManager::getAutoSaveEntity calls ::create which makes the
        // entity appear new. There are some form widgets that check if the
        // entity is new when constructing their form element. The auto-save
        // entity is never new so we enforce that to avoid issues with form
        // widgets.
        // @see \Drupal\path\Plugin\Field\FieldWidget\PathWidget::formElement
        $form_entity->enforceIsNew(FALSE);
        // We also need to record the loaded revision ID as the auto-save
        // manager does not do this for us and some widgets make use of this
        // information to load a particular revision.
        // @see \Drupal\content_moderation\Plugin\Field\FieldWidget\ModerationStateWidget::formElement
        if ($form_entity instanceof RevisionableInterface) {
          $form_entity->updateLoadedRevisionId();
        }
      }
    }

    $form_state = $this->buildFormState($form, $form_entity, $entity_form_mode);

    $built_form = $this->formBuilder()->buildForm($form, $form_state);

    // DEBUG: log entity source and items_count for comparison.
    $widget_state = \Drupal\Core\Field\WidgetBase::getWidgetState(
      [],
      'field_cvt_unlimited_number',
      $form_state,
    );
    \Drupal::logger('canvas_mv_debug')->debug(
      '[canvas:controller] form() done. method=@method form_build_id=@id is_cached=@cached entity_source=@source field_cvt_unlimited_number items_count=@count array_parents_set=@ap',
      [
        '@method' => $request?->getMethod(),
        '@id' => $built_form['#build_id'] ?? '?',
        '@cached' => $form_state->isCached() ? 'YES' : 'NO',
        '@source' => ($form_entity !== $entity) ? 'auto-save' : 'db-entity',
        '@count' => $widget_state['items_count'] ?? 'N/A',
        '@ap' => !empty($widget_state['array_parents']) ? 'YES' : 'NO',
      ]
    );

    return $built_form;
  }

}
