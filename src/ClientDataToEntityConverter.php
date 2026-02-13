<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\NestedArray;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Render\Element;
use Drupal\canvas\Form\ClientFormSubmissionHelper;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Form\FormCacheInterface;
use Drupal\canvas\Controller\EntityFormTrait;
use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\canvas\Controller\ClientServerConversionTrait;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Validator\ConstraintViolation;

class ClientDataToEntityConverter {

  use ClientServerConversionTrait;
  use EntityFormTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: FormBuilderInterface::class)]
    private readonly FormBuilderInterface & FormCacheInterface $formBuilder,
    private readonly CsrfTokenGenerator $csrfTokenGenerator,
    private readonly ComponentTreeLoader $componentTreeLoader,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * @todo remove the validate flag in https://www.drupal.org/i/3505018.
   */
  public function convert(array $client_data, FieldableEntityInterface $entity, bool $validate = TRUE): void {
    $entity_id = $entity->id();
    $request_id = uniqid('canvas_convert_', TRUE);
    $start_time = microtime(TRUE);

    // Track concurrent processing (simple static tracker)
    static $active_requests = [];
    $concurrent_requests = [];
    foreach ($active_requests as $existing_request_id => $existing_entity_id) {
      if ($existing_entity_id === $entity_id) {
        $concurrent_requests[] = $existing_request_id;
      }
    }
    $active_requests[$request_id] = $entity_id;

    // Log entry with race condition tracking
    \Drupal::logger('canvas_race_condition')->info('🚀 CONVERT START: Request @request_id for entity @entity_id (@entity_type)', [
      '@request_id' => $request_id,
      '@entity_id' => $entity_id,
      '@entity_type' => $entity->getEntityTypeId(),
      'validate' => $validate ? 'TRUE' : 'FALSE',
      'has_layout' => !empty($client_data['layout']),
      'has_model' => !empty($client_data['model']),
      'has_form_fields' => !empty($client_data['entity_form_fields']),
      'form_fields_count' => count($client_data['entity_form_fields'] ?? []),
      'concurrent_requests' => count($concurrent_requests),
      'concurrent_request_ids' => implode(', ', $concurrent_requests),
    ]);

    if (count($concurrent_requests) > 0) {
      \Drupal::logger('canvas_race_condition')->warning('⚠️ CONCURRENT ACCESS DETECTED: @count requests processing entity @entity_id simultaneously', [
        '@request_id' => $request_id,
        '@entity_id' => $entity_id,
        '@count' => count($concurrent_requests) + 1,
        'all_request_ids' => implode(', ', array_merge($concurrent_requests, [$request_id])),
      ]);
    }

    // =============================================================================
    // ENTITY LOCKING - Comment out this section to disable entity locking
    // =============================================================================
    $lock_id = "canvas_entity_conversion_{$entity_id}";
    $lock_timeout = 5;
    $lock_acquired = FALSE;

    \Drupal::logger('canvas_race_condition')->info('🔒 LOCK ATTEMPT: @request_id attempting to acquire lock @lock_id', [
      '@request_id' => $request_id,
      '@lock_id' => $lock_id,
      'timeout_seconds' => $lock_timeout,
    ]);

    // Enhanced lock acquisition with retry mechanism
    $lock_acquired = FALSE;
    $total_lock_wait = 0;
    $max_retries = 3;
    $base_delay = 100;

    for ($retry = 0; $retry <= $max_retries; $retry++) {
      $lock_start_time = microtime(TRUE);

      // Use shorter timeout per attempt, total time still reasonable
      $attempt_timeout = $retry === 0 ? $lock_timeout : min(2.0, $lock_timeout / 2);
      $lock_acquired = $this->lock->acquire($lock_id, $attempt_timeout);

      $lock_wait_duration = round((microtime(TRUE) - $lock_start_time) * 1000, 2);
      $total_lock_wait += $lock_wait_duration;

      if ($lock_acquired) {
        if ($retry > 0) {
          \Drupal::logger('canvas_race_condition')->info('✅ LOCK ACQUIRED: @request_id acquired lock @lock_id on retry @retry after @total_duration ms total', [
            '@request_id' => $request_id,
            '@lock_id' => $lock_id,
            '@retry' => $retry,
            '@total_duration' => $total_lock_wait,
          ]);
        }
        break;
      }

      // Log retry attempt
      if ($retry < $max_retries) {
        $delay = $base_delay * (2 ** $retry);
        \Drupal::logger('canvas_race_condition')->warning('🔄 LOCK RETRY: @request_id failed attempt @attempt, retrying in @delay ms', [
          '@request_id' => $request_id,
          '@attempt' => $retry + 1,
          '@delay' => $delay,
        ]);

        // Wait before retry (convert ms to microseconds)
        usleep($delay * 1000);
      }
    }

    if (!$lock_acquired) {
      \Drupal::logger('canvas_race_condition')->error('❌ LOCK EXHAUSTED: @request_id could not acquire lock @lock_id after @retries retries and @total_duration ms total', [
        '@request_id' => $request_id,
        '@lock_id' => $lock_id,
        '@retries' => $max_retries,
        '@total_duration' => $total_lock_wait,
      ]);

      // Clean up concurrent processing tracker before throwing
      unset($active_requests[$request_id]);

      // Use TooManyRequestsHttpException (429) for persistent lock contention
      // This indicates multiple concurrent requests to the same entity
      throw new TooManyRequestsHttpException(
        2,
        "Canvas is processing another operation for this content. Please wait a moment and try again."
      );
    }

    if ($retry === 0) {
      \Drupal::logger('canvas_race_condition')->info('✅ LOCK ACQUIRED: @request_id acquired lock @lock_id immediately in @duration ms', [
        '@request_id' => $request_id,
        '@lock_id' => $lock_id,
        '@duration' => $total_lock_wait,
      ]);
    }
    // Note: retry case is logged above in the retry loop
    // =============================================================================
    // END ENTITY LOCKING
    // =============================================================================
    try {
      $expected_keys = ['layout', 'model', 'entity_form_fields'];
      if (!empty(array_diff_key($client_data, array_flip($expected_keys)))) {
        throw new \LogicException();
      }
      ['layout' => $layout, 'model' => $model, 'entity_form_fields' => $entity_form_fields] = $client_data;

      $item_list = $this->componentTreeLoader->load($entity);
      try {
        \assert(count(array_intersect(['nodeType', 'id', 'name', 'components'], array_keys($layout))) === 4);
        \assert($layout['nodeType'] === 'region');
        \assert($layout['id'] === 'content');
        \assert(is_array($layout['components']));
        $item_list->setValue(self::convertClientToServer($layout['components'], $model, $entity, $validate));
      }
      catch (ConstraintViolationException $e) {
        // @todo Remove iterator_to_array() after https://www.drupal.org/project/drupal/issues/3497677
        throw new ConstraintViolationException(new EntityConstraintViolationList($entity, iterator_to_array($e->getConstraintViolationList())));
      }

      // The current user may not have access any other fields on the entity or
      // this function may have been called to only update the layout.
      $form_validation = new EntityConstraintViolationList($entity);
      if (\count($entity_form_fields) > 0) {
        \Drupal::logger('canvas_race_condition')->info('📝 FORM FIELDS PROCESSING: @request_id processing @count form fields', [
          '@request_id' => $request_id,
          '@count' => count($entity_form_fields),
          'field_keys' => implode(', ', array_keys($entity_form_fields)),
        ]);

        try {
          $this->setEntityFields($entity, $entity_form_fields);
          $this->autoSaveManager->saveEntityFormViolations($entity);

        }
        catch (ConstraintViolationException $e) {
          if (!$validate) {
            // @todo Remove this in https://drupal.org/i/3505018
            $this->autoSaveManager->saveEntityFormViolations($entity, $e->getConstraintViolationList());
          }
          $form_validation->addAll($e->getConstraintViolationList());
        }
      }

      // Validate the updated entity:
      // - at minimum the component tree field has been updated based on
      // `layout` and `model`
      // - perhaps also other fields, if `entity_form_fields` was not empty
      $updated_entity_violations = $entity->validate();
      $updated_entity_violations->addAll($form_validation);
      // Validation happens using the server-side representation, but the
      // error message should use the client-side representation received in
      // the request body.
      // @see ::convertClientToServer()
      if ($updated_entity_violations->count() && $validate) {
        $field_name = $item_list->getFieldDefinition()->getName();
        // @todo Remove iterator_to_array() after https://www.drupal.org/project/drupal/issues/3497677
        throw (new ConstraintViolationException(new EntityConstraintViolationList($entity, iterator_to_array($updated_entity_violations))))->renamePropertyPaths([
          "$field_name.0.tree[" . ComponentTreeItemList::ROOT_UUID . "]" => 'layout.children',
          "$field_name.0.tree" => 'layout',
          "$field_name.0.inputs" => 'model',
        ]);
      }
    }
    finally {
      // =============================================================================
      // ENTITY LOCKING CLEANUP - Comment out if entity locking is disabled
      // above
      // =============================================================================
      if ($lock_acquired) {
        $this->lock->release($lock_id);
        \Drupal::logger('canvas_race_condition')->info('🔓 LOCK RELEASED: @request_id released lock @lock_id', [
          '@request_id' => $request_id,
          '@lock_id' => $lock_id,
        ]);
      }
      // =============================================================================

      // Clean up concurrent processing tracker
      unset($active_requests[$request_id]);
    }

    // Log completion
    $end_time = microtime(TRUE);
    $duration = round(($end_time - $start_time) * 1000, 2);
    \Drupal::logger('canvas_race_condition')->info('✅ CONVERT COMPLETE: @request_id finished in @duration ms', [
      '@request_id' => $request_id,
      '@duration' => $duration,
      'entity_changed' => $entity instanceof EntityChangedInterface ? $entity->getChangedTime() : 'N/A',
    ]);
  }

  /**
   * Checks whether the given field should be PATCHed.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $original_field
   *   The original (stored) value for the field.
   * @param \Drupal\Core\Field\FieldItemListInterface $received_field
   *   The received value for the field.
   *
   * @return bool
   *   Whether the field should be PATCHed or not.
   *
   * @throws \Drupal\Core\Access\AccessException
   *   Thrown when the user sending the request is not allowed to update the
   *   field. Only thrown when the user could not abuse this information to
   *   determine the stored value.
   *
   * @see \Drupal\jsonapi\Controller\EntityResource::checkPatchFieldAccess
   */
  private function checkPatchFieldAccess(FieldItemListInterface $original_field, FieldItemListInterface $received_field): bool {
    // If the user is allowed to edit the field, it is always safe to set the
    // received value. We may be setting an unchanged value, but that is ok.
    $field_edit_access = $original_field->access('edit', NULL, TRUE);
    if ($field_edit_access->isAllowed()) {
      return TRUE;
    }

    // The user might not have access to edit the field, but still needs to
    // submit the current field value as part of the PATCH request. For
    // example, the entity keys required by denormalizers. Therefore, if the
    // received value equals the stored value, return FALSE without throwing an
    // exception. But only for fields that the user has access to view, because
    // the user has no legitimate way of knowing the current value of fields
    // that they are not allowed to view, and we must not make the presence or
    // absence of a 403 response a way to find that out.
    if ($original_field->access('view') && $original_field->equals($received_field)) {
      return FALSE;
    }

    // It's helpful and safe to let the user know when they are not allowed to
    // update a field.
    $field_name = $received_field->getName();
    throw new AccessException("The current user is not allowed to update the field '$field_name'.");
  }

  private function setEntityFields(FieldableEntityInterface $entity, array $entity_form_fields): array {
    $entity_id = $entity->id();
    $method_request_id = uniqid('set_fields_', TRUE);
    $method_start_time = microtime(TRUE);

    // =============================================================================
    // COMPREHENSIVE REQUEST ANALYSIS - Compare working vs non-working cases
    // =============================================================================

    // Analyze all form field keys to understand request patterns
    $form_field_keys = array_keys($entity_form_fields);
    $button_keys = array_filter($form_field_keys, function ($key) {
      return is_string($key) && (str_contains($key, '_add_more') || str_contains($key, 'submit') || str_contains($key, 'button'));
    });
    $field_keys = array_filter($form_field_keys, function ($key) {
      return is_string($key) && str_starts_with($key, 'field_');
    });
    $other_keys = array_diff($form_field_keys, $button_keys, $field_keys);

    \Drupal::logger('canvas_request_analysis')->info('🔍 REQUEST ANALYSIS: @method_id analyzing request pattern', [
      '@method_id' => $method_request_id,
      '@entity_id' => $entity_id,
      'total_keys' => count($form_field_keys),
      'button_keys' => implode(', ', $button_keys),
      'field_keys' => implode(', ', $field_keys),
      'other_keys' => implode(', ', $other_keys),
      'button_count' => count($button_keys),
      'field_count' => count($field_keys),
      'has_form_build_id' => array_key_exists('form_build_id', $entity_form_fields) ? 'YES' : 'NO',
      'has_form_token' => array_key_exists('form_token', $entity_form_fields) ? 'YES' : 'NO',
      'has_op' => array_key_exists('op', $entity_form_fields) ? 'YES: ' . $entity_form_fields['op'] : 'NO',
    ]);

    // Sample field values to understand content patterns
    foreach ($field_keys as $field_key) {
      $field_value = $entity_form_fields[$field_key];
      $value_summary = 'unknown';

      if (is_array($field_value)) {
        $item_count = count($field_value);
        $empty_count = 0;
        $filled_count = 0;

        foreach ($field_value as $item) {
          if (is_array($item) && isset($item['value'])) {
            $item_val = trim($item['value'] ?? '');
            if (empty($item_val)) {
              $empty_count++;
            }
            else {
              $filled_count++;
            }
          }
        }
        $value_summary = "array({$item_count} items: {$filled_count} filled, {$empty_count} empty)";
      }
      elseif (is_string($field_value)) {
        $value_summary = "string('" . substr($field_value, 0, 50) . "')";
      }
      else {
        $value_summary = gettype($field_value);
      }

      \Drupal::logger('canvas_request_analysis')->info('📋 FIELD CONTENT: @method_id @field = @summary', [
        '@method_id' => $method_request_id,
        '@field' => $field_key,
        '@summary' => $value_summary,
      ]);
    }

    \Drupal::logger('canvas_race_condition')->info('🔧 SET_ENTITY_FIELDS START: @method_id for entity @entity_id', [
      '@method_id' => $method_request_id,
      '@entity_id' => $entity_id,
      'field_count' => count($entity_form_fields),
      'has_form_build_id' => array_key_exists('form_build_id', $entity_form_fields) ? 'YES: ' . $entity_form_fields['form_build_id'] : 'NO',
    ]);
    $expect_form_to_update_changed = FALSE;
    if ($entity instanceof EntityChangedInterface && $entity->hasField('changed')) {
      // TRICKY: We call `$form_object->submitForm($form, $form_state);` which
      // unless overridden by the form class will call
      // \Drupal\Core\Entity\ContentEntityForm::submitForm() then
      // \Drupal\Core\Entity\ContentEntityForm::updateChangedTime() then
      // call \Drupal\Core\Entity\EntityChangedInterface::setChangedTime().
      // We do not support the client sending 'changed' as it will be set by
      // the entity form logic. Allowing the client to set 'changed' could lead
      // to inconsistencies in the entity's changed time, as different edits
      // could be done on different clients and some edits may be done outside
      // Drupal Canvas which would use timestamps provided by the server.
      // \Drupal\Core\Entity\EntityChangedInterface::setChangedTime().
      // @see \Drupal\Core\Entity\ContentEntityForm::updateChangedTime()
      unset($entity_form_fields['changed']);
      $expect_form_to_update_changed = TRUE;
    }
    // Create a form state from the received entity fields.
    $form_state = new FormState();
    $form_state->set('entity', $entity);
    // Expand form values from their respective element name, e.g.
    // ['title[0][value]' => 'Node title'] becomes
    // ['title' => ['value' => 'Node title']].
    // @see \Drupal\canvas\Controller\ApiLayoutController::getEntityData
    \parse_str(\http_build_query($entity_form_fields), $entity_form_fields);

    // Form tokens are user session-specific. It may be that a user is
    // publishing an entity for another user. Therefore, we need to ensure that
    // the form_token is for the current user.
    if (\array_key_exists('form_id', $entity_form_fields)) {
      \assert(\is_string($entity_form_fields['form_id']));
      // @see \Drupal\Core\Form\FormBuilder::prepareForm
      $token_value = 'form_token_placeholder_' . Crypt::hashBase64($entity_form_fields['form_id']);
      $entity_form_fields['form_token'] = $this->csrfTokenGenerator->get($token_value);
    }

    // Handle quirks of managed file elements.
    // @todo Remove this when https://www.drupal.org/project/drupal/issues/3498054 is fixed.
    $file_fields = \array_keys(\array_filter(
      $entity->getFields(),
      static fn (FieldItemListInterface $fieldItemList): bool => \is_a($fieldItemList->getItemDefinition()->getClass(), FileItem::class, TRUE)
    ));
    foreach (\array_intersect_key($entity_form_fields, \array_flip($file_fields)) as $field_name => $values) {
      if (!\is_array($values)) {
        continue;
      }
      foreach ($values as $delta => $value) {
        // @see \Drupal\file\Element\ManagedFile::valueCallback
        if (\array_key_exists('fids', $value) && \is_array($value['fids'])) {
          $entity_form_fields[$field_name][$delta]['fids'] = \implode(' ', $value['fids']);
        }
      }
    }

    $form_object = $this->entityTypeManager->getFormObject($entity->getEntityTypeId(), 'default');
    $form_object->setEntity($entity);
    // Flag this as a programmatic build of the entity form - but do not flag
    // the form as submitted, as we don't want to execute submit handlers such
    // as ::save that would save the entity.
    $form_state = ClientFormSubmissionHelper::prepareProgrammedFormStateForFormObject($form_state, $form_object)
      // With the values provided from the front-end.
      ->setUserInput($entity_form_fields);
    $ajax_form_build_id = $ajax_submitted_form = NULL;
    $was_programmed = TRUE;
    $was_bypassing_programmed_access_checks = FALSE;
    $was_processing_input = TRUE;
    if (\array_key_exists('form_build_id', $entity_form_fields) && \is_string($entity_form_fields['form_build_id'])) {
      $ajax_form_build_id = $entity_form_fields['form_build_id'];
      \Drupal::logger('canvas_race_condition')->info('🔄 FORM CACHE LOAD: @method_id loading cached form @build_id', [
        '@method_id' => $method_request_id,
        '@build_id' => $ajax_form_build_id,
      ]);

      // If an AJAX form submission has modified the form state, and we've
      // recorded the form_build_id in entity form fields, load the cached form
      // state and form. Widgets like Media Library keep track of the selected
      // items in form state so we need to make sure we're using the updated
      // form state.
      // Enhanced cache corruption detection
      $cache_start_time = microtime(TRUE);
      $ajax_submitted_form = $this->formBuilder->getCache($ajax_form_build_id, $form_state);
      $cache_load_duration = round((microtime(TRUE) - $cache_start_time) * 1000, 2);

      if ($ajax_submitted_form) {
        \Drupal::logger('canvas_race_condition')->info('✅ FORM CACHE HIT: @method_id successfully loaded cached form in @duration ms', [
          '@method_id' => $method_request_id,
          '@duration' => $cache_load_duration,
          'cache_size' => strlen(serialize($ajax_submitted_form)),
        ]);

        // Check for cache corruption indicators
        if (empty($ajax_submitted_form) || !is_array($ajax_submitted_form)) {
          \Drupal::logger('canvas_race_condition')->error('🚨 CACHE CORRUPTION: @method_id loaded empty or invalid cache structure', [
            '@method_id' => $method_request_id,
            '@build_id' => $ajax_form_build_id,
            'cache_type' => gettype($ajax_submitted_form),
          ]);
        }
      }
      else {
        \Drupal::logger('canvas_race_condition')->warning('❌ FORM CACHE MISS: @method_id failed to load cached form @build_id in @duration ms', [
          '@method_id' => $method_request_id,
          '@build_id' => $ajax_form_build_id,
          '@duration' => $cache_load_duration,
        ]);

        // Check if cache backend is having issues (using default cache instead
        // of form cache)
        try {
          $cache_backend_status = \Drupal::cache('default')->get('test_cache_health_' . time());
          \Drupal::logger('canvas_race_condition')->info('🔍 CACHE BACKEND STATUS: @method_id cache backend responding: @status', [
            '@method_id' => $method_request_id,
            '@status' => $cache_backend_status !== FALSE ? 'OK' : 'DEGRADED',
          ]);
        }
        catch (\Exception $e) {
          \Drupal::logger('canvas_race_condition')->warning('⚠️ CACHE BACKEND CHECK FAILED: @method_id could not check cache status: @error', [
            '@method_id' => $method_request_id,
            '@error' => $e->getMessage(),
          ]);
        }
      }
      // We've retrieved a form state from the cache, but it might now no longer
      // be programmed - despite us flagging it as so above. When we call
      // ::buildForm below, it will attempt to load the form state from the
      // cache. In doing so, it will load the version that might not be flagged
      // as programmed. We have to ensure that our call to ::buildForm doesn't
      // reload the cached form-state with this non-programmed status. When a
      // non-programmed form submission occurs, the form builder looks for a
      // triggering element. This involves looking for the 'op' form state value
      // and if that is not present it falls back to the first button on the
      // form. If the first button on the form has #limit_validation_errors set,
      // this will prevent validation callbacks being executed for the whole
      // form. Some widgets make use of validate callbacks to update the
      // submitted form values, so if these aren't executed - the form values
      // can be in an invalid state. We prevent this occurring by rewriting the
      // form state cache with the programmed flag set before we call
      // ::buildForm. When we're finished submitting the form to build the
      // entity, we will put back the cache entry the way it was.
      if (!$form_state->isProgrammed()) {
        \Drupal::logger('canvas_race_condition')->warning('⚠️ FORM STATE CONFLICT: @method_id found non-programmed cached form state, fixing...', [
          '@method_id' => $method_request_id,
          '@build_id' => $ajax_form_build_id,
          'was_programmed' => $form_state->isProgrammed() ? 'TRUE' : 'FALSE',
          'was_processing_input' => $form_state->isProcessingInput() ? 'TRUE' : 'FALSE',
        ]);

        // Make note of the cached values so we can reinstate them after
        // submitting the form.
        $was_programmed = $form_state->isProgrammed();
        $was_bypassing_programmed_access_checks = $form_state->isBypassingProgrammedAccessChecks();
        $was_processing_input = $form_state->isProcessingInput();
        // A cached un-programmed form state exists, we have to prevent the form
        // builder from loading the cached form state as it will not be a
        // programmatic submission.
        // @see \Drupal\Core\Form\FormBuilder::buildForm
        // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase::validateElement
        $form_state->setProcessInput()
          ->setProgrammed()
          ->setProgrammedBypassAccessCheck(FALSE);

        \Drupal::logger('canvas_race_condition')->info('🔧 FORM CACHE UPDATE: @method_id updating cached form state to programmed', [
          '@method_id' => $method_request_id,
        ]);

        // Update the form state cache so that the Form Builder picks up the
        // programmed version during ::buildForm.
        $this->formBuilder->setCache($ajax_form_build_id, $ajax_submitted_form, $form_state);
      }
    }

    // 'Peek' at the form to work out any form fields that are booleans or
    // buttons.
    $peek_form_object = $this->entityTypeManager->getFormObject($entity->getEntityTypeId(), 'default');
    $peek_form_state = $this->buildFormState($peek_form_object, $entity, 'default')
      // Don't fetch any form values from the request
      ->setUserInput([])
      // Don't process any input or interfere with form caches.
      ->setProcessInput(FALSE)
      // Don't perform any submission logic.
      ->setProgrammed(FALSE);
    $peek_form = $this->formBuilder->buildForm($peek_form_object, $peek_form_state);

    $buttons = array_merge(self::spotElementsByType($peek_form, 'button'), self::spotElementsByType($peek_form, 'submit'));
    $entity_form_fields = array_diff_key($entity_form_fields, \array_flip($buttons));
    // Checkboxes are unique in that the browser doesn't submit a value when the
    // field is unchecked. We need to remove these from the field values when
    // that is the case.
    $checkboxes_parents = ClientFormSubmissionHelper::spotCheckboxesParents($peek_form);
    $empty_checkboxes = \array_filter($checkboxes_parents, static fn (array $parents) => NestedArray::getValue($entity_form_fields, $parents) === '0');
    foreach ($empty_checkboxes as $parents) {
      $value = NestedArray::getValue($entity_form_fields, $parents);
      // This covers NULL, FALSE, 0 and '0' by design.
      if (empty($value)) {
        // Unchecked checkboxes are expected to be set with value NULL. For a
        // normal form submission, this is done for us by the Form Builder. But
        // for a programmatic form submission, this needs to be done manually.
        // @see \Drupal\Core\Form\FormBuilder::handleInputElement
        NestedArray::setValue($entity_form_fields, $parents, NULL);
      }
    }

    // Update the form values with unchecked checkboxes set to NULL and any
    // buttons removed.
    $form_state->setUserInput($entity_form_fields);

    // Capture debug info to log only if an error occurs.
    $pre_build_debug_info = [
      'validation_complete' => $form_state->isValidationComplete() ? 'TRUE' : 'FALSE',
      'programmed' => $form_state->isProgrammed() ? 'TRUE' : 'FALSE',
      'cache_loaded' => isset($ajax_submitted_form) ? 'TRUE' : 'FALSE',
      'build_id' => $ajax_form_build_id ?? 'none',
      'processing_input' => $form_state->isProcessingInput() ? 'TRUE' : 'FALSE',
    ];

    \Drupal::logger('canvas_race_condition')->info('🏗️ FORM BUILD START: @method_id building form', [
      '@method_id' => $method_request_id,
      'pre_build_state' => json_encode($pre_build_debug_info),
    ]);

    $build_start_time = microtime(TRUE);
    try {
      $form = $this->formBuilder->buildForm($form_object, $form_state);
      $build_duration = round((microtime(TRUE) - $build_start_time) * 1000, 2);

      \Drupal::logger('canvas_race_condition')->info('✅ FORM BUILD SUCCESS: @method_id completed in @duration ms', [
        '@method_id' => $method_request_id,
        '@duration' => $build_duration,
        'form_id' => $form['#form_id'] ?? 'unknown',
      ]);
    }
    // @phpstan-ignore-next-line catch.neverThrown
    catch (\TypeError $e) {
      // Handle situation where #element_validate does not appear to be called,
      // such as the options_buttons widget not converting the string value into
      // the expected array in
      // \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase::validateElement.
      if (strpos($e->getMessage(), 'massageFormValues') !== FALSE &&
        strpos($e->getMessage(), 'must be of type array, string given') !== FALSE) {

        // Log the issue for debugging with captured state info
        \Drupal::logger('canvas_race_condition')->error('🚨 RACE CONDITION DETECTED: @method_id buildForm TypeError - @message', [
          '@method_id' => $method_request_id,
          '@message' => $e->getMessage(),
          '@debug_info' => json_encode($pre_build_debug_info),
          'build_duration_before_error' => round((microtime(TRUE) - $build_start_time) * 1000, 2),
        ]);

        // Clear any potentially corrupted cached form state and rebuild from
        // scratch.
        if ($ajax_form_build_id) {
          $this->formBuilder->deleteCache($ajax_form_build_id);
        }

        // Create a fresh form state and try again
        $form_state = ClientFormSubmissionHelper::prepareProgrammedFormStateForFormObject(
          (new FormState()),
          $form_object
        )->setUserInput($entity_form_fields);

        $form = $this->formBuilder->buildForm($form_object, $form_state);
      }
      else {
        // Re-throw if it's a different TypeError
        throw $e;
      }
    }
    $violations_list = new EntityConstraintViolationList($entity);
    $errors = $form_state->getErrors();
    $invalid_fields = [];
    if (\count($errors) > 0) {
      foreach ($errors as $element_path => $error) {
        $parents = \explode('][', $element_path);
        // Reverse the property path to element path change made in
        // ContentEntityForm.
        // @see \Drupal\Core\Entity\ContentEntityForm::flagViolations
        $property_path = \implode('.', $parents);
        $invalid_value = NestedArray::getValue($entity_form_fields, $parents);
        $violations_list->add(new ConstraintViolation(
        // Some errors may contain markup from the user of % placeholders in
        // TranslatableMarkup. We just want the plain text version.
          PlainTextOutput::renderFromHtml((string) $error),
          NULL,
          [],
          NULL,
          $property_path,
          $invalid_value,
        ));
        $field_name = \reset($parents);
        $invalid_fields[$property_path] = $field_name;
      }
    }

    // Capture debug info to log only if an error occurs during submitForm.
    $pre_submit_debug_info = [
      'validation_complete' => $form_state->isValidationComplete() ? 'TRUE' : 'FALSE',
      'error_count' => count($form_state->getErrors()),
      'values_keys_sample' => implode(',', array_slice(array_keys($form_state->getValues()), 0, 10)) . '...',
      'has_form' => isset($form) ? 'TRUE' : 'FALSE',
    ];

    // Uncomment the following to force validation to re-run before submitForm.
    // This ensures #element_validate callbacks always run regardless of cached
    // state.
    // $form_state->setValidationComplete(FALSE);
    // $this->formBuilder->validateForm($form['#form_id'], $form, $form_state);

    // Now trigger the form level submit handler.
    \Drupal::logger('canvas_race_condition')->info('📤 FORM SUBMIT START: @method_id submitting form', [
      '@method_id' => $method_request_id,
      'pre_submit_state' => json_encode($pre_submit_debug_info),
    ]);

    $submit_start_time = microtime(TRUE);
    try {
      $form_object->submitForm($form, $form_state);
      $submit_duration = round((microtime(TRUE) - $submit_start_time) * 1000, 2);

      \Drupal::logger('canvas_race_condition')->info('✅ FORM SUBMIT SUCCESS: @method_id completed in @duration ms', [
        '@method_id' => $method_request_id,
        '@duration' => $submit_duration,
        'final_error_count' => count($form_state->getErrors()),
      ]);

      // =============================================================================
      // POST-SUBMISSION ANALYSIS - See what changed during submitForm()
      // =============================================================================
      \Drupal::logger('canvas_request_analysis')->info('📊 POST-SUBMIT STATE: @method_id analyzing entity state after submitForm()', [
        '@method_id' => $method_request_id,
        'form_rebuilt' => $form_state->isRebuilding() ? 'YES' : 'NO',
        'validation_complete' => $form_state->isValidationComplete() ? 'YES' : 'NO',
        'has_triggering_element' => $form_state->getTriggeringElement() ? 'YES' : 'NO',
        'triggering_element_name' => $form_state->getTriggeringElement()['#name'] ?? 'N/A',
        'triggering_element_value' => $form_state->getTriggeringElement()['#value'] ?? 'N/A',
      ]);

      // Check field states after submission for multi-value fields
      foreach ($entity->getFields() as $field_name => $field_items) {
        if (str_starts_with($field_name, 'field_') && $field_items->getFieldDefinition()->getFieldStorageDefinition()->isMultiple()) {
          $current_count = $field_items->count();
          $empty_count = 0;
          $filled_count = 0;

          foreach ($field_items as $item) {
            if ($item->isEmpty()) {
              $empty_count++;
            }
            else {
              $filled_count++;
            }
          }

          \Drupal::logger('canvas_request_analysis')->info('📝 FIELD STATE POST-SUBMIT: @method_id @field has @total items (@filled filled, @empty empty)', [
            '@method_id' => $method_request_id,
            '@field' => $field_name,
            '@total' => $current_count,
            '@filled' => $filled_count,
            '@empty' => $empty_count,
          ]);
        }
      }
      // =============================================================================
    }
    catch (\TypeError $e) {
      // Handle situation where #element_validate does not appear to be called,
      // such as the options_buttons widget not converting the string value
      // into the expected array in
      // \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase::validateElement.
      if (strpos($e->getMessage(), 'massageFormValues') !== FALSE &&
        strpos($e->getMessage(), 'must be of type array, string given') !== FALSE) {

        // Log the issue for debugging
        \Drupal::logger('canvas_race_condition')->error('🚨 RACE CONDITION DETECTED: @method_id submitForm TypeError - @message', [
          '@method_id' => $method_request_id,
          '@message' => $e->getMessage(),
          '@debug_info' => json_encode($pre_submit_debug_info),
          'submit_duration_before_error' => round((microtime(TRUE) - $submit_start_time) * 1000, 2),
        ]);

        // Clear any potentially corrupted cached form state and rebuild from
        // scratch.
        if ($ajax_form_build_id) {
          $this->formBuilder->deleteCache($ajax_form_build_id);
        }

        // Create a fresh form state and rebuild the form, then try submit again
        $fresh_form_state = ClientFormSubmissionHelper::prepareProgrammedFormStateForFormObject(
          (new FormState()),
          $form_object
        )->setUserInput($entity_form_fields);

        $fresh_form = $this->formBuilder->buildForm($form_object, $fresh_form_state);
        $form_object->submitForm($fresh_form, $fresh_form_state);

        // Update our references to use the fresh versions
        $form = $fresh_form;
        $form_state = $fresh_form_state;
      }
      else {
        // Re-throw if it's a different TypeError
        throw $e;
      }
    }

    if ($ajax_form_build_id !== NULL && $ajax_submitted_form !== NULL) {
      // This conversion submitted the form and didn't trigger a form rebuild.
      // That means that the form cache entry will be deleted. The AJAX form in
      // the page however still has a hidden form field pointing to the old form
      // build ID and corresponding form cache entry. This means any further
      // AJAX interactions in the page data form will send this build ID but the
      // form cache won't find the corresponding entry because the building and
      // submission we performed above has removed the form cache. As we're only
      // making use of form submissions to utilize widgets - we don't want to
      // clear the form cache for the form_build_id the actual form in the
      // browser is making use of. So we make sure to re-instate the old form
      // and form state cache entries.
      // @see \Drupal\Core\Form\FormBuilder::processForm
      $form_state->setProgrammedBypassAccessCheck($was_bypassing_programmed_access_checks)
        ->setProcessInput($was_processing_input)
        ->setProgrammed($was_programmed);

      $this->formBuilder->setCache($ajax_form_build_id, $ajax_submitted_form, $form_state);
    }
    // And retrieve the updated entity.
    $updated_entity = $form_object->getEntity();
    \assert($updated_entity instanceof FieldableEntityInterface);
    $fields_to_update = \array_intersect_key(
      $updated_entity->getFields(),
      $entity_form_fields + ($expect_form_to_update_changed ? ['changed' => NULL] : [])
    );
    foreach ($fields_to_update as $name => $items) {
      // For any form elements that yielded validation errors, revert back to
      // the value from the original entity. We do this on a per-delta basis to
      // ensure new valid deltas or any valid changes to existing deltas are
      // retained.
      \assert($items instanceof FieldItemListInterface);
      $new_value = $items->getValue();
      if (!\is_string($name) || !$entity->hasField($name) || \in_array($name, $invalid_fields, TRUE)) {
        $invalid_property_paths = \array_keys(\array_filter($invalid_fields, static fn (string $field_name) => $field_name === $name));
        $original_value = $entity->get($name);
        foreach ($invalid_property_paths as $invalid_property_path) {
          [, $delta] = \explode('.', $invalid_property_path);
          if (\is_numeric($delta)) {
            $new_value[$delta] = $original_value->get($delta)?->getValue();
          }
        }
      }
      $entity->set($name, $new_value);
    }

    \assert(!is_null($entity->id()));
    $original_entity = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    \assert($original_entity instanceof FieldableEntityInterface);
    // Filter out form_build_id, form_id and form_token.
    $entity_form_fields = array_filter(
      $entity_form_fields,
      static fn (string|int $key): bool => is_string($key) && $entity->hasField($key),
      ARRAY_FILTER_USE_KEY,
    );
    // Copied from
    // \Drupal\jsonapi\Controller\EntityResource::updateEntityField().
    foreach ($entity_form_fields as $field_name => $field_value) {
      \assert(\is_string($field_name));
      try {
        $original_field = $original_entity->get($field_name);
        // The field value on `$entity` will have been set in the call to
        // \Drupal\Core\Entity\Display\EntityFormDisplayInterface::extractFormValues()
        // above. `checkPatchFieldAccess()` will not
        // return a violation if the user does not have 'edit' access but the
        // user has 'view' access and  the received value equals the stored
        // value.
        if (!$this->checkPatchFieldAccess($original_field, $entity->get($field_name))) {
          $entity->set($field_name, $original_field->getValue());
        }
      }
      catch (\Exception $e) {
        $violations_list->add(new ConstraintViolation($e->getMessage(), $e->getMessage(), [], $field_value, "entity_form_fields.$field_name", $field_value));
      }
    }
    if ($violations_list->count()) {
      throw new ConstraintViolationException($violations_list);
    }
    // Filter out form values that are not accessible to the client.
    $values = self::filterFormValues($form_state->getValues(), $form, $original_entity);
    // Collapse form values into the respective element name, e.g.
    // ['title' => ['value' => 'Node title']] becomes
    // ['title[0][value]' => 'Node title'. This keeps the data sent in the same
    // shape as the 'name' attributes on each of the form elements built by the
    // form element and avoids needing to smooth out the idiosyncrasies of each
    // widget's structure.
    // @see \Drupal\canvas\Controller\EntityFormController::form
    $values = Query::parse(\http_build_query(\array_intersect_key($values, $entity->toArray())));
    if ($ajax_form_build_id !== NULL) {
      // Update the form build ID.
      $values['form_build_id'] = $ajax_form_build_id;
    }
    if (\array_key_exists('#form_id', $form)) {
      // Update the form ID.
      $values['form_id'] = $form['#form_id'];
    }

    $method_end_time = microtime(TRUE);
    $method_duration = round(($method_end_time - $method_start_time) * 1000, 2);
    \Drupal::logger('canvas_race_condition')->info('✅ SET_ENTITY_FIELDS COMPLETE: @method_id finished in @duration ms', [
      '@method_id' => $method_request_id,
      '@duration' => $method_duration,
      'returned_values_count' => count($values),
      'has_form_build_id_result' => array_key_exists('form_build_id', $values) ? 'YES: ' . $values['form_build_id'] : 'NO',
    ]);

    return $values;
  }

  private static function spotElementsByType(array $form, string $type): array {
    $elements = [];
    foreach (Element::children($form) as $child) {
      $element = $form[$child];
      $elements = \array_merge($elements, self::spotElementsByType($element, $type));

      if (($element['#type'] ?? NULL) === $type && \array_key_exists('#name', $element)) {
        $elements[] = $element['#name'];
      }
    }
    return $elements;
  }

}
