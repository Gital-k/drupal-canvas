<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class EntityFormController extends ControllerBase {

  use EntityFormTrait;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly RequestStack $requestStack,
    protected ThemeHandlerInterface $themeHandler,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
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

    // On a cold-cache POST triggered by the add or remove button, FormBuilder
    // rebuilds the form from scratch using the route entity - db values unaware
    // of how they might deviate from the autosaved entity. This can potentially
    // produce the wrong items_count. Here we identify these scenarios so we can
    // engage special handling later in this process.
    $is_cold_remove_post = FALSE;
    $is_cold_add_more_post = FALSE;
    if (!$is_get && $request?->getMethod() === 'POST') {
      $triggering = $request->request->get('_triggering_element_name', '');
      $form_build_id = $request->request->get('form_build_id');
      $is_cold_cache = FALSE;
      if ($form_build_id) {
        $is_cold_cache = \is_string($form_build_id) && ($this->keyValueExpirable->get('form')->get($form_build_id) === NULL);
      }
      if ($is_cold_cache && \is_string($triggering) &&  str_contains($triggering, '_remove_button')) {
        $is_cold_remove_post = TRUE;
      }
      if ($is_cold_cache && \is_string($triggering) && str_contains($triggering, '_add_more')) {
        $is_cold_add_more_post = TRUE;
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

    // On an add-more POST request, where there's not yet a form cache,
    // items_count is based on the entity saved in the DB, not the autosaved
    // version available to us as $entity. This can result in items_count not
    // reflecting the number of items in the autosaved entity. To address this,
    // we count the submitted deltas directly from the POST data.
    if ($is_cold_add_more_post && $request !== NULL) {
      $input = $request->request->all();
      foreach ($entity->getFields() as $field_name => $field_items) {
        $field_storage = $field_items->getFieldDefinition()->getFieldStorageDefinition();
        if ($field_storage->getCardinality() !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
          continue;
        }
        if (!isset($input[$field_name]) || !\is_array($input[$field_name])) {
          continue;
        }
        $submitted_count = count(array_filter(
          \array_keys($input[$field_name]),
          'is_numeric',
        ));
        // Subtract 1 for the trailing empty row that is always included.
        $items_count = max(0, $submitted_count - 1);
        WidgetBase::setWidgetState([], $field_name, $form_state, [
          'items_count' => $items_count,
          'array_parents' => [],
        ]);
      }
    }

    $built_form = $this->formBuilder()->buildForm($form, $form_state);

    return $built_form;
  }

}
