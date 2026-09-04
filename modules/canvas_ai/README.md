## Drupal Canvas AI
Drupal Canvas AI is an experimental module (hidden by default) that integrates a suite of AI-powered agents directly into the Drupal Canvas UI.
It acts as an "assistant" for front-end developers and site builders, allowing you to create/edit components based on a textual prompt or/and an image, create/edit page, create title and metadata.

The UI is powered by [Deepchat](https://deepchat.dev/), providing an intuitive and interactive chat interface for your commands. The backend leverages the powerful and extensible AI Agents framework for Drupal.

## Requirements
  1. [AI Agents](https://www.drupal.org/project/ai_agents) framework (^1.2@beta or higher).
  2. An AI Provider module that supports function calling. You can find the list of providers [here](https://www.drupal.org/project/ai/)
  3. A valid API key for your chosen AI provider, configured within Drupal.
  4. A current-generation chat model. The page builder agent decides whether a request fixes the outcome (act on it) or leaves it to the agent (propose a plan and wait for approval); that judgement, and the approval stop itself, depend on the model following the instruction: GPT-5.2 and GPT-5.6 Luna both do, while older or lightweight models (for example GPT-4o or Gemini 3.5 Flash) may act directly. GPT-5.6 Luna builds pages well at a lower cost per turn than the larger models, which makes it a sensible default. If you need a plan for a specific request, ask for one in the message.

## Installation
1. Setup Canvas according to the [Contribution Guide](../../CONTRIBUTING.md)
2. Install the Drupal Canvas AI module `drush pm:en canvas_ai`.
3. Configure providers at `/admin/config/ai/providers`.
4. Configure core AI settings at `/admin/config/ai/settings`.

## Supported Models

Canvas AI requires AI models with strong function-calling capabilities and sufficient context windows for complex component generation tasks. Below is a categorization of models based on testing and community feedback.

### Fully Supported

These models have been tested and are known to work reliably with Canvas AI:

- **Claude Opus 4.x** (Anthropic) - Tier 2+ access recommended
- **ChatGPT 4.5+** (OpenAI) - Tier 2+ access recommended
- **ChatGPT 5.1+** (OpenAI) - Tier 2+ access recommended

### Likely Supported

These models should work but may have limited testing:

- **Gemini Pro 3** (Google)
- **Mistral Large** (Mistral AI)
- **Claude Sonnet 4.x** (Anthropic)

### Unsupported / Not Recommended

These models are known to have issues with Canvas AI due to limited capabilities, rate limiting, or insufficient function-calling support:

- Free-tier ChatGPT (rate limits, limited function calling)
- Grok 3 (insufficient function calling)
- Claude Sonnet 3.7 (limited context for complex tasks)
- Smaller models (Gemini Flash, Claude Haiku, etc.)

### Why Some Models Are Not Supported

1. **Function Calling**: Canvas AI relies heavily on structured function calling. Models without robust function-calling capabilities will fail to execute agents properly.
2. **Context Window**: Complex component generation and editing requires large context windows to maintain state across operations.
3. **Rate Limits**: Free tiers often have rate limits that interrupt multi-step AI workflows.
4. **Response Quality**: Smaller models may generate syntactically valid but semantically incorrect code.

If you are testing with an unlisted model and it works well, please contribute your findings to the issue queue.

## Contribution
See the [CONTRIBUTING.md](CONTRIBUTING.md) for contribution.
