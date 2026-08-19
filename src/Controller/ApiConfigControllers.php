<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AssetRenderer;
use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\ComponentSource\ContextGatherer;
use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\EntityHandlers\VisibleWhenDisabledCanvasConfigEntityAccessControlHandler;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Controllers exposing HTTP API for interacting with Canvas's Config entities.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 *
 * @see \Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface
 * @see \Drupal\canvas\ClientSideRepresentation
 */
final class ApiConfigControllers extends ApiControllerBase {

  use ComponentTreeItemListInstantiatorTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly RendererInterface $renderer,
    private readonly AssetRenderer $assetRenderer,
    #[Autowire(param: 'renderer.config')]
    private readonly array $rendererConfig,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly AccessManagerInterface $accessManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ComponentSourceManager $componentSourceManager,
    private readonly ContextGatherer $contextGatherer,
  ) {}

  /**
   * Returns a list of enabled Canvas config entities in client representation.
   *
   * This controller provides a critical response for the Canvas UI. Therefore,
   * it should hence be as fast and cacheable as possible. High-cardinality
   * cache contexts (such as 'user' and 'session') result in poor cacheability.
   * Fortunately, these cache contexts only are present for the markup used for
   * previewing Canvas Components. So Canvas chooses to sacrifice accuracy of
   * the preview slightly to be able to guarantee strong cacheability and fast
   * responses.
   */
  public function list(string $canvas_config_entity_type_id, ?Request $request = NULL): CacheableJsonResponse {
    $canvas_config_entity_type = $this->entityTypeManager->getDefinition($canvas_config_entity_type_id);
    \assert($canvas_config_entity_type instanceof ConfigEntityTypeInterface);

    // Load the queried config entities: a list of all of them.
    $storage = $this->entityTypeManager->getStorage($canvas_config_entity_type_id);
    $query = $storage->getQuery()->accessCheck(TRUE);
    // Load only enabled Canvas config entities if the config entity type:
    // - specifies the `status` property as a lookup key
    // - does not use the special "visible when disabled" access control handler
    if (\in_array('status', $canvas_config_entity_type->getLookupKeys(), TRUE) && $canvas_config_entity_type->getHandlerClass('access') !== VisibleWhenDisabledCanvasConfigEntityAccessControlHandler::class) {
      $query->condition('status', TRUE);
    }

    // If the Canvas config entity type has a weight, sort by it.
    if ($canvas_config_entity_type->hasKey('weight')) {
      $query->sort('weight');
    }

    // Always sort by ID as a secondary sort to ensure deterministic ordering
    // across databases.
    // @todo Uncomment the next line once https://www.drupal.org/project/drupal/issues/2862699#comment-16461888 is fixed in Drupal core.
    $id_key = $canvas_config_entity_type->getKey('id');
    \assert(\is_string($id_key));
    $query->sort($id_key);

    $query_cacheability = (new CacheableMetadata())
      ->addCacheContexts($canvas_config_entity_type->getListCacheContexts())
      ->addCacheTags($canvas_config_entity_type->getListCacheTags());
    $canvas_config_entity_type->getClass()::refineListQuery($query, $query_cacheability);
    /** @var array<\Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface> $config_entities */
    $config_entities = $storage->loadMultiple($query->execute());
    // As config entities do not use sql-storage, we need explicit access check
    // per https://www.drupal.org/node/3201242.
    $access_cacheability = new CacheableMetadata();
    $config_entities = array_filter($config_entities, function (CanvasHttpApiEligibleConfigEntityInterface $config_entity) use ($access_cacheability): bool {
      $access = $config_entity->access('view', return_as_object: TRUE);
      $access_cacheability->addCacheableDependency($config_entity);
      return $access->isAllowed();
    });

    // When listing Components, hide block-sourced Components whose required
    // contexts cannot be satisfied for the editing situation identified by the
    // request, so editors are never offered a block that is doomed to render as
    // a placeholder. Other config entity types on this shared route, and
    // Components from non-block sources, are untouched.
    $context_filter_cacheability = new CacheableMetadata();
    if ($canvas_config_entity_type_id === Component::ENTITY_TYPE_ID) {
      $config_entities = $this->filterByContextSatisfiability($config_entities, $request, $context_filter_cacheability);
    }

    $normalizations = [];
    $normalizations_cacheability = new CacheableMetadata();
    foreach ($config_entities as $key => &$entity) {
      $representation = $this->normalize($entity);
      $normalizations[$key] = $representation->values;
      $normalizations_cacheability->addCacheableDependency($representation);
    }

    // Set a minimum cache time of one hour, because this is only a preview.
    // (Cache tag invalidations will still result in an immediate update.)
    $max_age = $normalizations_cacheability->getCacheMaxAge();
    if ($max_age !== Cache::PERMANENT) {
      $normalizations_cacheability->setCacheMaxAge(max($max_age, 3600));
    }

    // Ignore the cache tags for individual Canvas config entities, because this
    // response lists them, so the list cache tag is sufficient and the rest is
    // pointless noise.
    // @see \Drupal\Core\Entity\EntityTypeInterface::getListCacheTags()
    $total_cacheability = (new CacheableMetadata())
      ->addCacheableDependency($query_cacheability)
      ->addCacheableDependency($access_cacheability)
      ->addCacheableDependency($normalizations_cacheability)
      ->addCacheableDependency($context_filter_cacheability);
    $total_cacheability->setCacheTags(array_filter(
      $total_cacheability->getCacheTags(),
      fn (string $tag): bool =>
        // Support both Canvas config entity types provided by the main Canvas
        // module…
        !str_starts_with($tag, 'config:canvas.' . $canvas_config_entity_type_id)
        // … and by optional submodules.
        && !str_starts_with($tag, 'config:canvas_personalization.' . $canvas_config_entity_type_id),
    ));

    return (new CacheableJsonResponse($normalizations))
      ->addCacheableDependency($total_cacheability);
  }

  /**
   * Filters block-sourced Components by whether their required contexts fit.
   *
   * The context set is gathered once for the whole request (not per Component),
   * from the host entity identified by the request plus the globally available
   * contexts. A Component is kept when it is not block-sourced, when its block
   * plugin declares no required contexts, or when every required context has a
   * matching context in the gathered set.
   *
   * @param array<string, \Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface> $config_entities
   *   The Component config entities to filter.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request, carrying the optional host-entity query parameters.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cache metadata for the filtering: the request-parameter
   *   variation axis and the structural tags that invalidate eligibility.
   *
   * @return array<string, \Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface>
   *   The kept Component config entities.
   */
  private function filterByContextSatisfiability(array $config_entities, ?Request $request, CacheableMetadata $cacheability): array {
    $host_entity = $this->deriveHostEntityFromRequest($request);

    // Eligibility depends on which context *types* are available for the host
    // entity's type and bundle, never on a specific entity id, so vary only on
    // the request parameters (bounded to entity type + bundle) to avoid a
    // per-entity-id cache explosion. Field blocks appear or disappear when a
    // bundle's fields or the set of bundles change, so invalidate on those.
    $cacheability->addCacheContexts([
      'url.query_args:entity_type',
      'url.query_args:entity_bundle',
    ]);
    if ($host_entity !== NULL) {
      $cacheability->addCacheTags(['entity_bundles', 'entity_field_info']);
    }

    // Gather the available contexts once for the whole request.
    $gathered_contexts = $this->contextGatherer->getPopulatedContexts($host_entity);

    return array_filter($config_entities, function (CanvasHttpApiEligibleConfigEntityInterface $config_entity) use ($gathered_contexts): bool {
      if (!$config_entity instanceof Component) {
        return TRUE;
      }
      $source = $config_entity->getComponentSource();
      // @todo Replace this concrete BlockComponent check with a ContextAwareComponentSourceInterface once one exists, so any context-aware component source participates in library filtering, not just blocks.
      if (!$source instanceof BlockComponent) {
        return TRUE;
      }
      return $this->contextGatherer->hasRequiredContextsSatisfied($source->getContextDefinitions(), $gathered_contexts);
    });
  }

  /**
   * Derives the host entity for context gathering from the request parameters.
   *
   * The list route is shared by many config entity types, so the host entity is
   * identified by optional `entity_type` and `entity_bundle` query parameters
   * rather than a path-embedded entity: only the entity type and bundle matter
   * for context satisfiability, so no specific entity needs to be loaded (which
   * also keeps the response cacheable across entities of the same bundle). When
   * no `entity_type` is given (for example region editing, or a caller not yet
   * sending the host) only globally available contexts are considered.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   An empty host entity of the requested type and bundle, or NULL when no
   *   host entity is identified.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the requested entity type or bundle does not exist.
   */
  private function deriveHostEntityFromRequest(?Request $request): ?FieldableEntityInterface {
    $entity_type_id = $request?->query->get('entity_type');
    if ($entity_type_id === NULL || $entity_type_id === '') {
      return NULL;
    }
    if (!\is_string($entity_type_id)) {
      throw new BadRequestHttpException('The "entity_type" query parameter must be a string.');
    }
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$entity_type instanceof ContentEntityTypeInterface) {
      throw new BadRequestHttpException(\sprintf('The "%s" entity type does not exist or is not a content entity type.', $entity_type_id));
    }

    $bundle = $request->query->get('entity_bundle');
    $values = [];
    if ($entity_type->hasKey('bundle')) {
      if (!\is_string($bundle) || $bundle === '') {
        // The host's entity type is known but its bundle is not — e.g. the page
        // editor route (/editor/{entity_type}/{entity_id}) carries no bundle.
        // Fall back to no host rather than erroring, so only globally
        // satisfiable Components are listed.
        // @todo Filter by a bundled host once its bundle is known in https://www.drupal.org/project/canvas/issues/3498525
        return NULL;
      }
      if (!\array_key_exists($bundle, $this->bundleInfo->getBundleInfo($entity_type_id))) {
        throw new BadRequestHttpException(\sprintf('The "%s" bundle does not exist for the "%s" entity type.', $bundle, $entity_type_id));
      }
      $values[(string) $entity_type->getKey('bundle')] = $bundle;
    }

    $host_entity = $this->entityTypeManager->getStorage($entity_type_id)->create($values);
    \assert($host_entity instanceof FieldableEntityInterface);
    return $host_entity;
  }

  /**
   * Transforms ::list() result for the ContentTemplate listing.
   */
  public function listContentTemplatesAsHierarchy(): CacheableJsonResponse {
    // Reuse the default ::list(), and transform the result from a flat list to
    // a hierarchy, with labels for each level.
    $response = $this->list(ContentTemplate::ENTITY_TYPE_ID);
    // @phpstan-ignore-next-line argument.type
    $flat_json = json_decode($response->getContent(), TRUE);

    // @todo Generalize beyond nodes in https://www.drupal.org/i/3498525
    $supported_entity_type_ids = ['node'];
    $individual_bundle_entity_cache_tag_prefixes_to_ignore = [];

    // 1. Create the hierarchy:
    // - all supported content entity types with their bundle collection label
    // - all bundles for each supported content entity type with their label
    $hierarchical_json = [];
    $additional_cacheability = new CacheableMetadata();
    // Update whenever the list of entity types changes, not just when the set
    // of ContentTemplates config entities changes.
    // @todo Uncomment in https://www.drupal.org/i/3498525
    // $additional_cacheability->addCacheTags(['entity_types']);
    // Update whenever the list of bundles changes. Bundles may be defined via
    // hook_entity_bundle_info()  or via a bundle entity type.
    // @see \Drupal\Core\Entity\EntityTypeBundleInfo::getAllBundleInfo()
    $additional_cacheability->addCacheTags(['entity_bundles']);
    foreach ($supported_entity_type_ids as $entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      \assert($entity_type instanceof EntityTypeInterface);
      $bundle_entity_type_id = $entity_type->getBundleEntityType();

      $label = $entity_type->getCollectionLabel();
      if ($bundle_entity_type_id) {
        $bundle_entity_type = $this->entityTypeManager->getDefinition($bundle_entity_type_id);
        \assert($bundle_entity_type instanceof EntityTypeInterface);
        $label = $bundle_entity_type->getCollectionLabel();
      }
      $bundle_info = $this->bundleInfo->getBundleInfo($entity_type_id);
      $bundles = [];
      foreach ($bundle_info as $bundle_key => $info) {
        /** @var \Drupal\Core\Access\AccessResultInterface $edit_fields_access_check */
        $edit_fields_access_check = $this->accessManager->checkNamedRoute("entity.$entity_type_id.field_ui_fields", [$bundle_entity_type_id => $bundle_key], $this->currentUser, TRUE);
        \assert($edit_fields_access_check instanceof AccessResultInterface);
        $edit_fields_access = $edit_fields_access_check->isAllowed();
        /** @var \Drupal\Core\Access\AccessResultInterface $delete_access_check */
        $delete_access_check = $this->accessManager->checkNamedRoute("entity.$bundle_entity_type_id.delete_form", [$bundle_entity_type_id => $bundle_key], $this->currentUser, TRUE);
        \assert($delete_access_check instanceof AccessResultInterface);
        $delete_access = $delete_access_check->isAllowed();

        $bundles[$bundle_key] = [
          'label' => $info['label'],
          'viewModes' => [],
        ];
        if ($delete_access) {
          $delete_url = Url::fromRoute("entity.$bundle_entity_type_id.delete_form", [$bundle_entity_type_id => $bundle_key])
            ->toString();
          $bundles[$bundle_key]['deleteUrl'] = $delete_url;
        }
        else {
          $bundles[$bundle_key]['deleteUrl'] = NULL;
        }
        if ($edit_fields_access) {
          $edit_fields_url = Url::fromRoute("entity.$entity_type_id.field_ui_fields", [$bundle_entity_type_id => $bundle_key])
            ->toString();
          $bundles[$bundle_key]['editFieldsUrl'] = $edit_fields_url;
        }
        else {
          $bundles[$bundle_key]['editFieldsUrl'] = NULL;
        }

        $additional_cacheability
          ->addCacheableDependency($delete_access_check)
          ->addCacheableDependency($edit_fields_access_check);
      }

      $hierarchical_json[$entity_type_id] = [
        'label' => $label,
        'bundles' => $bundles,
      ];
      // For bundleless content entity types, omit the label.
      // For example: the `User` content entity type does not have any bundles,
      // so listing "User" twice as the label is pointless.
      if (\array_keys($hierarchical_json[$entity_type_id]['bundles']) === [$entity_type_id]) {
        $hierarchical_json[$entity_type_id]['bundles'][$entity_type_id]['label'] = NULL;
      }
      if ($bundle_entity_type_id) {
        \assert($bundle_entity_type instanceof ConfigEntityTypeInterface);
        $additional_cacheability->addCacheTags($bundle_entity_type->getListCacheTags());
        $individual_bundle_entity_cache_tag_prefixes_to_ignore[] = \sprintf("config:%s.", $bundle_entity_type->getConfigPrefix());
      }
    }

    // 2. Populate the hierarchy, which makes the nested `viewModes` key no
    // longer contain the empty array, for those that have ContentTemplates.
    foreach ($flat_json as $template_id => $normalization) {
      // Place the original normalization in its place in the hierarchy;
      // determine hierarchy using the ID.
      // @see \Drupal\canvas\Entity\ContentTemplate::id()
      [$entity_type_id, $bundle, $view_mode] = explode('.', $template_id);
      $hierarchical_json[$entity_type_id]['bundles'][$bundle]['viewModes'][$view_mode] = $normalization;
    }

    // Ignore the cache tags for individual bundle config entities (surfaced by
    // access checks on their "edit fields" and "delete" URLs), because this
    // response lists them, so the list cache tag is sufficient and the rest is
    // pointless noise.
    // @see \Drupal\Core\Entity\EntityTypeInterface::getListCacheTags()
    $additional_cacheability->setCacheTags(array_filter(
      $additional_cacheability->getCacheTags(),
      function (string $tag) use ($individual_bundle_entity_cache_tag_prefixes_to_ignore) {
        foreach ($individual_bundle_entity_cache_tag_prefixes_to_ignore as $prefix) {
          return !str_starts_with($tag, $prefix);
        }
        return FALSE;
      }
    ));

    // Overwrite data, and add to the already existing cacheability.
    return $response->setData($hierarchical_json)
      ->addCacheableDependency($additional_cacheability);
  }

  public function get(Request $request, CanvasHttpApiEligibleConfigEntityInterface $canvas_config_entity): CacheableJsonResponse {
    $canvas_config_entity_type = $canvas_config_entity->getEntityType();
    \assert($canvas_config_entity_type instanceof ConfigEntityTypeInterface);
    $representation = $this->normalize($canvas_config_entity);
    return (new CacheableJsonResponse(status: 200, data: $representation->values))
      ->addCacheableDependency($canvas_config_entity)
      ->addCacheableDependency($representation);
  }

  public function post(string $canvas_config_entity_type_id, Request $request): JsonResponse {
    $canvas_config_entity_type = $this->entityTypeManager->getDefinition($canvas_config_entity_type_id);
    \assert($canvas_config_entity_type instanceof ConfigEntityTypeInterface);

    // Create an in-memory config entity.
    $decoded = self::decode($request);
    try {
      $canvas_config_entity = $canvas_config_entity_type->getClass()::createFromClientSide($decoded);
      \assert($canvas_config_entity instanceof CanvasHttpApiEligibleConfigEntityInterface);
      $this->validate($canvas_config_entity);
    }
    catch (ConstraintViolationException $e) {
      throw $e->renamePropertyPaths([
        'component_tree.inputs' => 'model',
        'component_tree' => 'layout',
      ]);
    }

    // Save the Canvas config entity, respond with a 201 if success. Else 409.
    try {
      $canvas_config_entity->save();
    }
    catch (EntityStorageException $e) {
      throw new ConflictHttpException($e->getMessage());
    }

    $representation = $this->normalize($canvas_config_entity);
    return new JsonResponse(status: 201, data: $representation->values, headers: [
      'Location' => Url::fromRoute(
        'canvas.api.config.get',
        [
          'canvas_config_entity_type_id' => $canvas_config_entity->getEntityTypeId(),
          'canvas_config_entity' => $canvas_config_entity->id(),
        ])
        ->toString(TRUE)
        ->getGeneratedUrl(),
    ]);
  }

  public static function delete(CanvasHttpApiEligibleConfigEntityInterface $canvas_config_entity): JsonResponse {
    // @todo First validate that there is no other entity depending on this. If there is, respond with a 400, 409, 412 or 422 (TBD).
    // @todo Permissions take into account config dependencies, but we might have content dependencies depending on it too. See https://www.drupal.org/project/canvas/issues/3516839
    // @see https://www.drupal.org/project/drupal/issues/3423459
    $canvas_config_entity->delete();
    return new JsonResponse(status: 204, data: NULL);
  }

  public function patch(Request $request, CanvasHttpApiEligibleConfigEntityInterface $canvas_config_entity): JsonResponse {
    $decoded = self::decode($request);
    $canvas_config_entity->updateFromClientSide($decoded);
    try {
      $this->validate($canvas_config_entity);
    }
    catch (ConstraintViolationException $e) {
      throw $e->renamePropertyPaths([
        'component_tree.inputs' => 'model',
        'component_tree' => 'layout',
      ]);
    }

    // Save the Canvas config entity, respond with a 200.
    $canvas_config_entity->save();
    $canvas_config_entity_type = $canvas_config_entity->getEntityType();
    \assert($canvas_config_entity_type instanceof ConfigEntityTypeInterface);
    $representation = $this->normalize($canvas_config_entity);
    return new JsonResponse(status: 200, data: $representation->values);
  }

  /**
   * Rejects requests from clients that still synchronize page regions.
   */
  public static function pageRegionGone(Request $request): JsonResponse {
    $message = 'Global regions were replaced by page templates.';
    if ($request->headers->has('X-Canvas-CLI')) {
      $message .= ' Upgrade @drupal-canvas/cli, or use --no-regions to continue without region synchronization.';
    }
    return new JsonResponse([
      'message' => $message,
    ], Response::HTTP_GONE);
  }

  private static function validate(CanvasHttpApiEligibleConfigEntityInterface $canvas_config_entity): void {
    $violations = $canvas_config_entity->getTypedData()->validate();
    if ($violations->count()) {
      throw new ConstraintViolationException($violations);
    }
  }

  /**
   * Normalizes this config entity, ensuring strong cacheability.
   *
   * Strong cacheability is "ensured" by accepting imperfect previews, when
   * those previews are highly dynamic.
   */
  private function normalize(CanvasHttpApiEligibleConfigEntityInterface $entity): ClientSideRepresentation {
    // Auto-update Pattern's component instances before serving them, which will
    // make the preview accurate with what the editor would see when adding the
    // Pattern to the component tree being edited.
    // @todo Refine in https://www.drupal.org/project/canvas/issues/3571366
    if ($entity instanceof Pattern) {
      $tree = $entity->getComponentTree();
      $this->componentSourceManager->updateComponentInstances($tree);
      $entity->setComponentTree($tree->getValue());
    }

    // TRICKY: some components may (erroneously!) bubble cacheability even
    // when just constructing a render array. For maximum ecosystem
    // compatibility, account for this, and catch the bubbled cacheability.
    // @see \Drupal\views\Plugin\Block\ViewsBlock::build()
    $get_representation = function (CanvasHttpApiEligibleConfigEntityInterface $entity): ClientSideRepresentation {
      $context = new RenderContext();
      $representation = $this->renderer->executeInRenderContext(
        $context,
        fn () => $entity->normalizeForClientSide()->renderPreviewIfAny($this->renderer, $this->assetRenderer),
      );
      \assert($representation instanceof ClientSideRepresentation);
      if (!$context->isEmpty()) {
        $leaked_cacheability = $context->pop();
        $representation->addCacheableDependency($leaked_cacheability);
      }
      return $representation;
    };

    $representation = $get_representation($entity);

    // Use core's `renderer.config` container parameter to determine which cache
    // contexts are considered poorly cacheable.
    $problematic_cache_contexts = array_intersect(
      $representation->getCacheContexts(),
      $this->rendererConfig['auto_placeholder_conditions']['contexts']
    );

    // If problematic cache contexts are present or if the markup is empty,
    // attempt to re-render in a way that the Component preview is strongly
    // cacheable while still sufficiently accurate.
    if (!empty($problematic_cache_contexts) || empty($representation->values['default_markup'])) {
      $ignorable_cache_contexts = ['session', 'user'];

      if (array_diff($problematic_cache_contexts, $ignorable_cache_contexts)) {
        throw new \LogicException(\sprintf('No PHP API exists yet to allow specifying a technique to avoid the `%s` cache context(s) while still generating an acceptable preview', implode(',', $problematic_cache_contexts)));
      }

      try {
        $this->accountSwitcher->switchTo(new AnonymousUserSession());
        $representation = $get_representation($entity);
        // Ignore these cache contexts if they still exist, because it's been
        // re-rendered as the anonymous user. If they still exist, they are safe
        // to ignore for preview purposes.
        $representation->removeCacheContexts($ignorable_cache_contexts);
      }
      finally {
        $this->accountSwitcher->switchBack();
      }
    }

    return $representation;
  }

}
