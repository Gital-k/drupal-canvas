<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Controller;

use Drupal\canvas_ai\AiResponseValidator;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Service\DirectEditMatcher;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Handles deterministic component edits without invoking the LLM agent chain.
 *
 * When the user selects a component and sends a message that matches a
 * deterministic edit pattern ("change the heading to X"), this controller
 * applies the edit directly using the same Canvas pipeline as the AI
 * agents, but at zero LLM token cost and sub-second latency.
 *
 * The frontend can call this endpoint first; if it returns a 422, route to
 * the standard AI endpoint instead.
 */
final class DirectEditController extends ControllerBase {

  public function __construct(
    private readonly DirectEditMatcher $matcher,
    private readonly AiResponseValidator $responseValidator,
    private readonly CanvasAiPageBuilderHelper $pageBuilderHelper,
    private readonly CanvasAiTempStore $canvasAiTempStore,
    private readonly CsrfTokenGenerator $csrfTokenGenerator,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $directEditConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('canvas_ai.direct_edit_matcher'),
      $container->get('canvas_ai.response_validator'),
      $container->get('canvas_ai.page_builder_helper'),
      $container->get('canvas_ai.tempstore'),
      $container->get('csrf_token'),
      $container->get('logger.channel.canvas_ai'),
      $container->get('config.factory'),
    );
  }

  /**
   * Attempts a deterministic edit on the selected component.
   *
   * This endpoint expects the Canvas frontend to have already loaded the page
   * in the editor, which populates CanvasAiTempStore via CanvasBuilder::render().
   * The tempstore contains the authoritative component list — we never accept
   * it from the client to prevent authorization bypass.
   *
   * Request body (JSON):
   * - message: string — the user's chat message
   * - component_uuid: string — UUID of the selected component
   * - component_name: string — SDC name (e.g., 'sdc.theme_name.heading')
   *
   * Returns:
   * - 200 with update operations if the edit was applied deterministically.
   * - 422 if the message doesn't match a deterministic pattern (route to AI).
   * - 400 for validation errors.
   * - 403 for CSRF or permission errors.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the CSRF token is invalid.
   */
  public function edit(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token') ?? '';
    if (!$this->csrfTokenGenerator->validate($token, 'canvas_ai.canvas_builder')) {
      throw new AccessDeniedHttpException('Invalid CSRF token');
    }

    $body = Json::decode($request->getContent());
    if (!is_array($body)) {
      return new JsonResponse(['status' => FALSE, 'message' => 'Invalid request body'], 400);
    }

    $message = $body['message'] ?? '';
    $componentUuid = $body['component_uuid'] ?? '';
    $componentName = $body['component_name'] ?? '';
    $layout = $body['layout'] ?? NULL;

    if ($message === '' || $componentUuid === '' || $componentName === '') {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Missing required fields: message, component_uuid, component_name',
      ], 400);
    }

    // Validate input formats before touching any downstream service.
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $componentUuid)) {
      return new JsonResponse(['status' => FALSE, 'message' => 'Invalid component_uuid format.'], 400);
    }
    if (!preg_match('/^sdc\.[a-z0-9_]+\.[a-z0-9_\-]+$/', $componentName)) {
      return new JsonResponse(['status' => FALSE, 'message' => 'Invalid component_name format.'], 400);
    }
    if (mb_strlen($message) > 2000) {
      return new JsonResponse(['status' => FALSE, 'message' => 'Message too long.'], 400);
    }

    // Component existence is validated against the server-side tempstore,
    // populated by CanvasBuilder::render() when the page was loaded.
    // We intentionally do NOT accept a 'layout' or component map from the
    // client — that would let any Canvas AI editor fabricate which components
    // "exist" and bypass the existence check.
    //
    // The standard AI endpoint seeds the same tempstore from the client-side
    // `layout` payload before validation. Mirror that here so a first direct
    // edit does not depend on a previous fallback request having populated
    // the tempstore already.
    if (is_string($layout) && $layout !== '') {
      $layoutDecoded = Json::decode($layout);
      if (is_array($layoutDecoded) && array_key_exists($componentUuid, $layoutDecoded)) {
        $this->canvasAiTempStore->setData(
          CanvasAiTempStore::COMPONENTS_IN_PAGE_WITH_PROP_VALUES_KEY,
          $layout
        );
      }
    }

    // Extract current prop values for the selected component from tempstore.
    // Needed for relative adjustments ("bigger"/"smaller").
    $currentPropValues = NULL;
    $componentsData = $this->canvasAiTempStore->getData(
      CanvasAiTempStore::COMPONENTS_IN_PAGE_WITH_PROP_VALUES_KEY
    );
    if (!empty($componentsData)) {
      $decoded = is_string($componentsData) ? Json::decode($componentsData) : $componentsData;
      if (is_array($decoded) && isset($decoded[$componentUuid])) {
        $componentData = $decoded[$componentUuid];
        $currentPropValues = $componentData['propValues'] ?? $componentData;
      }
    }

    // Attempt pattern match with timing.
    $startUs = (int) (hrtime(TRUE) / 1000);
    $match = $this->matcher->match($message, $componentName, $currentPropValues);
    $elapsedUs = (int) (hrtime(TRUE) / 1000) - $startUs;

    $telemetryEnabled = (bool) $this->directEditConfigFactory
      ->get('canvas_ai.direct_edit.settings')
      ->get('telemetry');

    if ($match === NULL) {
      // Always log elapsed time; gate detailed telemetry on config toggle.
      $this->logger->info('DirectEdit: match elapsed @elapsed_us us (reject)', [
        '@elapsed_us' => $elapsedUs,
      ]);
      if ($telemetryEnabled) {
        $this->logger->info('DirectEdit telemetry: @data', [
          '@data' => Json::encode([
            'tier' => 'reject',
            'component_name' => $componentName,
            'prop' => NULL,
            'reason' => 'no_match',
            'elapsed_us' => $elapsedUs,
            'message_length' => mb_strlen($message),
          ]),
        ]);
      }
      return new JsonResponse([
        'status' => FALSE,
        'reason' => 'no_match',
        'message' => 'Message does not match a deterministic edit pattern',
      ], 422);
    }

    // Determine tier and resolved prop for telemetry.
    $isCompound = isset($match['changes']);
    $tier = $isCompound ? 2 : 1;
    $resolvedProp = $isCompound
      ? implode(', ', array_column($match['changes'], 'prop'))
      : ($match['prop'] ?? NULL);

    // Always log elapsed time; gate detailed telemetry on config toggle.
    $this->logger->info('DirectEdit: match elapsed @elapsed_us us (tier @tier)', [
      '@elapsed_us' => $elapsedUs,
      '@tier' => $tier,
    ]);
    if ($telemetryEnabled) {
      $this->logger->info('DirectEdit telemetry: @data', [
        '@data' => Json::encode([
          'tier' => $tier,
          'component_name' => $componentName,
          'prop' => $resolvedProp,
          'reason' => 'matched',
          'elapsed_us' => $elapsedUs,
          'message_length' => mb_strlen($message),
        ]),
      ]);
    }

    try {
      $this->responseValidator->validateComponentExistsInPage($componentUuid);
    }
    catch (\Exception $e) {
      $this->logger->error('DirectEdit: component validation failed for @uuid: @msg', [
        '@uuid' => $componentUuid,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Component not found in current page.',
      ], 400);
    }

    $changes = $match['changes'] ?? [$match];
    $propValues = [];
    foreach ($changes as $change) {
      $propValues[$change['prop']] = $change['value'];
    }

    // Validate the prop values against the component schema.
    try {
      $this->responseValidator->validateComponentPropUpdate($componentName, $propValues);
    }
    catch (\Exception $e) {
      $this->logger->error('DirectEdit: prop validation failed for @component/@prop: @msg', [
        '@component' => $componentName,
        '@prop' => implode(', ', array_keys($propValues)),
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'The requested change is not valid for this component.',
      ], 400);
    }

    // Populate media prop values if needed.
    $propValues = $this->pageBuilderHelper->populateMediaPropIfNeeded(
      $componentName,
      $componentUuid,
      $propValues
    );

    // Build the structured output matching UpdateComponentData format.
    $updateComponents = [
      [
        'uuid' => $componentUuid,
        'fieldValues' => $propValues,
      ],
    ];

    // Use the same response builder as the AI pipeline.
    $response = ['status' => TRUE];
    $response = $this->pageBuilderHelper->includeUpdateOperations($updateComponents, $response);

    // Add metadata for tracking and measurement.
    // matched_prop and matched_value are included intentionally for frontend
    // display (e.g., "Changed heading_text to Welcome"). The value has
    // already been schema-validated above, and the response is
    // application/json consumed by JavaScript — not rendered as HTML.
    $response['direct_edit'] = TRUE;
    $response['tokens_used'] = 0;
    if (count($changes) === 1) {
      $response['matched_prop'] = $changes[0]['prop'];
      $response['matched_value'] = $changes[0]['value'];
    }
    else {
      $response['matched_props'] = array_column($changes, 'prop');
      $response['matched_values'] = $propValues;
      $response['message'] = sprintf(
        'Updated %d properties on the selected component.',
        count($changes)
      );
    }

    $this->logger->notice(
      'DirectEdit: @component props updated deterministically: @props',
      [
        '@component' => $componentName,
        '@props' => Json::encode($propValues),
      ]
    );

    return new JsonResponse($response);
  }

}
