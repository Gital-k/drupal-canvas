<?php

declare(strict_types=1);

namespace Drupal\canvas_workspaces_dev\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides persistent mock workspace endpoints for development.
 *
 * Workspaces and their changes are stored in Drupal's key/value store under
 * the 'canvas_workspaces_dev' collection. The main "live" workspace is always
 * seeded on first access and cannot be deleted. All other workspaces support
 * full create / read / update / delete operations.
 *
 * This controller is intended for development and testing only.
 */
final class WorkspacesMockController extends ControllerBase {

  /**
   * Key/value collection name.
   */
  private const COLLECTION = 'canvas_workspaces_dev';

  /**
   * Key/value key for the workspaces array.
   */
  private const WORKSPACES_KEY = 'workspaces';

  /**
   * Key/value key prefix for per-workspace change lists.
   */
  private const CHANGES_KEY_PREFIX = 'changes:';

  /**
   * The fixed ID of the main/live workspace.
   */
  private const LIVE_ID = 'live';

  public function __construct(
    #[Autowire(service: 'keyvalue')]
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {}

  // ---------------------------------------------------------------------------
  // Workspaces collection.
  // ---------------------------------------------------------------------------

  /**
   * GET /canvas/api/v0/workspaces-dev.
   *
   * Returns all workspaces, seeding defaults on first call.
   */
  public function listWorkspaces(): JsonResponse {
    return new JsonResponse(['data' => $this->loadWorkspaces()]);
  }

  /**
   * POST /canvas/api/v0/workspaces-dev.
   *
   * Creates a new workspace from a JSON body with 'name' and optional
   * 'source_id'. Returns 201 with the new workspace object.
   */
  public function createWorkspace(Request $request): JsonResponse {
    $body = $this::decodeBody($request);
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
      return new JsonResponse(['message' => "'name' is required."], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    $workspace = [
      'id' => 'ws-' . uniqid(),
      'name' => $name,
      'status' => 'draft',
      'isMain' => FALSE,
      'sourceId' => $body['source_id'] ?? self::LIVE_ID,
      'pendingChangesCount' => 0,
    ];

    $workspaces = $this->loadWorkspaces();
    $workspaces[$workspace['id']] = $workspace;
    $this->saveWorkspaces($workspaces);

    return new JsonResponse(['data' => $workspace], Response::HTTP_CREATED);
  }

  /**
   * Updates mutable fields on an existing workspace.
   *
   * Accepted fields: 'name', 'status', 'reviewers', 'scheduledAt'. The live
   * workspace is read-only (returns 403). Returns 404 when the workspace does
   * not exist.
   *
   * @param string $workspace_id
   *   The workspace ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The updated workspace or an error response.
   */
  public function updateWorkspace(string $workspace_id, Request $request): JsonResponse {
    if ($workspace_id === self::LIVE_ID) {
      return new JsonResponse(['message' => 'The live workspace cannot be modified.'], Response::HTTP_FORBIDDEN);
    }

    $workspaces = $this->loadWorkspaces();
    if (!isset($workspaces[$workspace_id])) {
      return new JsonResponse(['message' => 'Workspace not found.'], Response::HTTP_NOT_FOUND);
    }

    $body = $this::decodeBody($request);
    $workspace = $workspaces[$workspace_id];

    $mutable = ['name', 'status', 'reviewers', 'scheduledAt'];
    foreach ($mutable as $field) {
      if (\array_key_exists($field, $body)) {
        $workspace[$field] = $body[$field];
      }
    }

    $workspaces[$workspace_id] = $workspace;
    $this->saveWorkspaces($workspaces);

    return new JsonResponse(['data' => $workspace]);
  }

  /**
   * Deletes a workspace and its associated changes.
   *
   * The live workspace cannot be deleted (returns 403). Returns 404 when the
   * workspace does not exist.
   *
   * @param string $workspace_id
   *   The workspace ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A 204 response on success, or an error response.
   */
  public function deleteWorkspace(string $workspace_id): JsonResponse {
    if ($workspace_id === self::LIVE_ID) {
      return new JsonResponse(['message' => 'The live workspace cannot be deleted.'], Response::HTTP_FORBIDDEN);
    }

    $workspaces = $this->loadWorkspaces();
    if (!isset($workspaces[$workspace_id])) {
      return new JsonResponse(['message' => 'Workspace not found.'], Response::HTTP_NOT_FOUND);
    }

    unset($workspaces[$workspace_id]);
    $this->saveWorkspaces($workspaces);

    // Remove any stored changes for this workspace.
    $this->store()->delete(self::CHANGES_KEY_PREFIX . $workspace_id);

    return new JsonResponse(NULL, Response::HTTP_NO_CONTENT);
  }

  // ---------------------------------------------------------------------------
  // Workspace changes.
  // ---------------------------------------------------------------------------

  /**
   * GET /canvas/api/v0/workspaces-dev/{workspace_id}/changes.
   *
   * Returns pending changes for a workspace. The live workspace always returns
   * an empty list. Returns 404 when the workspace does not exist.
   */
  public function getChanges(string $workspace_id): JsonResponse {
    $workspaces = $this->loadWorkspaces();
    if (!isset($workspaces[$workspace_id])) {
      return new JsonResponse(['message' => 'Workspace not found.'], Response::HTTP_NOT_FOUND);
    }

    if ($workspace_id === self::LIVE_ID) {
      return new JsonResponse(['data' => ['changes' => [], 'total' => 0]]);
    }

    $changes = $this->loadChanges($workspace_id);
    return new JsonResponse(['data' => ['changes' => array_values($changes), 'total' => count($changes)]]);
  }

  // ---------------------------------------------------------------------------
  // Publish action.
  // ---------------------------------------------------------------------------

  /**
   * POST /canvas/api/v0/workspaces-dev/{workspace_id}/publish-action.
   *
   * Performs a publish action on a workspace. Accepted 'action' values:
   * 'publish', 'schedule', 'review'. On 'publish' the workspace status is
   * set to 'published' and its changes are cleared. Returns 404 when the
   * workspace does not exist.
   */
  public function publishAction(string $workspace_id, Request $request): JsonResponse {
    $workspaces = $this->loadWorkspaces();
    if (!isset($workspaces[$workspace_id])) {
      return new JsonResponse(['message' => 'Workspace not found.'], Response::HTTP_NOT_FOUND);
    }

    $body = $this::decodeBody($request);
    $action = $body['action'] ?? 'publish';

    $workspace = $workspaces[$workspace_id];

    switch ($action) {
      case 'publish':
        $workspace['status'] = 'published';
        $workspace['pendingChangesCount'] = 0;
        $workspaces[$workspace_id] = $workspace;
        $this->saveWorkspaces($workspaces);
        $this->store()->delete(self::CHANGES_KEY_PREFIX . $workspace_id);
        $message = 'Workspace published.';
        break;

      case 'schedule':
        $workspace['status'] = 'scheduled';
        if (isset($body['scheduledAt'])) {
          $workspace['scheduledAt'] = $body['scheduledAt'];
        }
        $workspaces[$workspace_id] = $workspace;
        $this->saveWorkspaces($workspaces);
        $message = 'Workspace scheduled for publishing.';
        break;

      case 'review':
        $workspace['status'] = 'in_review';
        if (isset($body['reviewerIds']) && \is_array($body['reviewerIds'])) {
          $workspace['reviewers'] = \array_map(
            static fn(string $id): array => ['id' => $id, 'type' => 'user', 'name' => $id],
            $body['reviewerIds'],
          );
        }
        $workspaces[$workspace_id] = $workspace;
        $this->saveWorkspaces($workspaces);
        $message = 'Workspace sent for review.';
        break;

      default:
        return new JsonResponse(['message' => "Unknown action '$action'."], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    return new JsonResponse(['message' => $message, 'data' => $workspace]);
  }

  // ---------------------------------------------------------------------------
  // Internal helpers.
  // ---------------------------------------------------------------------------

  /**
   * Returns the key/value store for this module.
   *
   * @return \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   *   The key/value store.
   */
  private function store(): KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

  /**
   * Loads all workspaces from the store, seeding defaults on first call.
   *
   * The returned array is keyed by workspace ID.
   *
   * @return array<string, array<string, mixed>>
   *   All workspaces keyed by ID.
   */
  private function loadWorkspaces(): array {
    $workspaces = $this->store()->get(self::WORKSPACES_KEY);
    if (!\is_array($workspaces)) {
      $workspaces = $this->seedWorkspaces();
    }
    return $workspaces;
  }

  /**
   * Persists the workspace map to the store.
   *
   * @param array<string, array<string, mixed>> $workspaces
   *   Workspaces keyed by ID.
   */
  private function saveWorkspaces(array $workspaces): void {
    $this->store()->set(self::WORKSPACES_KEY, $workspaces);
  }

  /**
   * Seeds the initial workspace data into the store and returns it.
   *
   * Called only when the store contains no workspace data yet.
   *
   * @return array<string, array<string, mixed>>
   *   The seeded workspaces keyed by ID.
   */
  private function seedWorkspaces(): array {
    $workspaces = [
      self::LIVE_ID => [
        'id' => self::LIVE_ID,
        'name' => 'Live',
        'status' => 'published',
        'isMain' => TRUE,
        'pendingChangesCount' => 0,
      ],
      'ws-draft-001' => [
        'id' => 'ws-draft-001',
        'name' => 'Marketing Campaign',
        'status' => 'draft',
        'isMain' => FALSE,
        'sourceId' => self::LIVE_ID,
        'pendingChangesCount' => 3,
      ],
      'ws-review-001' => [
        'id' => 'ws-review-001',
        'name' => 'Q4 Updates',
        'status' => 'in_review',
        'isMain' => FALSE,
        'sourceId' => self::LIVE_ID,
        'pendingChangesCount' => 2,
        'reviewers' => [
          ['id' => 'user-001', 'type' => 'user', 'name' => 'Alice Smith'],
          ['id' => 'user-002', 'type' => 'user', 'name' => 'Bob Jones'],
        ],
      ],
    ];

    $this->saveWorkspaces($workspaces);

    // Seed changes for the draft workspace.
    $this->store()->set(self::CHANGES_KEY_PREFIX . 'ws-draft-001', [
      'change-001' => [
        'id' => 'change-001',
        'entityType' => 'node',
        'entityId' => '123',
        'label' => 'Homepage',
        'updated' => \time() - 3600,
        'owner' => ['id' => 'user-001', 'name' => 'Alice Smith'],
      ],
      'change-002' => [
        'id' => 'change-002',
        'entityType' => 'node',
        'entityId' => '456',
        'label' => 'About Us',
        'updated' => \time() - 7200,
        'owner' => ['id' => 'user-002', 'name' => 'Bob Jones'],
      ],
      'change-003' => [
        'id' => 'change-003',
        'entityType' => 'canvas_page',
        'entityId' => '789',
        'label' => 'Landing Page',
        'updated' => \time() - 1800,
        'owner' => ['id' => 'user-001', 'name' => 'Alice Smith'],
      ],
    ]);

    // Seed changes for the in-review workspace.
    $this->store()->set(self::CHANGES_KEY_PREFIX . 'ws-review-001', [
      'change-004' => [
        'id' => 'change-004',
        'entityType' => 'node',
        'entityId' => '101',
        'label' => 'Contact Page',
        'updated' => \time() - 900,
        'owner' => ['id' => 'user-002', 'name' => 'Bob Jones'],
      ],
      'change-005' => [
        'id' => 'change-005',
        'entityType' => 'canvas_page',
        'entityId' => '202',
        'label' => 'Pricing Page',
        'updated' => \time() - 600,
        'owner' => ['id' => 'user-001', 'name' => 'Alice Smith'],
      ],
    ]);

    return $workspaces;
  }

  /**
   * Loads changes for a workspace from the store.
   *
   * Returns an empty array when no changes have been stored.
   *
   * @param string $workspace_id
   *   The workspace ID.
   *
   * @return array<string, array<string, mixed>>
   *   Change objects keyed by change ID.
   */
  private function loadChanges(string $workspace_id): array {
    $changes = $this->store()->get(self::CHANGES_KEY_PREFIX . $workspace_id);
    return \is_array($changes) ? $changes : [];
  }

  /**
   * Decodes a JSON request body into an associative array.
   *
   * Returns an empty array when the body is absent or invalid JSON.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, mixed>
   *   Decoded body or empty array.
   */
  private static function decodeBody(Request $request): array {
    $decoded = json_decode($request->getContent(), TRUE);
    return \is_array($decoded) ? $decoded : [];
  }

}
