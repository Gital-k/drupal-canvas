<?php

namespace Drupal\canvas_ai\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai_agents\Enum\AiAgentStatusItemTypes;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusPollerServiceInterface;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\TextGenerated;
use Drupal\canvas_ai\CanvasAiChatHelper;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Plugin\AiFunctionCall\BuilderResponseFunctionCallInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FormUploadedFile;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Renders the Drupal Canvas AI calls.
 */
final class CanvasBuilder extends ControllerBase {

  /**
   * Constructs a new CanvasBuilder object.
   */
  public function __construct(
    protected AiProviderPluginManager $providerService,
    protected PluginManagerInterface $agentManager,
    protected CsrfTokenGenerator $csrfTokenGenerator,
    protected CanvasAiPageBuilderHelper $canvasAiPageBuilderHelper,
    protected CanvasAiTempStore $canvasAiTempStore,
    protected FileUploadHandlerInterface $fileUploadHandler,
    protected AiAgentStatusPollerServiceInterface $poller,
    protected CanvasAiChatHelper $canvasAiChatHelper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('plugin.manager.ai_agents'),
      $container->get('csrf_token'),
      $container->get('canvas_ai.page_builder_helper'),
      $container->get('canvas_ai.tempstore'),
      $container->get('file.upload_handler'),
      $container->get('ai_agents.agent_status_poller'),
      $container->get('canvas_ai.chat_helper'),
    );
  }

  /**
   * Renders the Drupal Canvas AI calls.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  public function render(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token') ?? '';
    if (!$this->csrfTokenGenerator->validate($token, 'canvas_ai.canvas_builder')) {
      throw new AccessDeniedHttpException('Invalid CSRF token');
    }

    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent */
    $agent = $this->agentManager->createInstance('canvas_ai_orchestrator');
    $contentType = $request->getContentTypeFormat();
    $files = [];
    if ($contentType === 'json') {
      $prompt = Json::decode($request->getContent());
    }
    else {
      $prompt = $request->request->all();
      $files = $request->files->all();
      $prompt['derived_proptypes'] = Json::decode($prompt['derived_proptypes']);
      $prompt['selected_component_required_props'] = Json::decode($prompt['selected_component_required_props']);
      $prompt['custom_libraries'] = Json::decode($prompt['custom_libraries']);
    }
    // If $prompt['messages'] is missing or invalid, this code reconstructs it
    // by scanning for keys named 'message <number>', and
    // assembling them into an ordered 'messages' array, while cleaning up old keys
    // as we use $prompt['messages'] for further processing .
    if (!isset($prompt['messages']) || !\is_array($prompt['messages'])) {
      $messages = [];
      $keys_to_remove = [];
      foreach ($prompt as $key => $value) {
        if (preg_match('/^message(\d+)$/', $key, $matches)) {
          $num = (int) $matches[1];
          $decoded = Json::decode($value);
          if ($decoded !== NULL) {
            $messages[$num] = $decoded;
            $keys_to_remove[] = $key;
          }
        }
      }
      if (!empty($messages)) {
        ksort($messages);
        $prompt['messages'] = array_values($messages);
        foreach ($keys_to_remove as $key) {
          unset($prompt[$key]);
        }
      }
    }
    $image_files = [];
    foreach ($files as $file) {
      if (!$file instanceof UploadedFile) {
        continue;
      }
      // Validate and store the upload through the core file upload pipeline.
      // Once validated, we discard it, but we want to centralize validation.
      $upload_result = $this->fileUploadHandler->handleFileUpload(
        new FormUploadedFile($file),
        validators: [
          'FileNameLength' => [],
          'FileExtension' => ['extensions' => 'png jpg jpeg'],
          'FileSizeLimit' => ['fileLimit' => Environment::getUploadMaxSize()],
          'FileIsImage' => [],
        ],
        destination: 'temporary://',
        fileExists: FileExists::Rename,
      );
      if ($upload_result->hasViolations()) {
        return new JsonResponse([
          'status' => FALSE,
          'message' => 'Only image files are allowed (jpeg, png, jpg).',
        ]);
      }
      $uploaded_file = $upload_result->getFile();
      $mime_type = (string) $uploaded_file->getMimeType();
      $filename = (string) $uploaded_file->getFilename();
      $binary = file_get_contents((string) $uploaded_file->getFileUri());

      // Delete the managed file immediately, we only care about the contents.
      $uploaded_file->delete();

      if ($binary === FALSE) {
        return new JsonResponse([
          'status' => FALSE,
          'message' => 'An error occurred reading the uploaded file.',
        ]);
      }

      $image_files[] = new ImageFile($binary, $mime_type, $filename);
    }

    if (empty($prompt['messages'])) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'No prompt provided',
      ]);
    }

    // Check if the agent is mid-turn (resuming a paused hop) or starting fresh.
    // @see \Drupal\ai_assistant_api\Service\AgentRunner::runAsAgent()
    // @see \Drupal\canvas_ai\CanvasAiTempStore::getThreadState()
    // @see \Drupal\canvas_ai\CanvasAiTempStore::setThreadState()
    $job_id = (string) ($prompt['request_id'] ?? '');
    $thread_state = $job_id !== '' ? $this->canvasAiTempStore->getThreadState($job_id) : NULL;

    // Store the current layout in the temp store. This will be later used by
    // the ai agents.
    // @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetCurrentLayout.
    $current_layout = $prompt['current_layout'] ?? '';
    if (!empty($current_layout)) {
      $this->canvasAiTempStore->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, Json::encode($current_layout));
    }

    $default = $this->providerService->getDefaultProviderForOperationType('chat');
    if (!\is_array($default) || empty($default['provider_id']) || empty($default['model_id'])) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'No default provider found.',
      ]);
    }
    $config = $this->config('canvas_ai.settings');
    $http_client_options = [
      'timeout' => $config->get('http_client_options.timeout') ?? 60,
    ];
    $provider = $this->providerService->createInstance(
      $default['provider_id'],
      ['http_client_options' => $http_client_options]
    );

    if ($thread_state !== NULL) {
      // Resume a paused orchestrator: restore its serialized state and let it
      // execute the next tool decision. The chat input and history are restored
      // by ::fromArray(), so they must not be set again here.
      $agent->fromArray($thread_state);
    }
    else {
      // Fresh turn: seed the orchestrator with the user message and history.
      $task_message = array_pop($prompt['messages']);

      // Generate verbose context for orchestrator.
      $context = $this->canvasAiPageBuilderHelper->generateVerboseContextForOrchestrator($prompt);

      // Append the necessary context to the user message.
      $message_xml = $this->canvasAiPageBuilderHelper->formatMessageWithContext($context, $task_message['text']);

      $agent->setChatInput(new ChatInput([
        new ChatMessage($task_message['role'], $message_xml, $image_files),
      ]));

      $chat_history = $this->canvasAiChatHelper->getFilteredChatHistory($prompt['messages']);
      $agent->setChatHistory($chat_history);
    }

    // Per-hop configuration. The token contexts, provider and progress tracking
    // are not part of the serialized agent state, so they are (re)applied on
    // every hop. setLooped(FALSE) makes the orchestrator stop after a single
    // tool decision, so each request returns quickly.
    $agent->setProgressThreadId($job_id);
    $agent->setDetailedProgressTracking([
      AiAgentStatusItemTypes::Started,
      AiAgentStatusItemTypes::TextGenerated,
      AiAgentStatusItemTypes::Finished,
    ]);
    $agent->setAiProvider($provider);
    $agent->setModelName($default['model_id']);
    $agent->setAiConfiguration([]);
    $agent->setCreateDirectly(TRUE);
    $selected_component = $prompt['selected_component'] ?? NULL;
    $selected_component_required_props = isset($prompt['selected_component_required_props']) ? Json::encode($prompt['selected_component_required_props']) : NULL;
    $component_agent_dynamic_state = $this->canvasAiPageBuilderHelper->generateComponentAgentDynamicPromptSection([
      'selected_component' => $selected_component,
      'selected_component_required_props' => $selected_component_required_props,
      'json_api_module_status' => $this->moduleHandler()->moduleExists('jsonapi') ? 'enabled' : 'disabled',
      'menu_fetch_source' => $this->getMenuFetchSource(),
    ]);
    $agent->setTokenContexts([
      'entity_type' => $prompt['entity_type'] ?? NULL,
      'entity_id' => $prompt['entity_id'] ?? NULL,
      'selected_component' => $selected_component,
      'layout' => $prompt['layout'] ?? NULL,
      'derived_proptypes' => isset($prompt['derived_proptypes']) ? Json::encode($prompt['derived_proptypes']) : NULL,
      'page_title' => $prompt['page_title'] ?? NULL,
      'page_description' => $prompt['page_description'] ?? NULL,
      'active_component_uuid' => $prompt['active_component_uuid'] ?? 'None',
      'component_agent_dynamic_state' => $component_agent_dynamic_state,
      'available_regions' => Json::encode($this->canvasAiPageBuilderHelper->getAvailableRegions(Json::encode($prompt['current_layout']))) ?? NULL,
      // JSON-encode so the libraries render as readable data in the system
      // prompt token rather than the string "Array".
      'custom_libraries' => Json::encode($this->getSupportedLibraries()),
    ]);
    $agent->setLooped(FALSE);

    try {
      $solvability = $agent->determineSolvability();
    }
    catch (\Exception $e) {
      // Drop any half-serialized state so the next turn starts clean.
      if ($job_id !== '') {
        $this->canvasAiTempStore->deleteThreadState($job_id);
      }
      return new JsonResponse([
        'status' => FALSE,
        'message' => $e->getMessage(),
      ]);
    }

    // Persist the orchestrator state while it still has work to do, otherwise
    // clear it. should_continue tells the frontend whether to poll again to
    // resume the next tool decision.
    $should_continue = !$agent->isFinished();
    if ($job_id !== '') {
      if ($should_continue) {
        $this->canvasAiTempStore->setThreadState($job_id, $agent->toArray());
      }
      else {
        $this->canvasAiTempStore->deleteThreadState($job_id);
      }
    }

    $status = FALSE;
    $message = '';
    $response = [];
    if ($solvability == AiAgentInterface::JOB_NOT_SOLVABLE) {
      $message = 'Something went wrong';
    }
    elseif ($solvability == AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION) {
      $message = $agent->answerQuestion();
    }
    elseif ($solvability == AiAgentInterface::JOB_INFORMS) {
      $message = $agent->inform();
      $status = TRUE;
    }
    elseif ($solvability == AiAgentInterface::JOB_SOLVABLE) {
      $response['status'] = TRUE;
      $tools = $agent->getToolResults(TRUE);
      if (!empty($tools)) {
        foreach ($tools as $tool) {
          if ($tool instanceof BuilderResponseFunctionCallInterface) {
            $response = array_merge($response, $tool->getStructuredOutput());
          }
          if (\in_array($tool->getPluginId(), [
            'ai_agents::ai_agent::canvas_page_builder_agent',
            'ai_agents::ai_agent::canvas_template_builder_agent',
          ], TRUE)) {
            $this->canvasAiTempStore->deleteData(CanvasAiTempStore::CURRENT_LAYOUT_KEY);
          }
        }
      }
      $response['should_continue'] = $should_continue;
      // Only the final hop carries a message: the orchestrator's answer.
      if (!$should_continue) {
        $response['message'] = $agent->solve();
      }
      // Every hop carries the cumulative agent narration so the frontend can
      // render live progress in a single message without a separate poll.
      $response['progress'] = $this->getProgressItems($job_id, $response['message'] ?? NULL);
      $response = $this->canvasAiPageBuilderHelper->processCanvasPageFields($response);
      return new JsonResponse(
        $response,
      );
    }
    return new JsonResponse([
      'status' => $status,
      'message' => $message,
      'should_continue' => $should_continue,
    ]);
  }

  /**
   * Function to get the x-csrf-token.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function getCsrfToken(Request $request): Response {
    return new Response($this->csrfTokenGenerator->get('canvas_ai.canvas_builder'));
  }

  /**
   * Function to get the source for menu fetching.
   *
   * @return string
   *   The menu fetch source.
   */
  private function getMenuFetchSource(): string {
    if ($this->moduleHandler()->moduleExists('jsonapi_menu_items')) {
      $menuFetchSource = 'jsonapi_menu_items';
    }
    elseif ($this->config('system.feature_flags')->get('linkset_endpoint') === TRUE) {
      $menuFetchSource = 'linkset';
    }
    elseif ($this->currentUser()->hasPermission('administer site configuration')) {
      $menuFetchSource = 'linkset_not_configured';
    }
    else {
      $menuFetchSource = 'menu_fetching_functionality_not_available';
    }
    return $menuFetchSource;
  }

  /**
   * Returns per-agent progress items for the current turn.
   *
   * Collects the text each agent generates while using tools, so the frontend
   * can show the user what the agent is doing. Included in every hop response
   * so no separate poll endpoint is needed.
   *
   * @param string $job_id
   *   The request_id shared across all hops of a single turn.
   * @param string|null $final_message
   *   The orchestrator's final answer, when on the last hop. The poller tracks
   *   all text the agent generates, including the final answer, so it is
   *   stripped here to avoid showing it twice.
   *
   * @return array
   *   Progress items, each with: id, type, name, description, status,
   *   generated_text, agent_runner_id.
   */
  private function getProgressItems(string $job_id, ?string $final_message = NULL): array {
    $progress = $this->poller->getLatestStatusUpdates($job_id);
    $items = [];
    $agent_runner_to_agent_id = [];
    $is_finished = FALSE;

    foreach ($progress->getItems() as $event) {
      /** @var \Drupal\ai_agents\Service\AgentStatus\Interfaces\UpdateItems\StatusBaseInterface $event */
      $event_type = $event->getType();
      $agent_id = $event->getAgentId();

      if ($event_type == AiAgentStatusItemTypes::Started) {
        $agent_runner_id = $event->getAgentRunnerId();
        $agent_runner_to_agent_id[$agent_runner_id] = $agent_id;
        $items[$agent_id] = [
          'id' => $agent_id,
          'type' => 'agent',
          'name' => $event->getAgentName(),
          'description' => $this->getAgentDescription($agent_id, $event->getAgentName() ?? 'Agent'),
          'status' => 'running',
          'generated_text' => '',
          'agent_runner_id' => $agent_runner_id,
        ];
      }
      elseif ($event_type == AiAgentStatusItemTypes::Finished) {
        if (isset($items[$agent_id])) {
          $items[$agent_id]['status'] = 'completed';
          if ($agent_id == 'canvas_ai_orchestrator') {
            $is_finished = TRUE;
            break;
          }
        }
      }
      elseif ($event_type == AiAgentStatusItemTypes::TextGenerated) {
        if ($event instanceof TextGenerated) {
          $generated_text = $event->getGeneratedText();
          if (!empty($generated_text)) {
            $agent_runner_id = $event->getAgentRunnerId();
            if (isset($agent_runner_to_agent_id[$agent_runner_id])) {
              $target_agent_id = $agent_runner_to_agent_id[$agent_runner_id];
              if (isset($items[$target_agent_id])) {
                $items[$target_agent_id]['generated_text'] = !empty($items[$target_agent_id]['generated_text'])
                  ? $items[$target_agent_id]['generated_text'] . "\n\n" . $generated_text
                  : $generated_text;
              }
            }
          }
        }
      }
    }

    if ($is_finished) {
      foreach ($items as $key => $item) {
        if ($item['status'] !== 'completed') {
          $items[$key]['status'] = 'completed';
        }
      }
    }

    // The orchestrator's narration accumulates its final answer as the trailing
    // chunk. Drop it here so the answer (returned separately as `message`) is
    // not rendered twice.
    if ($final_message !== NULL && isset($items['canvas_ai_orchestrator'])) {
      $text = $items['canvas_ai_orchestrator']['generated_text'];
      $pos = strrpos($text, $final_message);
      if ($pos !== FALSE) {
        $items['canvas_ai_orchestrator']['generated_text'] = rtrim(substr($text, 0, $pos));
      }
    }

    return array_values($items);
  }

  /**
   * Function to return the agent description.
   *
   * @param string $agent_id
   *   The agent ID.
   * @param string $agent_name
   *   The agent name.
   *
   * @return string
   *   The agent description.
   */
  private function getAgentDescription(string $agent_id, string $agent_name): string {
    $descriptions = [
      'canvas_ai_orchestrator' => $this->t('Thinking'),
      'canvas_title_generation_agent' => $this->t('Generate a title'),
      'canvas_component_agent' => $this->t('Generate a component'),
      'canvas_metadata_generation_agent' => $this->t('Generate metadata'),
      'canvas_page_builder_agent' => $this->t('Finding components to place'),
      'canvas_template_builder_agent' => $this->t('Designing the page'),
    ];
    return $descriptions[$agent_id] ?? $this->t('@agentName working', ['@agentName' => $agent_name]);
  }

  /**
   * Gets the libraries supported by Canvas.
   *
   * @return array
   *   The array of supported libraries.
   */
  protected function getSupportedLibraries(): array {
    return [
      [
        "name" => "formatted_text",
        "type" => "Built-in custom package",
        "description" => "A built-in component to render text with trusted HTML using [`dangerouslySetInnerHTML`](https://react.dev/reference/react-dom/components/common#dangerously-setting-the-inner-html). The content is safe when processed through Drupal's filter system that is [correctly configured](https://www.drupal.org/docs/administering-a-drupal-site/security-in-drupal/configuring-text-formats-aka-input-formats-for-security).",
        "code" => "```jsx\nimport { FormattedText } from 'drupal-canvas';\n\nexport default function Example() {\n  return (\n    <FormattedText>\n      <em>Hello, world!</em>\n    </FormattedText>\n  );\n}\n```",
      ],
      [
        "name" => "cn",
        "type" => "Built-in custom package",
        "description" => "Utility for combining Tailwind CSS classes.",
        "code" => "```jsx\nimport { cn } from 'drupal-canvas';\n\nexport default function Example() {\n  return <ControlDots className=\"top-4 left-4 stroke-white absolute\" />;\n}\n\nconst ControlDots = ({ className }) => (\n  <svg\n    xmlns=\"http://www.w3.org/2000/svg\"\n    viewBox=\"0 0 31 9\"\n    fill=\"none\"\n    strokeWidth=\"2\"\n    className={cn('w-12', className)}\n  >\n    <ellipse cx=\"4.13\" cy=\"4.97\" rx=\"3.13\" ry=\"2.97\" />\n    <ellipse cx=\"15.16\" cy=\"4.97\" rx=\"3.13\" ry=\"2.97\" />\n    <ellipse cx=\"26.19\" cy=\"4.97\" rx=\"3.13\" ry=\"2.97\" />\n  </svg>\n);\n```",
      ],
      [
        "name" => "tailwind",
        "type" => "Bundled npm package",
        "description" => "Tailwind 4 is available to all components by default. The global CSS is added to all pages with the `@import \"tailwindcss\"` directive included. You can use the [`@theme` directive to customize theme variables](https://tailwindcss.com/docs/theme). For example, you can add a new color to your project by defining a theme variable like `--color-drupal-blue`: Now you can use utility classes like `bg-drupal-blue`, `text-drupal-blue`, or `fill-drupal-blue` in your component markup:",
        "code" => "```css\n@theme {\n  --color-drupal-blue: #009cde;\n}\n``` \n```jsx\nexport default function Example() {\nreturn <div className=\"bg-drupal-blue\">Drupal Blue</div>;\n}\n```",
      ],
      [
        "name" => "clsx",
        "type" => "Bundled npm package",
        "description" => "A tiny utility for constructing `className` strings conditionally. Also serves as a faster & smaller drop-in replacement for the `classnames` module.",
        "code" => "```jsx\nimport { clsx } from 'clsx'\n\nexport default function Example() {\n  return (\n    <div className={clsx('foo', true && 'bar', 'baz');} />\n    // => 'foo bar baz'\n  );\n};\n```",
      ],
      [
        "name" => "class_variance_authority",
        "type" => "Bundled npm package",
        "description" => "CVA helps you define components with multiple visual variants (like size, color, state) in a clean, type-safe way. Instead of manually concatenating CSS classes or writing complex conditional logic, you define variants upfront and let CVA handle the class composition.",
        "code" => "```js\nimport { cva } from 'class-variance-authority';\n\nconst button = cva(\n  'font-semibold border rounded', // base classes\n  {\n    variants: {\n      intent: {\n        primary: 'bg-blue-500 text-white border-blue-500',\n        secondary: 'bg-gray-200 text-gray-900 border-gray-200',\n      },\n      size: {\n        small: 'text-sm py-1 px-2',\n        medium: 'text-base py-2 px-4',\n      },\n    },\n    defaultVariants: {\n      intent: 'primary',\n      size: 'medium',\n    },\n  },\n);\n\n// Usage\nbutton({ intent: 'secondary', size: 'small' });\n// Returns: \"font-semibold border rounded bg-gray-200 text-gray-900 border-gray-200 text-sm py-1 px-2\"\n```",
      ],
      [
        "name" => "json_api_client",
        "type" => "Bundled npm package",
        "description" => "A JSON:API client for fetching Drupal content from code components. Use it with drupal-jsonapi-params to build query strings and swr to load and cache remote data.",
        "code" => "```js\nimport { JsonApiClient } from '@drupal-api-client/json-api-client';\nimport { DrupalJsonApiParams } from 'drupal-jsonapi-params';\nimport useSWR from 'swr';\n```",
      ],
      [
        "name" => "drupal_jsonapi_params",
        "type" => "Bundled npm package",
        "description" => "A helper package for generating JSON:API query strings, including includes, filters, fields, sorts, and pagination.",
        "code" => "```js\nimport { DrupalJsonApiParams } from 'drupal-jsonapi-params';\n\nconst params = new DrupalJsonApiParams()\n  .addInclude(['field_media_image'])\n  .addFields('node--article', ['title', 'path', 'field_media_image']);\n```",
      ],
      [
        "name" => "swr",
        "type" => "Bundled npm package",
        "description" => "A React data fetching hook for loading, caching, and revalidating content in code components.",
        "code" => "```js\nimport useSWR from 'swr';\n\nconst { data, error, isLoading } = useSWR('/jsonapi/node/article', fetcher);\n```",
      ],
      [
        "name" => "tailwind_merge",
        "type" => "Bundled npm package",
        "description" => "A utility function to efficiently merge Tailwind CSS classes in JS without style conflicts.",
        "code" => "```js\nimport { twMerge } from 'tailwind-merge';\n\ntwMerge('px-2 py-1 bg-red hover:bg-dark-red', 'p-3 bg-[#B91C1C]');\n// → 'hover:bg-dark-red p-3 bg-[#B91C1C]'\n```",
      ],
      [
        "name" => 'tailwindcss_typography',
        "type" => "Bundled npm package",
        "description" => "A Tailwind CSS plugin that provides a set of pre-configured typography classes for consistent and readable text styles.",
        "code" => "```js\n<FormattedText className=\"prose md:prose-lg lg:prose-xl\">\n  {body}\n</FormattedText>\n```",
      ],
    ];
  }

}
